<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The one generic task that runs every registered sweep trigger.
 *
 * A new sweep is a registration and needs no new task type and no scheduler
 * registration, which is the whole reason the two trigger kinds sit behind
 * one registry.
 *
 * The payload carries a bin index and a bin count, so one run enumerates a
 * bounded slice rather than every enrolment on a large site, and the dedupe
 * key of the scheduled task has something meaningful to cancel. The period
 * bucket is what makes two runs inside one period produce exactly one queue
 * row: without it the six other digest components are identical across every
 * occurrence and a cron that fires twice sends twice.
 *
 * It declares RunsPeriodically because nothing else can start it. Every other
 * handler in the registry runs because a use case queued it: a certificate is
 * rendered because one was issued, an import runs because somebody pressed
 * Run. A sweep has no such moment, and a sweep nothing starts is the empty
 * seam the eleven-registries rule forbids: the free build's own
 * entitlement-expiring trigger would sit registered and never fire on either
 * host. The three answers below are the provider's, not an adapter's, so the
 * two hosts shard and deduplicate a turn identically.
 */
final class NotificationSweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.notification.sweep';

    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly SqlNotificationRuleRepository $rules,
        private readonly NotificationEnqueue $enqueue,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $binIndex = $run->int('binIndex', 0);
        $binCount = $run->int('binCount', SweepContext::DEFAULT_BIN_COUNT);
        $bucket = $run->string('periodBucket', $this->clock->now()->format('Y-m-d'));
        $written = 0;

        foreach ($this->triggers->sweeps() as $sweep) {
            foreach ($this->rules->enabledForTrigger($sweep->key()) as $rule) {
                $context = SweepContext::create(
                    $this->clock->now(),
                    $this->database,
                    $rule->definition,
                    $binIndex,
                    $binCount,
                    $bucket,
                    $rule->id,
                    $this->siteTimezone,
                );

                foreach ($sweep->dueCandidates($context) as $occurrence) {
                    if ($this->enqueue->forOccurrence($rule, $occurrence) !== null) {
                        ++$written;
                    }
                }
            }
        }

        return sprintf('Notification sweep %d of %d wrote %d intent row(s).', $binIndex, $binCount, $written);
    }

    /**
     * The dedupe key one scheduled sweep run carries, so a rescheduled run
     * replaces the pending one rather than queueing beside it.
     */
    public static function dedupeKeyFor(string $triggerKey, string $periodBucket, int $binIndex): string
    {
        return sprintf('sweep:%s:%s:%d', $triggerKey, $periodBucket, $binIndex);
    }

    public function binCount(): int
    {
        return SweepContext::DEFAULT_BIN_COUNT;
    }

    /**
     * One bucket per hour. A sweep trigger's own occurrence key narrows it
     * further where it needs to; what this decides is only how often the host
     * clock may start a fresh turn, and an hour is short enough that a nudge
     * lands on the day it is due and long enough that a site whose scheduler
     * fires on page traffic does not start a turn a minute.
     */
    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return sprintf('sweep:%s:%s:%d', self::TYPE, $periodBucket, $binIndex);
    }
}
