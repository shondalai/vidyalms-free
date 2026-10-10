<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;

/**
 * The workbench header and settings tab: a management course row plus what
 * only the single-course view needs.
 */
final class ManagedCourseDetail
{
    /**
     * @param list<Course> $prerequisites
     */
    public function __construct(
        public readonly ManagedCourse $managed,
        public readonly array $prerequisites,
        public readonly int $pendingGradingCount,
    ) {
    }
}
