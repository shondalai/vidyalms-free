<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

/**
 * The computed snapshot of one enrolment's course progress. Always derived
 * by RecomputeCourseProgress, never edited by hand; deleting the row loses
 * nothing that a recompute cannot restore.
 */
final class CourseProgress
{
    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $lessonsTotal,
        public readonly int $lessonsCompleted,
        public readonly float $percent,
        public readonly ?float $quizAverage,
        public readonly ?bool $finalQuizPassed,
        public readonly \DateTimeImmutable $computedAt,
    ) {
    }
}
