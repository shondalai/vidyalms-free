<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/** A host whose queue read fails, which must not cost the site a turn. */
final class FailingCountScheduler implements SchedulerPort, CountsPendingTasks
{
    /** @var list<ScheduledTask> */
    public array $tasks = [];

    public function schedule(ScheduledTask $task): void
    {
        $this->tasks[] = $task;
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
    }

    /** @return array<string, int> */
    public function pendingCountsByType(): array
    {
        throw new \RuntimeException('The queue could not be read.');
    }
}
