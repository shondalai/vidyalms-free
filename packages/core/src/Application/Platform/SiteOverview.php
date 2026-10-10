<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Access\ExpireEntitlements;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Platform\GradebookRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\SiteMetricsRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;

/**
 * The site-wide counts and what needs attention.
 *
 * This is the one implementation of these figures. Both host dashboards used
 * to carry their own copy of the same SQL, and a copy in two places is a
 * count that will be corrected in one of them: an adapter that computes a
 * rule the core owns is drift by construction.
 *
 * Requires manage or reports. It used to require reports alone, which made
 * the dashboard the one /admin screen a site administrator holding manage
 * could not open, while every other one let them in. A landing screen a
 * manager is refused from is a bug in the capability list, not a policy.
 */
final class SiteOverview
{
    private const DEFAULT_WAITING_DAYS = 3;
    private const DEFAULT_EXPIRING_DAYS = 14;
    private const TOP_COURSES = 5;

    public function __construct(
        private readonly SiteMetricsRepository $metrics,
        private readonly GradebookRepository $gradebook,
        private readonly CertificateRepository $certificates,
        private readonly CourseRepository $courses,
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly Settings $settings,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly ?HostFact $expirySweepSchedule = null,
    ) {
    }

    public function execute(
        Principal $principal,
        int $waitingDays = self::DEFAULT_WAITING_DAYS,
        int $expiringDays = self::DEFAULT_EXPIRING_DAYS,
        int $periodDays = 30,
    ): SiteOverviewReport {
        $this->guard->require($principal, Capability::MANAGE);

        if (!\in_array($periodDays, [7, 30], true)) {
            throw ValidationException::forField('period_days', 'Choose 7 or 30 days.');
        }

        if ($waitingDays < 0) {
            throw ValidationException::forField('waiting_days', 'Must be zero or more.');
        }

        if ($expiringDays < 1) {
            throw ValidationException::forField('expiring_days', 'Must be 1 or more.');
        }

        $now = $this->clock->now();
        $courseCounts = $this->metrics->courseCountsByState();
        $enrolmentCounts = $this->metrics->enrolmentCountsByStatus();

        $waitingCutoff = $now->sub(new \DateInterval(sprintf('PT%dH', $waitingDays * 24)));
        $submissions = $this->gradebook->submissionsWaitingSince($waitingCutoff);
        $attempts = $this->gradebook->attemptsWaitingSince($waitingCutoff);

        $waitingCourses = [];

        foreach ([...$submissions, ...$attempts] as $row) {
            $waitingCourses[(int) $row['course_id']] = true;
        }

        [$missingCount, $missingTypes] = $this->lessonsWithoutProvider();

        return new SiteOverviewReport(
            $courseCounts[CourseState::Published->value] ?? 0,
            $courseCounts[CourseState::Draft->value] ?? 0,
            $courseCounts[CourseState::Archived->value] ?? 0,
            $enrolmentCounts[Enrolment::STATUS_ACTIVE] ?? 0,
            $enrolmentCounts[Enrolment::STATUS_SUSPENDED] ?? 0,
            $enrolmentCounts[Enrolment::STATUS_COMPLETED] ?? 0,
            $this->metrics->completionsSince($now->setDate(
                (int) $now->format('Y'),
                (int) $now->format('n'),
                1,
            )->setTime(0, 0)),
            $this->certificates->countAll(),
            $this->certificates->countRevoked(),
            \count($submissions),
            \count($attempts),
            \count($waitingCourses),
            $waitingDays,
            $this->metrics->expiringEntitlements($now, $now->add(new \DateInterval(sprintf('P%dD', $expiringDays)))),
            $expiringDays,
            $this->lastSweepRun(),
            $this->expirySweepSchedule?->value(),
            $missingCount,
            $missingTypes,
            $this->topCourses(),
            $now,
            $this->metrics->learningActivity($now, $periodDays),
        );
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function lessonsWithoutProvider(): array
    {
        $count = 0;
        $types = [];

        foreach ($this->metrics->lessonCountsByType() as $type => $lessons) {
            if ($this->lessonTypes->has($type)) {
                continue;
            }

            $count += $lessons;
            $types[] = $type;
        }

        return [$count, $types];
    }

    /**
     * @return list<TopCourse>
     */
    private function topCourses(): array
    {
        $rows = $this->metrics->topCoursesByActiveEnrolments(self::TOP_COURSES);

        if ($rows === []) {
            return [];
        }

        $courses = [];

        foreach ($this->courses->byIds(array_map(static fn (array $row): int => $row['course_id'], $rows)) as $course) {
            $courses[$course->id] = $course;
        }

        $top = [];

        foreach ($rows as $row) {
            $course = $courses[$row['course_id']] ?? null;

            if ($course !== null) {
                $top[] = new TopCourse(
                    $course,
                    $row['active_count'],
                    $row['enrolled_count'],
                    $row['completed_count'],
                    $row['lesson_count'],
                );
            }
        }

        return $top;
    }

    private function lastSweepRun(): ?\DateTimeImmutable
    {
        $stored = $this->settings->getString(ExpireEntitlements::LAST_RUN_SETTING);

        if ($stored === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $stored, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }
}
