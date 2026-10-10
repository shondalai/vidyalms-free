<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Replaces the prerequisite list of one course.
 *
 * Two courses are involved in every write, so two rules apply: the caller
 * must be able to teach the course being edited, and each named
 * prerequisite must be a course the caller could already see. A course they
 * could not see answers "not found", the same as an id that is not there,
 * so the route cannot be read as a directory of the courses table.
 */
final class SetPrerequisites
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param list<int> $prerequisiteCourseIds
     */
    public function execute(Principal $principal, int $courseId, array $prerequisiteCourseIds): void
    {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $ids = array_values(array_unique($prerequisiteCourseIds));

        if (\in_array($courseId, $ids, true)) {
            throw ValidationException::forField('prerequisites', 'A course cannot be its own prerequisite.');
        }

        foreach ($ids as $id) {
            $prerequisite = $this->courses->byId($id);

            if ($prerequisite === null || !$this->isVisibleTo($principal, $prerequisite)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw EntityNotFoundException::for('Course', $id);
            }
        }

        $this->courses->replacePrerequisites($courseId, $ids);
    }

    /**
     * The same bar the learner course route applies: a published course is
     * readable by anyone who has its id, and anything else only by staff of
     * that course.
     */
    private function isVisibleTo(Principal $principal, Course $course): bool
    {
        if ($course->isPublished()) {
            return true;
        }

        return $this->access->can(
            $principal,
            $course->id,
            Capability::TEACH,
            Capability::GRADE,
            Capability::ENROL_OTHERS,
            Capability::VIEW_REPORTS,
        );
    }
}
