<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduledTask;

/**
 * A scheduled task that has to be started by the clock rather than by an event.
 *
 * Most handlers run because a use case queued them: a certificate is rendered
 * because one was issued, an import runs because somebody pressed Run. A sweep
 * has no such moment. Nothing happens when a learner fails to log in, when a
 * digest period closes or when a drip window opens, so something has to notice,
 * and that something is the host's own recurring clock: Joomla's Scheduled
 * Tasks, WordPress's Action Scheduler or WP-Cron.
 *
 * This is a capability probed with instanceof rather than a method on
 * ScheduledTaskHandlerInterface, because that interface is shipped and
 * 02-product-editions.md invites third parties to implement it. **When absent,
 * a handler is never started by the clock**, which is today's behaviour for
 * every handler that exists.
 *
 * The provider owns all three answers and the adapter owns none of them. An
 * adapter that computed a period bucket or built a dedupe key would be holding
 * queue policy, and the two adapters would then shard and deduplicate
 * differently on the same site. What an adapter does is loop, ask, and enqueue
 * through SchedulerPort.
 *
 * Binning is here because a sweep that enumerates every enrolment on a large
 * site in one run is the failure LearnDash 1.5.0 fixed by replacing an
 * every-minute cron with one scheduled event doing bounded work. Twenty-four
 * bins is the design's default; a task that cannot be sharded answers 1.
 */
interface RunsPeriodically
{
    /**
     * How many bins one period is sharded into. One run enqueues this many
     * jobs, each carrying binIndex and binCount in its payload, so a handler
     * can select its own share of the rows. Answer 1 for work that cannot be
     * divided.
     */
    public function binCount(): int;

    /**
     * The period this instant falls in, as a stable string.
     *
     * Two runs of the host clock inside one period must produce the same
     * bucket, because that is what makes the dedupe key below reject the
     * second enqueue. Both hosts are documented multi-firers (Joomla's lazy
     * scheduler is triggered from frontend responses, WP-Cron fires on page
     * load), so a cron that runs twice is the normal case rather than the
     * exotic one.
     */
    public function periodBucket(\DateTimeImmutable $now): string;

    /**
     * The dedupe key for one bin of one period.
     *
     * SchedulerPort replaces a pending task carrying the same key, so this is
     * what stops a second enqueue inside one period producing a second run.
     * The design's shape is {source}:{key}:{bucket}:{bin}; a provider may
     * choose its own as long as it is stable for the pair.
     */
    public function periodicDedupeKey(string $periodBucket, int $binIndex): string;
}
