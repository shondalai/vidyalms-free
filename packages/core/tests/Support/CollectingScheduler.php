<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

final class CollectingScheduler implements SchedulerPort
{
    /** @var list<ScheduledTask> */
    public array $tasks = [];

    public function schedule(ScheduledTask $task): void
    {
        if ($task->dedupeKey !== null) {
            $this->cancelByDedupeKey($task->dedupeKey);
        }

        $this->tasks[] = $task;
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
        $this->tasks = array_values(array_filter(
            $this->tasks,
            static fn (ScheduledTask $task): bool => $task->dedupeKey !== $dedupeKey,
        ));
    }
}
