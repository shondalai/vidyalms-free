<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\EnqueuePeriodicTasks;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\CollectingScheduler;
use Shondalai\VidyaLMS\Core\Tests\Support\CountingScheduler;
use Shondalai\VidyaLMS\Core\Tests\Support\FailingCountScheduler;
use Shondalai\VidyaLMS\Core\Tests\Support\FrozenClock;
use Shondalai\VidyaLMS\Core\Tests\Support\HourlySweepTask;
use Shondalai\VidyaLMS\Core\Tests\Support\ShardedSweepTask;

/**
 * A queue that is not being drained is not given more work.
 *
 * The dedupe key collapses two turns of the clock inside one period, which is
 * what it was built for and what both hosts need, because both are documented
 * multi-firers. It cannot collapse two periods and must not: two periods are
 * two windows. What nothing saw was the third case, and it is the one both
 * development sites' job tables actually grew by: a site whose queue runs
 * nothing at all (a disabled task plugin on Joomla, a WP-Cron that only fires
 * on a visited page) took another period's rows every turn, one job per missed
 * window per burst, without bound.
 */
final class PeriodicEnqueueBacklogTest extends TestCase
{
    public function testASecondPeriodIsQueuedWhileTheFirstHasBeenDrained(): void
    {
        $clock = new FrozenClock('2026-01-15 10:00:00');
        $scheduler = new CountingScheduler();
        $enqueue = new EnqueuePeriodicTasks(self::registry(new HourlySweepTask()), $scheduler, $clock);

        $first = $enqueue->execute();

        self::assertSame(1, $first['enqueued']);
        self::assertSame([], $first['backlogged']);

        // The queue ran, which is the normal case and the one that must not
        // be held back.
        $scheduler->pending = [];
        $clock->advance('PT1H');

        $second = $enqueue->execute();

        self::assertSame(1, $second['enqueued'], 'a new period after a drained queue is queued');
        self::assertSame([], $second['backlogged']);
    }

    public function testANewPeriodIsNotQueuedBehindAPeriodThatNeverStarted(): void
    {
        $clock = new FrozenClock('2026-01-15 10:00:00');
        $scheduler = new CountingScheduler();
        $enqueue = new EnqueuePeriodicTasks(self::registry(new HourlySweepTask()), $scheduler, $clock);

        $enqueue->execute();

        self::assertSame(1, $scheduler->pendingCountsByType()[HourlySweepTask::TYPE]);

        // Six hours of a broken cron. Before this check each turn added
        // another row, because each period has its own bucket and therefore
        // its own dedupe key: six turns, six rows, none of them run.
        for ($hour = 0; $hour < 6; $hour++) {
            $clock->advance('PT1H');
            $result = $enqueue->execute();

            self::assertSame(0, $result['enqueued']);
            self::assertSame([HourlySweepTask::TYPE], $result['backlogged']);
            self::assertNotContains(HourlySweepTask::TYPE, $result['tasks']);
        }

        self::assertCount(1, $scheduler->pending, 'the queue holds one job, not seven');
    }

    /**
     * A partly drained multi-bin sweep is still queued in full, because the
     * rule is one period's worth of unstarted work and not one job.
     */
    public function testAPartlyDrainedShardedSweepIsQueuedAgain(): void
    {
        $clock = new FrozenClock('2026-01-15 10:00:00');
        $scheduler = new CountingScheduler();
        $enqueue = new EnqueuePeriodicTasks(self::registry(new ShardedSweepTask()), $scheduler, $clock);

        self::assertSame(4, $enqueue->execute()['enqueued']);

        // Three of the four ran; the fourth is still waiting.
        $scheduler->pending = \array_slice($scheduler->pending, 0, 1);
        $clock->advance('PT1H');

        $result = $enqueue->execute();

        self::assertSame(4, $result['enqueued']);
        self::assertSame([], $result['backlogged']);
    }

    /**
     * An adapter that cannot answer the question keeps exactly the behaviour
     * it had, which is correct and merely noisy. A capability that changed
     * behaviour when absent would be a required method wearing a disguise.
     */
    public function testASchedulerThatCannotCountIsNeverHeldBack(): void
    {
        $clock = new FrozenClock('2026-01-15 10:00:00');
        $scheduler = new CollectingScheduler();
        $enqueue = new EnqueuePeriodicTasks(self::registry(new HourlySweepTask()), $scheduler, $clock);

        $enqueue->execute();
        $clock->advance('PT1H');
        $result = $enqueue->execute();

        self::assertSame(1, $result['enqueued']);
        self::assertSame([], $result['backlogged']);
        self::assertCount(2, $scheduler->tasks);
    }

    /**
     * A queue read that fails must not lose a turn of the site's clock. The
     * sweep is enqueued, which is the direction that keeps the site working.
     */
    public function testASchedulerThatThrowsWhileCountingIsTreatedAsOneThatCannot(): void
    {
        $clock = new FrozenClock('2026-01-15 10:00:00');
        $scheduler = new FailingCountScheduler();
        $enqueue = new EnqueuePeriodicTasks(self::registry(new HourlySweepTask()), $scheduler, $clock);

        $result = $enqueue->execute();

        self::assertSame(1, $result['enqueued']);
        self::assertSame([], $result['backlogged']);
    }

    private static function registry(ScheduledTaskHandlerInterface ...$handlers): ScheduledTaskRegistry
    {
        $registry = new ScheduledTaskRegistry();

        foreach ($handlers as $handler) {
            $registry->register($handler);
        }

        return $registry;
    }
}
