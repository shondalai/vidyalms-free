<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgressForCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\ModuleArchived;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * Takes a module and everything in it out of a course without deleting
 * anything.
 *
 * The lessons go with the module. A module nobody can reach whose lessons
 * still count towards completion is a course that says one thing to an
 * author and another to a learner, so the two states are never allowed to
 * disagree. Restoring is the reverse in two steps, because the author
 * decides which lessons come back: set the module's state, then each
 * lesson's.
 *
 * Requires teach on the module's course.
 */
final class ArchiveModule
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly LessonRepository $lessons,
        private readonly EnrolmentRepository $enrolments,
        private readonly RecomputeCourseProgressForCourse $recompute,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array{0: Module, 1: ArchiveOutcome}
     */
    public function execute(Principal $principal, int $moduleId): array
    {
        $this->access->requireSignedIn($principal);

        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        $this->access->require($principal, $module->courseId, Capability::TEACH);

        if ($module->isArchived()) {
            return [$module, ArchiveOutcome::notNeeded()];
        }

        $archived = $module->withState(Module::STATE_ARCHIVED);
        $lessons = $this->lessons->byModule($moduleId);

        $archivedLessons = $this->database->transactional(function () use ($archived, $lessons): int {
            $this->modules->update($archived);
            $count = 0;

            foreach ($lessons as $lesson) {
                if ($lesson->isArchived()) {
                    continue;
                }

                $this->lessons->update($lesson->withState(Lesson::STATE_ARCHIVED), null);
                ++$count;
            }

            return $count;
        });

        $enrolments = $this->enrolments->countForCourse($module->courseId);
        $outcome = $enrolments === 0 || $archivedLessons === 0
            ? ArchiveOutcome::notNeeded($archivedLessons, $enrolments)
            : ArchiveOutcome::queued($archivedLessons, $enrolments);

        if ($outcome->progressRecompute === ArchiveOutcome::RECOMPUTE_QUEUED) {
            $this->recompute->queue($module->courseId);
        }

        // One event for one authoring action. The lessons that went with the
        // module are a count on it rather than an event each: a module of
        // forty lessons is not forty-one occurrences.
        $this->events->dispatch(new ModuleArchived(
            $this->clock->now(),
            $module->courseId,
            $moduleId,
            $archivedLessons,
            $enrolments,
            $principal->userId,
        ));

        return [$archived, $outcome];
    }
}
