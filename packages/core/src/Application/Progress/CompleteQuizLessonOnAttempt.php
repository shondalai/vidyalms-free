<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The only path by which a pass-mode quiz lesson completes: a passing
 * attempt, submitted auto-graded or graded manually. Already-completed
 * lessons stay untouched, so a re-fired or re-graded event never emits a
 * second LessonCompleted. A passing final quiz without a lesson still
 * recomputes, so pass_final courses complete without waiting for another
 * heartbeat.
 */
final class CompleteQuizLessonOnAttempt
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
        if (!$event instanceof AttemptSubmitted && !$event instanceof AttemptGraded) {
            return;
        }

        if ($event->passed !== true) {
            return;
        }

        if ($event->lessonId !== null) {
            $this->completeLesson($event->enrolmentId, $event->lessonId, $event->userId, $event->courseId);
        }

        $this->recomputeCourseProgress->execute(Principal::user($event->userId), $event->enrolmentId);
    }

    private function completeLesson(int $enrolmentId, int $lessonId, int $userId, int $courseId): void
    {
        $now = $this->clock->now();

        $progress = $this->lessonProgress->findByEnrolmentAndLesson($enrolmentId, $lessonId)
            ?? LessonProgress::start($enrolmentId, $lessonId, $userId, $now);

        if (!$progress->complete($now)) {
            return;
        }

        $this->lessonProgress->save($progress);
        $this->events->dispatch(new LessonCompleted($now, $enrolmentId, $lessonId, $userId, $courseId));
    }
}
