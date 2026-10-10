<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The one writer and reader of `report_runs`.
 *
 * Row mapping, JSON encoding and datetime formatting happen here and nowhere
 * else, which is the persistence rule the standards page states. The use cases
 * above it hold {@see ReportRun} and never an array of columns.
 */
final class ReportRunStore
{
    public const TABLE = 'report_runs';

    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Opens a run in the `running` state and answers the row it wrote.
     *
     * The row exists before the report is computed rather than after, because
     * the 202 contract hands a caller an id to poll and an id that only appears
     * once the work is finished is an id nobody can poll. It is also what makes
     * a crashed worker visible: a row stuck in `running` is a run that died,
     * where no row at all is indistinguishable from a request that never
     * arrived.
     */
    public function open(ReportRun $run): ReportRun
    {
        $started = $run->startedAt ?? $this->clock->now();
        $id = $this->database->insert(self::TABLE, [
            'report_key' => $run->reportKey,
            'format_key' => $run->formatKey,
            'delivery_key' => $run->deliveryKey,
            'parameters' => $run->parameters === []
                ? null
                : json_encode($run->parameters, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            'parameters_version' => $run->parametersVersion,
            'timezone' => $run->timezone,
            'scope_digest' => $run->scopeDigest,
            'scope_digest_shape' => $run->scopeDigestShape,
            'requested_by' => $run->requestedBy,
            'rendered_as' => $run->renderedAs,
            'organisation_id' => $run->organisationId,
            'schedule_id' => $run->scheduleId,
            'state' => ReportRun::STATE_RUNNING,
            'row_count' => 0,
            'current_line' => 0,
            'truncated' => 0,
            'storage_path' => null,
            'byte_size' => 0,
            'error' => null,
            'started_at' => $started->format(self::DATETIME),
            'finished_at' => null,
            'expires_at' => $run->expiresAt->format(self::DATETIME),
            'created_at' => $this->clock->now()->format(self::DATETIME),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ]);

        return $this->find($id) ?? throw new \RuntimeException('The report run row vanished immediately after insert.');
    }

    /** The run finished and wrote an artefact, or finished and wrote nothing. */
    public function complete(
        int $id,
        int $rowCount,
        bool $truncated,
        ?string $storagePath,
        int $byteSize,
    ): void {
        $this->database->update(self::TABLE, [
            // Zero rows is a success and is stored apart from a failure,
            // because a schedule's empty-report policy has three outcomes and
            // cannot choose between them from a boolean.
            'state' => $rowCount === 0 ? ReportRun::STATE_EMPTY : ReportRun::STATE_DONE,
            'row_count' => $rowCount,
            'current_line' => $rowCount,
            'truncated' => $truncated ? 1 : 0,
            'storage_path' => $storagePath,
            'byte_size' => $byteSize,
            'finished_at' => $this->clock->now()->format(self::DATETIME),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    /**
     * How far the run has got, written while it is still running.
     *
     * It is a bare update rather than a compare-and-swap because a progress
     * marker that lost a race would only be briefly stale, and a run has
     * exactly one writer anyway. The caller decides how often to call it: every
     * row would make the progress marker the expensive part of the export.
     */
    public function progress(int $id, int $currentLine): void
    {
        $this->database->update(self::TABLE, [
            'current_line' => max(0, $currentLine),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    /**
     * What the delivery provider answered, recorded beside the run rather than
     * discarded.
     *
     * The pipeline called deliverThrough() as a statement and threw its
     * DeliveryResult away, so a delivery to a provider nobody registered ended
     * with the run in state "done", `error` null and no log line anywhere,
     * while both the standards page and the design table publish that the
     * schedule records a delivery failure the admin can see.
     */
    public function recordDelivery(int $id, string $state, ?string $error = null): void
    {
        $this->database->update(self::TABLE, [
            'delivery_state' => $state,
            'delivery_error' => $error === null ? null : mb_substr($error, 0, 2000),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    public function fail(int $id, string $error): void
    {
        $this->database->update(self::TABLE, [
            'state' => ReportRun::STATE_FAILED,
            'error' => mb_substr($error, 0, 2000),
            'finished_at' => $this->clock->now()->format(self::DATETIME),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    /** The sweep took the artefact; the manifest survives as the evidence it was taken. */
    public function markExpired(int $id): void
    {
        $this->database->update(self::TABLE, [
            'state' => ReportRun::STATE_EXPIRED,
            'storage_path' => null,
            'byte_size' => 0,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    public function find(int $id): ?ReportRun
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Runs whose artefact is past its retention date and has not been taken.
     *
     * @return list<ReportRun>
     */
    public function dueForSweep(int $batch = 100): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('expires_at', '<', $this->clock->now()->format(self::DATETIME))
                ->whereIn('state', [ReportRun::STATE_DONE, ReportRun::STATE_EMPTY, ReportRun::STATE_FAILED])
                ->orderBy('expires_at')
                ->limit(max(1, $batch)),
        );

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * How many rows hold a null `expires_at`.
     *
     * The column is non-null in the schema, so this is expected to be zero
     * forever. The sweep asserts it anyway, because the whole reason the column
     * is non-null is that a null one is skipped silently for the life of the
     * site, and an invariant nothing checks is a comment.
     */
    public function withoutExpiry(): int
    {
        return $this->database->count(Query::from(self::TABLE)->whereNull('expires_at'));
    }

    /**
     * Every run a person requested, newest first, for the privacy export.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function forSubject(int $userId, int $page, int $perPage): array
    {
        $query = Query::from(self::TABLE)->where('requested_by', '=', $userId);
        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll(
            $query->orderBy('id', Query::SORT_DESC)
                ->limit(max(1, $perPage))
                ->offset((max(1, $page) - 1) * max(1, $perPage)),
        );

        return ['items' => array_values($rows), 'total' => $total];
    }

    /** @return list<int> */
    public function idsForSubject(int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->select(['id'])->where('requested_by', '=', $userId)->orderBy('id'),
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function delete(int $id): void
    {
        $this->database->delete(self::TABLE, ['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): ReportRun
    {
        $parameters = $row['parameters'] ?? null;

        if (\is_string($parameters) && $parameters !== '') {
            $decoded = json_decode($parameters, true);
            $parameters = \is_array($decoded) ? $decoded : [];
        }

        return new ReportRun(
            (int) $row['id'],
            (string) $row['report_key'],
            (string) $row['format_key'],
            self::optionalString($row['delivery_key'] ?? null),
            \is_array($parameters) ? $parameters : [],
            (int) ($row['parameters_version'] ?? 1),
            (string) ($row['timezone'] ?? 'UTC'),
            (string) ($row['scope_digest'] ?? ''),
            (int) ($row['requested_by'] ?? 0),
            self::optionalInt($row['rendered_as'] ?? null),
            self::optionalInt($row['organisation_id'] ?? null),
            self::optionalInt($row['schedule_id'] ?? null),
            (string) ($row['state'] ?? ReportRun::STATE_QUEUED),
            (int) ($row['row_count'] ?? 0),
            (int) ($row['current_line'] ?? 0),
            (bool) ($row['truncated'] ?? false),
            self::optionalString($row['storage_path'] ?? null),
            (int) ($row['byte_size'] ?? 0),
            self::optionalString($row['error'] ?? null),
            self::instant($row['started_at'] ?? null),
            self::instant($row['finished_at'] ?? null),
            self::instant($row['expires_at'] ?? null) ?? new \DateTimeImmutable('@0'),
            self::optionalString($row['delivery_state'] ?? null),
            self::optionalString($row['delivery_error'] ?? null),
            (string) ($row['scope_digest_shape'] ?? ReportScopeDigest::SHAPE),
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function optionalInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
