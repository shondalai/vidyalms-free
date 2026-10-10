<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Moves one lesson to another module of the same course, and puts it last
 * there. UpdateLesson deliberately refuses a module change, and ReorderLessons
 * only permutes what a module already holds, so without this a curriculum
 * reorganised at the source could never be re-imported: the lesson would stay
 * under the module it first arrived in.
 *
 * Only within a course. A lesson carries its course id, and progress, quiz and
 * assignment rows are written against it, so moving a lesson to another
 * course's module would leave a learner's history pointing at a course the
 * lesson no longer belongs to. That is a delete and a re-create, and it is the
 * caller's decision to make, not a side effect of a move.
 */
final class MoveLesson
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $lessonId, int $moduleId): Lesson
    {
        $this->access->requireSignedIn($principal);

        $lesson = $this->lessons->byId($lessonId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $lessonId);

        $this->access->require($principal, $lesson->courseId, Capability::TEACH);
        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        if ($module->courseId !== $lesson->courseId) {
            throw ValidationException::forField(
                'module_id',
                'A lesson can only move between modules of the course it belongs to.',
            );
        }

        if ($lesson->moduleId === $moduleId) {
            return $lesson;
        }

        // The same rule UpdateLesson enforces, on the other route into the
        // same state: a published lesson may not land in a module that is not
        // published, or it would be invisible and still countable.
        if ($lesson->isPublished() && $module->state !== Module::STATE_PUBLISHED) {
            throw ValidationException::forField(
                'module_id',
                sprintf(
                    'Module %d is not published, so a published lesson cannot move into it.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $moduleId,
                ),
            );
        }

        $moved = new Lesson(
            id: $lesson->id,
            courseId: $lesson->courseId,
            moduleId: $moduleId,
            title: $lesson->title,
            slug: $lesson->slug,
            type: $lesson->type,
            summary: $lesson->summary,
            durationSeconds: $lesson->durationSeconds,
            position: $this->lessons->nextPosition($moduleId),
            state: $lesson->state,
            freePreview: $lesson->freePreview,
        );

        $this->lessons->update($moved, null);

        return $moved;
    }
}
