<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The only path by which a submission-mode assignment lesson completes: the
 * instructor approval of the latest submission. Failed work and requested
 * revisions stay incomplete. Re-delivery keeps LessonCompleted at exactly once.
 */
final class CompleteAssignmentLessonOnGrade
{
    public function __construct(
        private readonly LessonProgressRepository $lessonProgress,
        private readonly RecomputeCourseProgress $recomputeCourseProgress,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        if (!$event instanceof SubmissionGraded || !$event->approved) {
            return;
        }

        $now = $this->clock->now();

        $progress = $this->lessonProgress->findByEnrolmentAndLesson($event->enrolmentId, $event->lessonId)
            ?? LessonProgress::start($event->enrolmentId, $event->lessonId, $event->userId, $now);

        if ($progress->complete($now)) {
            $this->lessonProgress->save($progress);
            $this->events->dispatch(new LessonCompleted(
                $now,
                $event->enrolmentId,
                $event->lessonId,
                $event->userId,
                $event->courseId,
            ));
        }

        $this->recomputeCourseProgress->execute(Principal::user($event->userId), $event->enrolmentId);
    }
}
