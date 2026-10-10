<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * The admin dashboard: four counts with their secondary figures, what needs
 * attention, the busiest published courses, and a bounded activity snapshot.
 */
final class SiteOverviewReport
{
    /**
     * @param list<string>     $lessonTypesWithoutProvider
     * @param list<TopCourse>  $topCourses
     * @param array{
     *     period_days: int,
     *     daily: list<array{date: string, enrolments: int, completions: int, previous_enrolments: int, previous_completions: int}>,
     *     recent: list<array{kind: string, course_id: int, course_title: string, occurred_at: string}>
     * } $activity
     */
    public function __construct(
        public readonly int $publishedCourses,
        public readonly int $draftCourses,
        public readonly int $archivedCourses,
        public readonly int $activeEnrolments,
        public readonly int $suspendedEnrolments,
        public readonly int $completedEnrolments,
        public readonly int $completionsThisMonth,
        public readonly int $certificatesIssued,
        public readonly int $certificatesRevoked,
        public readonly int $waitingSubmissions,
        public readonly int $waitingAttempts,
        public readonly int $waitingCourses,
        public readonly int $waitingOlderThanDays,
        public readonly int $expiringEntitlements,
        public readonly int $expiringWithinDays,
        public readonly ?\DateTimeImmutable $expirySweepLastRunAt,
        public readonly ?string $expirySweepSchedule,
        public readonly int $lessonsWithoutProvider,
        public readonly array $lessonTypesWithoutProvider,
        public readonly array $topCourses,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly array $activity,
    ) {
    }
}
