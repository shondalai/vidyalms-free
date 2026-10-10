<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;

/**
 * What a learner sees of their own progress: the computed course summary
 * (null until the first recompute) and the per-lesson rows.
 */
final class MyCourseProgress
{
    /**
     * @param list<LessonProgress> $lessons
     */
    public function __construct(
        public readonly int $enrolmentId,
        public readonly int $courseId,
        public readonly string $enrolmentStatus,
        public readonly ?CourseProgress $summary,
        public readonly array $lessons,
    ) {
    }
}
