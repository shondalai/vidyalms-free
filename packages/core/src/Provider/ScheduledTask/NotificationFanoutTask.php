<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationFanout;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Expands intent rows into per-recipient rows.
 *
 * It is binned exactly as the sweeps are, so one run does bounded work and a
 * rule over a large audience never runs its policy evaluations inside the
 * transaction that wrote the intent row.
 *
 * **It is started by the clock**, and it has to be. An intent row is written
 * by a use case and nothing else ever queues this handler, so a build where
 * it did not declare RunsPeriodically left every intent row ever written
 * sitting in pending_fanout for the life of the site: the machinery worked
 * perfectly and nothing started it. The task was registered, the handler was
 * correct, and no mail was ever sent on either host.
 *
 * The period is short because the fan-out is the first of two hops and a
 * transactional mail should not wait a quarter of an hour to reach the queue,
 * let alone the recipient. Both hosts fire the recurring clock opportunistically
 * rather than punctually, so the bucket is what makes a clock that fires twice
 * inside one period enqueue one row per bin rather than two.
 */
final class NotificationFanoutTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.notification.fanout';

    /** Five minutes, expressed as the bucket width in seconds. */
    public const PERIOD_SECONDS = 300;

    /**
     * Four bins. Fewer than the sweeps' twenty-four because an intent row
     * expands one audience rather than enumerating every enrolment, and more
     * than one because a cohort-starting rule over a large roster is exactly
     * the run that must not be a single job.
     */
    public const BINS = 4;

    public function __construct(private readonly NotificationFanout $fanout)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function binCount(): int
    {
        return self::BINS;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return (string) intdiv($now->getTimestamp(), self::PERIOD_SECONDS);
    }

    /**
     * The periodic key is namespaced apart from the per-row wake-up's
     * `fanout:{queueRowId}:{bin}`. Both halves are integers, so an
     * unnamespaced period bucket collides with the intent row of the same
     * number, and on a busy site those numbers meet: the bucket is the epoch
     * over five minutes, which is in the millions today. A collision would
     * make a wake-up replace a pending sweep or the reverse.
     */
    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'fanout.periodic:' . $periodBucket . ':' . $binIndex;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $limit = $run->int('limit', NotificationFanout::DEFAULT_LIMIT);
        $written = $this->fanout->run(
            max(1, $limit),
            max(0, $run->int('binIndex', 0)),
            max(1, $run->int('binCount', 1)),
        );

        return sprintf('Notification fan-out wrote %d row(s).', $written);
    }
}
