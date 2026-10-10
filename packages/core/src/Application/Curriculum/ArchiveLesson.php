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
use Shondalai\VidyaLMS\Core\Domain\Events\LessonArchived;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Takes a lesson out of a course without deleting anything.
 *
 * An archived lesson leaves the learner curriculum, the lessons_total of
 * every enrolment and the completion rule. Its progress rows, attempts,
 * submissions and grades stay where they are: those record what a person
 * did, they are append-only, and an authoring decision is not a correction
 * of history. Restoring the lesson (PATCH the state back to draft or
 * published) brings all of it back with the lesson.
 *
 * Because completion is a pure function over progress, removing a lesson can
 * finish the course for a learner who had done everything else. Nothing else
 * would notice: a finished learner sends no more heartbeats, and a heartbeat
 * is what usually triggers a recompute. So the archive queues one for every
 * enrolment of the course, and the response says so.
 *
 * Requires teach on the lesson's course.
 */
final class ArchiveLesson
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly EnrolmentRepository $enrolments,
        private readonly RecomputeCourseProgressForCourse $recompute,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array{0: Lesson, 1: ArchiveOutcome}
     */
    public function execute(Principal $principal, int $lessonId): array
    {
        $this->access->requireSignedIn($principal);

        $lesson = $this->lessons->byId($lessonId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $lessonId);

        $this->access->require($principal, $lesson->courseId, Capability::TEACH);

        // Archiving an archived lesson answers the same thing again and
        // queues nothing: a client retrying a DELETE must not set a
        // course-wide recompute going a second time.
        if ($lesson->isArchived()) {
            return [$lesson, ArchiveOutcome::notNeeded()];
        }

        $archived = $lesson->withState(Lesson::STATE_ARCHIVED);
        $this->lessons->update($archived, null);

        $enrolments = $this->enrolments->countForCourse($lesson->courseId);
        $outcome = $enrolments === 0
            ? ArchiveOutcome::notNeeded(1)
            : ArchiveOutcome::queued(1, $enrolments);

        if ($outcome->progressRecompute === ArchiveOutcome::RECOMPUTE_QUEUED) {
            $this->recompute->queue($lesson->courseId);
        }

        // Dispatched on the transition, after the state write, exactly as the
        // course events are. It carries the same counts the response does,
        // because a listener needs the size of what just happened as much as
        // the fact of it.
        $this->events->dispatch(new LessonArchived(
            $this->clock->now(),
            $lesson->courseId,
            $lessonId,
            $lesson->moduleId,
            $outcome->lessonsArchived,
            $outcome->enrolmentsAffected,
            $principal->userId,
        ));

        return [$archived, $outcome];
    }
}
