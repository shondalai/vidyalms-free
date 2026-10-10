<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * The counts behind the admin dashboard, in one place so that neither
 * adapter writes them again. Both host dashboards used to carry their own
 * copy of this SQL, which is exactly the drift the adapters are meant not to
 * have: one of them will always be corrected without the other.
 */
interface SiteMetricsRepository
{
    /**
     * @return array<string, int> keyed by state (draft, published, archived)
     */
    public function courseCountsByState(): array;

    /**
     * @return array<string, int> keyed by status (active, suspended, completed, cancelled)
     */
    public function enrolmentCountsByStatus(): array;

    /**
     * Enrolments whose completed_at falls on or after the moment.
     */
    public function completionsSince(\DateTimeImmutable $from): int;

    /**
     * Active entitlements expiring inside the window that still cover an
     * active enrolment: an expiry nobody is using is not a warning.
     */
    public function expiringEntitlements(\DateTimeImmutable $from, \DateTimeImmutable $until): int;

    /**
     * Every lesson type key in use, with how many lessons carry it.
     *
     * @return array<string, int>
     */
    public function lessonCountsByType(): array;

    /**
     * Published courses with at least one active enrolment, most active
     * first, at most $limit of them.
     *
     * @return list<array{course_id: int, active_count: int, enrolled_count: int, completed_count: int, lesson_count: int}>
     */
    public function topCoursesByActiveEnrolments(int $limit): array;

    /**
     * Bounded daily counts and recent learning events, in UTC. The current
     * period ends at now; the comparison ends at the same time of day.
     *
     * @return array{
     *     period_days: int,
     *     daily: list<array{date: string, enrolments: int, completions: int, previous_enrolments: int, previous_completions: int}>,
     *     recent: list<array{kind: string, course_id: int, course_title: string, occurred_at: string}>
     * }
     */
    public function learningActivity(\DateTimeImmutable $now, int $days): array;
}
