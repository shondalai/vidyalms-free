<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/**
 * A scheduler that behaves the way both hosts' queue tables do, rather than
 * the way a list does.
 *
 * CollectingScheduler models "replace any task carrying the same key" and has
 * no notion of a row being claimed, which is exactly why a batched task that
 * re-queued itself under the key of the row being drained looked correct in
 * the core suite and lost every batch after the first on a real host. Here a
 * keyed schedule is an upsert that resets an existing row to pending (what
 * JoomlaScheduler and WpScheduler do through DatabasePort::upsert with status
 * among the reschedule columns), claim() moves one pending row to running, and
 * finish() only stamps a row that is still running, so a row re-queued
 * mid-flight survives the runner that was draining it.
 */
final class QueueScheduler implements SchedulerPort
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    /** @var array<int, array{id: int, task: ScheduledTask, status: string}> */
    private array $rows = [];

    private int $nextId = 1;

    public function schedule(ScheduledTask $task): void
    {
        if ($task->dedupeKey !== null) {
            foreach ($this->rows as $id => $row) {
                if ($row['task']->dedupeKey === $task->dedupeKey) {
                    $this->rows[$id] = ['id' => $id, 'task' => $task, 'status' => self::STATUS_PENDING];

                    return;
                }
            }
        }

        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id, 'task' => $task, 'status' => self::STATUS_PENDING];
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['task']->dedupeKey === $dedupeKey && $row['status'] === self::STATUS_PENDING) {
                unset($this->rows[$id]);
            }
        }
    }

    /**
     * The oldest pending row of a type, moved to running. Null when there is
     * none left to take.
     *
     * @return array{id: int, task: ScheduledTask}|null
     */
    public function claim(?string $type = null): ?array
    {
        foreach ($this->rows as $id => $row) {
            if ($row['status'] !== self::STATUS_PENDING) {
                continue;
            }

            if ($type !== null && $row['task']->type !== $type) {
                continue;
            }

            $this->rows[$id]['status'] = self::STATUS_RUNNING;

            return ['id' => $id, 'task' => $row['task']];
        }

        return null;
    }

    /**
     * What a runner does after dispatching, modelled as the weaker of the two
     * hosts: the row it drained is stamped done whatever its status is now.
     * A task that re-queues itself must therefore not reuse the key of the row
     * being drained, and that is what this fake exists to prove. Both adapters
     * additionally guard the stamp on the row still being running, which is
     * defence in depth rather than the rule.
     */
    public function finish(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = self::STATUS_DONE;
        }
    }

    /**
     * @return list<ScheduledTask>
     */
    public function pending(?string $type = null): array
    {
        $tasks = [];

        foreach ($this->rows as $row) {
            if ($row['status'] !== self::STATUS_PENDING) {
                continue;
            }

            if ($type !== null && $row['task']->type !== $type) {
                continue;
            }

            $tasks[] = $row['task'];
        }

        return $tasks;
    }
}
