<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationSender;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Drains the queue.
 *
 * It never throws to report a delivery failure. Retry is owned by the queue
 * and by nothing else, so this handler records the outcome on the row, sets
 * the next attempt instant and returns a summary line, leaving the runner
 * nothing to retry.
 */
final class NotificationSendTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.notification.send';

    /** Five minutes, expressed as the bucket width in seconds. */
    public const PERIOD_SECONDS = 300;

    /**
     * One bin. The drain claims each row before it sends, so two runners over
     * one row already produce exactly one send and sharding buys nothing; the
     * limit is what bounds a run.
     */
    public const BINS = 1;

    public function __construct(private readonly NotificationSender $sender)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    /**
     * **Started by the clock**, and it has to be: a queue row is written by
     * the fan-out and nothing else ever queues this handler, so a build where
     * it did not declare this left every pending row pending for the life of
     * the site. It also means a deferred row returns to pending on its own
     * instant, because releaseDue() runs at the head of every drain.
     */
    public function binCount(): int
    {
        return self::BINS;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return (string) intdiv($now->getTimestamp(), self::PERIOD_SECONDS);
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'notification.send:' . $periodBucket . ':' . $binIndex;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $limit = $run->int('limit', NotificationSender::DEFAULT_LIMIT);
        $sent = $this->sender->drain(max(1, $limit));

        return sprintf('Notification drain sent %d message(s).', $sent);
    }
}
