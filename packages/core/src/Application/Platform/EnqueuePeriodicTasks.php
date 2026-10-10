<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRegistry;

/**
 * One turn of the host's recurring clock, expressed as queue rows.
 *
 * A sweep has no event behind it: nothing happens when a drip window opens,
 * when a digest period closes or when a learner fails to log in, so something
 * has to notice. That something is the host's own scheduler, and this is what
 * it calls. Every handler in the registry that declares RunsPeriodically is
 * asked for its bin count, its period bucket and its dedupe key, and one row
 * per bin is queued through SchedulerPort.
 *
 * It lives here and not in either adapter for the reason the whole repository
 * exists: two independently written loops would shard and deduplicate
 * differently, and "anything that passes on WordPress and fails on Joomla is
 * an adapter bug by definition" would stop being checkable. Both runners call
 * this and neither holds a task type, a bin count or a bucket.
 *
 * It is idempotent within a period by construction. SchedulerPort replaces a
 * pending task carrying the same dedupe key, and the provider's bucket is
 * stable across a period, so a host clock that fires twice inside one period
 * leaves one row per bin. Both hosts are documented multi-firers, so that is
 * the normal case rather than the exotic one.
 *
 * A provider that throws while being asked is skipped and named in the result
 * rather than aborting the turn, because one broken third-party sweep must not
 * stop the site's own.
 */
final class EnqueuePeriodicTasks
{
    public function __construct(
        private readonly ScheduledTaskRegistry $tasks,
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array{enqueued: int, tasks: list<string>, skipped: list<string>, backlogged: list<string>}
     */
    public function execute(): array
    {
        $now      = $this->clock->now();
        $enqueued = 0;
        $ran      = [];
        $skipped  = [];
        $backlogged = [];
        $pending  = $this->pendingCounts();

        foreach ($this->tasks->all() as $handler) {
            if (!$handler instanceof RunsPeriodically) {
                continue;
            }

            try {
                $bins   = max(1, $handler->binCount());
                $bucket = $handler->periodBucket($now);

                // The dedupe key collapses two turns inside one period; it
                // cannot collapse two periods, and must not, because they are
                // two windows. What it also cannot see is a queue that is not
                // being drained at all: on a site with a broken cron, a
                // disabled task plugin or a host that only runs WP-Cron on a
                // visited page, every turn added another period's rows to a
                // queue that ran none of them, one job per missed window per
                // burst, without bound. There is no point queueing a fourth
                // period behind three that have not started, and the pending
                // rows do the same work when they finally run.
                if (($pending[$handler->key()] ?? 0) >= $bins) {
                    $backlogged[] = $handler->key();

                    continue;
                }

                for ($bin = 0; $bin < $bins; $bin++) {
                    $this->scheduler->schedule(new ScheduledTask(
                        $handler->key(),
                        ['binIndex' => $bin, 'binCount' => $bins, 'periodBucket' => $bucket],
                        $now,
                        $handler->periodicDedupeKey($bucket, $bin),
                        oncePerKey: true,
                    ));

                    $enqueued++;
                }

                $ran[] = $handler->key();
            } catch (\Throwable) {
                // A third-party sweep that cannot answer must not stop the
                // site's own. It is named so an operator can see which one.
                $skipped[] = $handler->key();
            }
        }

        return ['enqueued' => $enqueued, 'tasks' => $ran, 'skipped' => $skipped, 'backlogged' => $backlogged];
    }

    /**
     * What the scheduler is already holding, when it can say.
     *
     * Probed with instanceof rather than asked of every SchedulerPort,
     * because adding the method to the port would fatal every adapter that
     * has not been rebuilt. An adapter that cannot answer gets an empty map,
     * which means no handler is ever held back and the behaviour is exactly
     * what it was before this check existed: correct, and merely noisy on a
     * site whose queue is not drained.
     *
     * A scheduler that throws while answering is treated as one that cannot
     * answer. A turn of the site's clock must not be lost because a queue
     * read failed.
     *
     * @return array<string, int>
     */
    private function pendingCounts(): array
    {
        if (!$this->scheduler instanceof CountsPendingTasks) {
            return [];
        }

        try {
            return $this->scheduler->pendingCountsByType();
        } catch (\Throwable) {
            return [];
        }
    }
}
