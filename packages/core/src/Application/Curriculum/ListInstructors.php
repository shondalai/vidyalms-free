<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The instructors of one course, lead roles first. Reading the list needs any
 * course capability; changing it needs manage, because instructorship is what
 * the per-course rule keys on.
 */
final class ListInstructors
{
    public function __construct(
        private readonly ManagedCourseView $view,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return list<Instructor>
     */
    public function execute(Principal $principal, int $courseId): array
    {
        $course = $this->access->require(
            $principal,
            $courseId,
            Capability::TEACH,
            Capability::GRADE,
            Capability::ENROL_OTHERS,
            Capability::VIEW_REPORTS,
        );

        return $this->view->instructorsOf($course);
    }
}
