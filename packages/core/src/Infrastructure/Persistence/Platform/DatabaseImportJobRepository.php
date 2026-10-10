<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class DatabaseImportJobRepository implements ImportJobRepository
{
    /**
     * The domain's own list, not a second copy of it. A job that finished
     * with problems is finished, so its source is releasable on the same
     * thirty day window every other finished job's is; a private copy here
     * would have kept those uploads for ever by omission.
     *
     * @var list<string>
     */
    private const TERMINAL_STATUSES = ImportJob::TERMINAL_STATUSES;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function create(
        string $importer,
        ?string $sourcePath,
        array $options,
        int $createdBy,
        ?int $organisationId = null,
        ?int $organisationUnitId = null,
    ): int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->database->insert('import_jobs', [
            'importer' => $importer,
            'status' => ImportJob::STATUS_PENDING,
            'source_path' => $sourcePath,
            'options' => $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR),
            'created_by' => $createdBy,
            // Stamped from the run context, which is an authorised OrgScope or
            // nothing at all. There is no branch here that reads an
            // organisation out of the options map, because the options map is
            // where a caller's own payload arrives.
            'organisation_id' => $organisationId,
            'organisation_unit_id' => $organisationUnitId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markRunning(int $id): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->update('import_jobs', [
            'status' => ImportJob::STATUS_RUNNING,
            'started_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function recordProgress(int $id, string $log, array $totals): void
    {
        $this->database->update('import_jobs', [
            'log' => $log,
            'totals' => $totals === [] ? null : json_encode($totals, JSON_THROW_ON_ERROR),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function touch(int $id): void
    {
        $this->database->update('import_jobs', [
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function markCompleted(int $id, string $log, array $totals): void
    {
        $this->write($id, ImportJob::STATUS_COMPLETED, $log, $totals);
    }

    public function markPreviewed(int $id, string $log, array $totals): void
    {
        $this->write($id, ImportJob::STATUS_PREVIEWED, $log, $totals);
    }

    public function markFailed(int $id, string $log, array $totals): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->update('import_jobs', [
            'status' => ImportJob::STATUS_FAILED,
            'log' => $log,
            // A run that failed before creating anything reports no totals at
            // all, rather than an empty map that reads like a finished count.
            'totals' => $totals === [] ? null : json_encode($totals, JSON_THROW_ON_ERROR),
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function find(int $id): ?ImportJob
    {
        $row = $this->database->selectOne(
            Query::from('import_jobs')->where('id', '=', $id),
        );

        if ($row === null) {
            return null;
        }

        return $this->map($row);
    }

    public function recent(int $page, int $perPage): ImportJobPage
    {
        $query = Query::from('import_jobs');
        $total = $this->database->count($query);

        $query->orderBy('id', Query::SORT_DESC)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        return new ImportJobPage(
            array_map($this->map(...), $this->database->selectAll($query)),
            $total,
            $page,
            $perPage,
        );
    }

    public function releasedSourcesBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        $stamp = $cutoff->format('Y-m-d H:i:s');

        $candidates = $this->database->selectAll(
            Query::from('import_jobs')
                ->select(['source_path'])
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->whereNotNull('source_path')
                ->whereNotNull('finished_at')
                ->where('finished_at', '<', $stamp)
                ->orderBy('id', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        );

        $paths = [];

        foreach ($candidates as $row) {
            $path = $this->stringOrNull($row['source_path'] ?? null);

            if ($path !== null && $path !== '') {
                $paths[$path] = true;
            }
        }

        if ($paths === []) {
            return [];
        }

        // The same upload can be named by several jobs: the screen's retry
        // queues a second run against the first run's source. Deleting the
        // file because the first job went cold would pull it out from under
        // the retry, so every job naming the path is asked before any of them
        // releases it.
        $rows = $this->database->selectAll(
            Query::from('import_jobs')
                ->select(['id', 'source_path', 'status', 'finished_at'])
                ->whereIn('source_path', array_keys($paths)),
        );

        /** @var array<string, list<int>> $released */
        $released = [];

        foreach ($rows as $row) {
            $path = $this->stringOrNull($row['source_path'] ?? null);

            if ($path === null || !isset($paths[$path])) {
                continue;
            }

            $finishedAt = $this->stringOrNull($row['finished_at'] ?? null);

            if (
                !\in_array((string) ($row['status'] ?? ''), self::TERMINAL_STATUSES, true)
                || $finishedAt === null
                || $finishedAt === ''
                || $finishedAt >= $stamp
            ) {
                unset($paths[$path], $released[$path]);

                continue;
            }

            $released[$path][] = (int) ($row['id'] ?? 0);
        }

        return $released;
    }

    public function clearSourcePath(int $id): void
    {
        $this->database->update('import_jobs', [
            'source_path' => null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Guarded on the running status, so a run finishing after an operator
     * ended the job loses the race instead of overwriting their decision.
     * The caller reads the answer and records a late finish instead.
     *
     * @param array<string, mixed> $totals
     */
    public function finishIfRunning(int $id, string $status, string $log, array $totals): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $affected = $this->database->update('import_jobs', [
            'status' => $status,
            'log' => $log,
            // A run that failed before creating anything reports no totals at
            // all, rather than an empty map that reads like a finished count.
            'totals' => $status === ImportJob::STATUS_FAILED && $totals === []
                ? null
                : json_encode($totals, JSON_THROW_ON_ERROR),
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => ImportJob::STATUS_RUNNING]);

        return $affected === 1;
    }

    /**
     * The unguarded write behind the three marks.
     *
     * @param array<string, mixed> $totals
     */
    private function write(int $id, string $status, string $log, array $totals): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->update('import_jobs', [
            'status' => $status,
            'log' => $log,
            'totals' => json_encode($totals, JSON_THROW_ON_ERROR),
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ImportJob
    {
        // The StoredShape envelope is stripped here and nowhere else, so the
        // API contract and every importer still see the body alone.
        $stored = $this->jsonToArray($row['options'] ?? null) ?? [];

        return new ImportJob(
            (int) ($row['id'] ?? 0),
            (string) ($row['importer'] ?? ''),
            (string) ($row['status'] ?? ImportJob::STATUS_PENDING),
            $this->stringOrNull($row['source_path'] ?? null),
            StoredShape::bodyOf($stored),
            $this->stringOrNull($row['log'] ?? null),
            $this->jsonToArray($row['totals'] ?? null),
            $this->dateOrNull($row['started_at'] ?? null),
            $this->dateOrNull($row['finished_at'] ?? null),
            (int) ($row['created_by'] ?? 0),
            $this->dateOrNull($row['created_at'] ?? null),
            // The heartbeat: every write this repository makes moves it, and
            // a running job that stops moving it has stopped responding.
            $this->dateOrNull($row['updated_at'] ?? null),
            ($row['organisation_id'] ?? null) === null ? null : (int) $row['organisation_id'],
            StoredShape::versionOf($stored),
            ($row['organisation_unit_id'] ?? null) === null ? null : (int) $row['organisation_unit_id'],
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonToArray(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
