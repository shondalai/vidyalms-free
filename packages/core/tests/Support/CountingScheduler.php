<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/**
 * A host queue that remembers what has not started, as both real ones do.
 *
 * CollectingScheduler beside it deliberately does not declare the counting
 * capability, so the two together cover both sides of the instanceof probe.
 */
final class CountingScheduler implements SchedulerPort, CountsPendingTasks
{
    /** @var list<ScheduledTask> */
    public array $pending = [];

    public function schedule(ScheduledTask $task): void
    {
        if ($task->dedupeKey !== null) {
            $this->cancelByDedupeKey($task->dedupeKey);
        }

        $this->pending[] = $task;
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
        $this->pending = array_values(array_filter(
            $this->pending,
            static fn (ScheduledTask $task): bool => $task->dedupeKey !== $dedupeKey,
        ));
    }

    /** @return array<string, int> */
    public function pendingCountsByType(): array
    {
        $counts = [];

        foreach ($this->pending as $task) {
            $counts[$task->type] = ($counts[$task->type] ?? 0) + 1;
        }

        return $counts;
    }
}
