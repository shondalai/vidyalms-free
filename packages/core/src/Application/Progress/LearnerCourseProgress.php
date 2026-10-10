<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Access\LearnerEnrolment;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;

/**
 * What staff see of one learner in one course: the same summary and lesson
 * rows the learner sees of themselves, with the enrolment and entitlement
 * beside them.
 */
final class LearnerCourseProgress
{
    /**
     * @param list<LessonProgress> $lessons
     */
    public function __construct(
        public readonly LearnerEnrolment $enrolment,
        public readonly ?CourseProgress $summary,
        public readonly array $lessons,
    ) {
    }
}
