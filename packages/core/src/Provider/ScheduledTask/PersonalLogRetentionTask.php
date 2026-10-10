<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Platform\RetentionSettings;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/** Bounded cleanup of expired history; pending deliveries are never removed. */
final class PersonalLogRetentionTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly RetentionSettings $retention,
    ) {}

    public function key(): string { return 'privacy.logs.cleanup'; }
    public function binCount(): int { return 1; }
    public function periodBucket(\DateTimeImmutable $now): string { return $now->format('Y-m-d-H'); }
    public function periodicDedupeKey(string $periodBucket, int $binIndex): string { return $this->key() . ':' . $periodBucket; }

    public function run(ScheduledTaskRun $run): string
    {
        $cutoff = $this->retention->activityLogHorizon($this->clock->now())->format('Y-m-d H:i:s');
        $removed = 0;
        foreach (['activity_log' => 'occurred_at', 'email_log' => 'created_at', 'notification_queue' => 'updated_at'] as $table => $column) {
            $query = Query::from($table)->select(['id'])->where($column, '<', $cutoff)->orderBy('id')->limit(500);
            if ($table === 'notification_queue') {
                $query->whereIn('state', ['sent', 'suppressed', 'failed_permanent', 'cancelled']);
            }
            foreach ($this->database->selectAll($query) as $row) {
                $removed += $this->database->transactional(function () use ($table, $row): int {
                    if ($table === 'notification_queue') {
                        $this->database->delete('org_notification_secrets', ['queue_id' => (int) $row['id']]);
                    }
                    return $this->database->delete($table, ['id' => (int) $row['id']]);
                });
            }
        }
        return (string) $removed;
    }
}
