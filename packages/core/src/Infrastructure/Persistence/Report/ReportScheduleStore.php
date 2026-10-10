<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The one writer and reader of `report_schedules`.
 *
 * Row mapping, JSON encoding and datetime formatting happen here and nowhere
 * else, which is the persistence rule the standards page states. The use cases
 * above it hold {@see ReportSchedule} and never an array of columns.
 *
 * **The StoredShape envelope is applied here and stripped here.** The column
 * holds `{"_v": 1, ...}` and the domain object holds the body alone, exactly as
 * every other provider-shaped payload in this product does, and the version is
 * mirrored into `definition_version` so a shape migration is a `WHERE` rather
 * than a walk of every row's JSON.
 */
final class ReportScheduleStore
{
    public const TABLE = 'report_schedules';

    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function save(ReportSchedule $schedule): ReportSchedule
    {
        $now = $this->clock->now()->format(self::DATETIME);
        $row = [
            'title' => $schedule->title,
            'report_key' => $schedule->reportKey,
            'format_key' => $schedule->formatKey,
            'delivery_key' => $schedule->deliveryKey,
            'definition' => $this->encode($schedule->definition, $schedule->definitionVersion),
            'definition_version' => $schedule->definitionVersion,
            'audience_key' => $schedule->audienceKey,
            'audience_config' => $this->encode($schedule->audienceConfig, $schedule->audienceConfigVersion),
            'audience_config_version' => $schedule->audienceConfigVersion,
            'recurrence' => $schedule->recurrence,
            'recurrence_day' => $schedule->recurrenceDay,
            'run_at_minute' => $schedule->runAtMinute,
            'timezone' => $schedule->timezone,
            'starts_at' => $schedule->startsAt->format(self::DATETIME),
            'next_run_at' => $schedule->nextRunAt?->format(self::DATETIME),
            'last_run_at' => $schedule->lastRunAt?->format(self::DATETIME),
            'render_as' => $schedule->renderAs,
            'empty_policy' => $schedule->emptyPolicy,
            'subject' => $schedule->subject,
            'body' => $schedule->body,
            'organisation_id' => $schedule->organisationId,
            'state' => $schedule->state,
            'created_by' => $schedule->createdBy,
            'run_count' => $schedule->runCount,
            'last_error' => $schedule->lastError,
            'updated_at' => $now,
        ];

        if ($schedule->id === null) {
            $row['created_at'] = $now;
            $id = $this->database->insert(self::TABLE, $row);
        } else {
            $id = $schedule->id;
            $this->database->update(self::TABLE, $row, ['id' => $id]);
        }

        return $this->find($id) ?? throw new \RuntimeException('The report schedule row vanished after a write.');
    }

    public function find(int $id): ?ReportSchedule
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every enabled schedule whose next run has arrived, oldest first.
     *
     * A schedule with no `next_run_at` is due: it has never run, and the task
     * that claims it computes the first boundary. Leaving it out would mean a
     * schedule saved as enabled never fires until somebody saves it again.
     *
     * @return list<ReportSchedule>
     */
    public function due(\DateTimeImmutable $at, int $limit = 20): array
    {
        $query = Query::from(self::TABLE)
            ->where('state', '=', ReportSchedule::STATE_ENABLED)
            ->orderBy('next_run_at', Query::SORT_ASC)
            ->limit(max(1, $limit));

        $due = [];

        foreach ($this->database->selectAll($query) as $row) {
            $schedule = $this->hydrate($row);

            if ($schedule->nextRunAt === null || $schedule->nextRunAt <= $at) {
                $due[] = $schedule;
            }
        }

        return $due;
    }

    /**
     * @return list<ReportSchedule>
     */
    public function all(): array
    {
        $rows = $this->database->selectAll(Query::from(self::TABLE)->orderBy('id', Query::SORT_ASC));

        return array_map(fn (array $row): ReportSchedule => $this->hydrate($row), $rows);
    }

    /**
     * Removes one schedule and leaves its finished runs alone.
     *
     * `report_runs.schedule_id` keeps pointing at an id that no longer
     * resolves, deliberately: a run is the manifest of an artefact of personal
     * data carrying a retention date, and cascading the delete would leave the
     * artefact with nothing tracking its expiry, which is the exact failure the
     * manifest exists to prevent.
     */
    public function delete(int $id): void
    {
        $this->database->delete(self::TABLE, ['id' => $id]);
    }

    /** Reserve this occurrence before rendering, so a fatal cannot block later schedules. */
    public function claim(ReportSchedule $schedule, \DateTimeImmutable $now): bool
    {
        if ($schedule->id === null) {
            return false;
        }
        return $this->database->update(self::TABLE, [
            'next_run_at' => $schedule->nextDueAfter($now)->format(self::DATETIME),
            'last_error' => 'Report generation started but has not finished. Check the worker if this persists.',
            'updated_at' => $now->format(self::DATETIME),
        ], [
            'id' => $schedule->id,
            'state' => ReportSchedule::STATE_ENABLED,
            'next_run_at' => $schedule->nextRunAt?->format(self::DATETIME),
        ]) === 1;
    }

    /** Records the outcome of one turn, so a failing schedule is visible rather than merely silent. */
    public function recordRun(
        int $id,
        \DateTimeImmutable $ranAt,
        ?\DateTimeImmutable $nextRunAt,
        ?string $error = null,
    ): void {
        $current = $this->find($id);
        $this->database->update(self::TABLE, [
            'last_run_at' => $ranAt->format(self::DATETIME),
            'next_run_at' => $nextRunAt?->format(self::DATETIME),
            'run_count' => ($current === null ? 0 : $current->runCount) + 1,
            'last_error' => $error,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encode(array $body, int $version): ?string
    {
        if ($body === []) {
            return null;
        }

        return json_encode(
            StoredShape::wrap($body, $version),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ReportSchedule
    {
        return new ReportSchedule(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['report_key'],
            (string) $row['format_key'],
            (string) $row['delivery_key'],
            $this->decode($row['definition'] ?? null),
            (int) ($row['definition_version'] ?? 1),
            (string) $row['audience_key'],
            $this->decode($row['audience_config'] ?? null),
            (int) ($row['audience_config_version'] ?? 1),
            (string) $row['recurrence'],
            (int) ($row['recurrence_day'] ?? 1),
            (int) ($row['run_at_minute'] ?? 0),
            (string) ($row['timezone'] ?? 'UTC'),
            $this->moment($row['starts_at'] ?? null) ?? $this->clock->now(),
            $this->moment($row['next_run_at'] ?? null),
            $this->moment($row['last_run_at'] ?? null),
            (string) ($row['render_as'] ?? ReportSchedule::RENDER_AS_RECIPIENT),
            (string) ($row['empty_policy'] ?? ReportSchedule::EMPTY_SEND),
            $row['subject'] === null ? null : (string) $row['subject'],
            $row['body'] === null ? null : (string) $row['body'],
            isset($row['organisation_id']) ? (int) $row['organisation_id'] : null,
            (string) ($row['state'] ?? ReportSchedule::STATE_DISABLED),
            (int) ($row['created_by'] ?? 0),
            (int) ($row['run_count'] ?? 0),
            isset($row['last_error']) ? (string) $row['last_error'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $stored): array
    {
        if (!\is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        return \is_array($decoded) ? StoredShape::bodyOf($decoded) : [];
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
