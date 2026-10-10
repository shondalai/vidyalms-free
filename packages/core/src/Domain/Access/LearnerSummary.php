<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

/**
 * Stored learning activity for one distinct enrolled host account id.
 * Account name and email live in the CMS and are attached by the application
 * after the learning database has sorted and cut the page.
 */
final class LearnerSummary
{
    public function __construct(
        public readonly int $userId,
        public readonly int $courseCount,
        public readonly int $activeCourseCount,
        public readonly int $completedCourseCount,
        public readonly float $averageProgress,
        public readonly ?\DateTimeImmutable $lastActivityAt,
        public readonly \DateTimeImmutable $lastEnrolledAt,
    ) {
    }
}
