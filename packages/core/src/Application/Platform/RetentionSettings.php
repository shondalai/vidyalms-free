<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * How long the two highest-volume personal-data artefacts are kept.
 *
 * Settings rows rather than columns, for the reason the notification design
 * gives: a site-level default a report or a schedule inherits is a two-level
 * resolution, and a column would privilege whichever level shipped first.
 *
 * **activity_log is the table GDPR Article 5(1)(e) binds hardest.** It is the
 * highest-volume personal-data table in the product, it records what a named
 * person did and when, and it is written from a heartbeat, so "keep it
 * forever" is a decision nobody made.
 *
 * **The retention has a real tension with dispute evidence**, and the
 * resolution is stated here rather than discovered during a chargeback: keep
 * the general retention short, and snapshot the evidence bundle when a
 * dispute opens. A chargeback can be filed up to 120 days after payment and
 * later still where the purchase was for a future-dated cohort, so a site
 * whose retention is shorter than its dispute window is told so on the admin
 * screen instead of finding out when it has nothing to submit.
 *
 * Report artefacts are the same rule one level up: report_runs rows and their
 * files are personal data, they carry a non-null expiry populated from the
 * setting below, and a sweep deletes the artefacts past it so an erasure
 * request does not have to hunt object storage for an untracked blob.
 */
final class RetentionSettings
{
    public const ACTIVITY_LOG_DAYS = 'activity_log.retention_days';

    public const REPORT_ARTEFACT_DAYS = 'reports.artefact_retention_days';

    /**
     * Long enough to cover a chargeback window with room, short enough to be
     * a decision rather than an accumulation.
     */
    public const DEFAULT_ACTIVITY_LOG_DAYS = 400;

    /**
     * A generated report is a convenience artefact, not a record: the rows it
     * was built from outlive it and it can be regenerated identically, which
     * is exactly what report_runs.timezone and the pinned parameters are for.
     */
    public const DEFAULT_REPORT_ARTEFACT_DAYS = 30;

    /** The floor, so a misconfiguration cannot make the sweep delete on sight. */
    private const MINIMUM_DAYS = 1;

    /** The ceiling, so "forever" has to be said out loud rather than typed as a big number. */
    private const MAXIMUM_DAYS = 3650;

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function activityLogDays(): int
    {
        return $this->clamp($this->settings->getInt(self::ACTIVITY_LOG_DAYS, self::DEFAULT_ACTIVITY_LOG_DAYS));
    }

    public function reportArtefactDays(): int
    {
        return $this->clamp($this->settings->getInt(self::REPORT_ARTEFACT_DAYS, self::DEFAULT_REPORT_ARTEFACT_DAYS));
    }

    /**
     * The expiry a report run is stamped with at insert.
     *
     * Computed here rather than left to each caller, because the column is
     * non-null precisely so that a row inserted by a caller that forgot the
     * setting cannot hold a null and be skipped by a `WHERE expires_at < now`
     * sweep for the life of the site.
     */
    public function reportArtefactExpiry(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('+' . $this->reportArtefactDays() . ' days');
    }

    /** The instant an activity_log sweep deletes before. */
    public function activityLogHorizon(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . $this->activityLogDays() . ' days');
    }

    /**
     * Whether the general retention is shorter than the dispute window, which
     * the admin screen states rather than leaving a seller to discover.
     */
    public function activityLogCoversDisputes(int $disputeWindowDays = 120): bool
    {
        return $this->activityLogDays() >= $disputeWindowDays;
    }

    private function clamp(int $days): int
    {
        return max(self::MINIMUM_DAYS, min(self::MAXIMUM_DAYS, $days));
    }
}
