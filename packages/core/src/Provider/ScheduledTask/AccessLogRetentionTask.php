<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationRetentionPolicies;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The only writer that removes a row from the admin access log.
 *
 * **The audited party does not set the audit log's retention.** That is the
 * point of ISO/IEC 27001:2022 A.8.15, and an earlier draft of the design lost
 * it by routing the sweep through a per-organisation policy an organisation
 * owner may edit. So the duration here is a **site-wide floor**: a
 * per-organisation policy may lengthen it and may never shorten it, and a row
 * with no owning organisation is pruned on the floor itself.
 *
 * The floor is a settings row rather than a column, following the precedent
 * for site-wide operational values that no provider interprets and no report
 * filters on. It is read on every run rather than cached, because the run is
 * once a day and a stale floor is a deletion that cannot be undone.
 *
 * {@see retentionDaysFor()} is the seam the per-organisation lengthening
 * arrives through, and it has a caller: `organisations.retention_policy`, its
 * write route and its named refusal shipped with the wave that owns retention,
 * so {@see run()} asks this method per owning organisation and the clamp is
 * what makes the answer safe. A row with no owning organisation is pruned on
 * the floor itself.
 *
 * The two paragraphs this replaced said the opposite, one screen above the code
 * that had already made them false: that the clamp had no caller and that the
 * column did not exist. A docblock contradicting the method below it is worse
 * than none, because a reader trusts it.
 */
final class AccessLogRetentionTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    /**
     * The key follows the vocabulary every registered task on both hosts
     * already uses. The design's table spells it "org.access_log_retention";
     * a host job row stores this string and both drains resolve a handler by
     * it, so it is spelled the way the other fourteen are.
     */
    public const KEY = 'vidyalms.organisation.access_log_retention';

    /**
     * The site-wide floor, in days. It is deliberately long: the log is the
     * evidence an auditor asks for, and a floor short enough to be convenient
     * is a floor that has already deleted what they came for.
     */
    public const RETENTION_SETTING = 'admin_access_log.retention_days';
    public const DEFAULT_RETENTION_DAYS = 400;

    /** How many rows one run removes, so a long-neglected log drains over several runs. */
    public const BATCH = 500;

    public function __construct(
        private readonly AdminAccessLogStore $log,
        private readonly Settings $settings,
        /**
         * Where the per-organisation lengthening is read from.
         *
         * Trailing and defaulted, so a caller built before the retention column
         * existed keeps constructing this task unchanged and gets the site floor
         * for every row, which is exactly what it got before.
         */
        private readonly ?OrganisationRetentionPolicies $policies = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $days = $this->floorDays();
        $lengthened = $this->lengthenedCutOffs($days);
        $removed = $this->log->prune($this->log->cutOff($days), self::BATCH, $lengthened);

        return sprintf(
            'pruned=%d floor_days=%d lengthened_organisations=%d',
            $removed,
            $days,
            \count($lengthened),
        );
    }

    /**
     * The organisations whose own policy keeps their rows longer, as the
     * instant each one's cut-off falls on.
     *
     * **This is where {@see retentionDaysFor()} stopped being a clamp with no
     * caller.** The method shipped with the sweep and the column it needed did
     * not exist, which was said out loud rather than left to look like a rule.
     * Now `organisations.retention_policy` exists, so the sweep asks per owning
     * organisation, and the clamp is what makes the answer safe: a policy may
     * lengthen the floor and can never shorten it, whatever is stored.
     *
     * A row with no owning organisation is pruned on the floor itself, which is
     * the design's own sentence and is why this map is consulted rather than
     * required: an organisation absent from it keeps the floor.
     *
     * @return array<int, string>
     */
    private function lengthenedCutOffs(int $floorDays): array
    {
        $out = [];

        foreach ($this->policies?->accessLogRetentionDays() ?? [] as $organisationId => $requested) {
            $days = $this->retentionDaysFor($organisationId, $requested);

            if ($days > $floorDays) {
                $out[$organisationId] = $this->log->cutOff($days);
            }
        }

        return $out;
    }

    /**
     * The site-wide floor, never below one day and never below what a stored
     * value claims when that value is nonsense. A settings row holding a
     * string, a zero or a negative number is a misconfiguration, and the safe
     * reading of a misconfigured retention floor is the longer one.
     */
    public function floorDays(): int
    {
        $stored = $this->settings->getInt(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS);

        return $stored < 1 ? self::DEFAULT_RETENTION_DAYS : $stored;
    }

    /**
     * How long one organisation's rows are kept.
     *
     * Today every organisation is kept for the floor. The signature is the
     * one a per-organisation policy needs, and the clamp below is the rule
     * that policy may never break.
     */
    public function retentionDaysFor(int $organisationId, ?int $requestedDays = null): int
    {
        $floor = $this->floorDays();

        return $requestedDays === null ? $floor : max($floor, $requestedDays);
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
