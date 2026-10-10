<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * One lesson in its editing shape. Requires teach on the lesson's course.
 */
final class GetAuthoringLesson
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly AuthoringLessonView $view,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $lessonId): AuthoringLesson
    {
        $this->access->requireSignedIn($principal);

        $lesson = $this->lessons->byId($lessonId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $lessonId);

        $this->access->require($principal, $lesson->courseId, Capability::TEACH);

        return $this->view->of($lesson);
    }

    /**
     * The same shape for a lesson the caller has just written; the write
     * already applied the rule, so this does not ask again.
     */
    public function reload(int $lessonId): AuthoringLesson
    {
        $lesson = $this->lessons->byId($lessonId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $lessonId);

        return $this->view->of($lesson);
    }
}
