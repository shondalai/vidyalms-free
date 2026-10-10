<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;

final class CompleteQuizLessonOnAttemptTest extends ProgressTestCase
{
    private const LEARNER = 42;

    private int $courseId;
    private int $moduleId;
    private int $lessonId;
    private int $enrolmentId;
    private int $quizId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse('all_lessons');
        $this->moduleId = $this->seedModule($this->courseId);
        $this->lessonId = $this->seedLesson($this->courseId, $this->moduleId, 'quiz');
        $this->enrolmentId = $this->seedEnrolment(self::LEARNER, $this->courseId);
        $this->quizId = $this->seedQuiz($this->courseId, $this->lessonId);
    }

    private function gradedEvent(bool $passed, ?int $lessonId = null, float $percentage = 80.0): AttemptGraded
    {
        return new AttemptGraded(
            $this->env->clock->now(),
            1,
            $this->quizId,
            $lessonId ?? $this->lessonId,
            $this->enrolmentId,
            self::LEARNER,
            $this->courseId,
            $percentage,
            $passed,
        );
    }

    public function testPassingGradeCompletesTheQuizLesson(): void
    {
        $this->seedAttempt($this->quizId, $this->enrolmentId, self::LEARNER, 1, 'graded', 80.0, true);

        ($this->completeQuizLessonOnAttempt)($this->gradedEvent(true));

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertSame(100.0, (float) $row['progress_percent']);
        self::assertSame($this->nowString(), $row['completed_at']);

        $events = $this->publishedEvents(LessonCompleted::class);
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(LessonCompleted::class, $event);
        self::assertSame($this->lessonId, $event->lessonId);

        $summary = $this->courseProgressRow($this->enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(1, (int) $summary['lessons_completed']);
        self::assertSame(80.0, (float) $summary['quiz_average']);
    }

    public function testPassingSubmitEventAlsoCompletes(): void
    {
        $this->seedAttempt($this->quizId, $this->enrolmentId, self::LEARNER, 1, 'graded', 90.0, true);

        $event = new AttemptSubmitted(
            $this->env->clock->now(),
            1,
            $this->quizId,
            $this->lessonId,
            $this->enrolmentId,
            self::LEARNER,
            $this->courseId,
            90.0,
            true,
            false,
        );

        ($this->completeQuizLessonOnAttempt)($event);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
    }

    public function testFailedAttemptDoesNothing(): void
    {
        ($this->completeQuizLessonOnAttempt)($this->gradedEvent(false, percentage: 30.0));

        self::assertNull($this->lessonProgressRow($this->enrolmentId, $this->lessonId));
        self::assertCount(0, $this->publishedEvents(LessonCompleted::class));
        self::assertNull($this->courseProgressRow($this->enrolmentId));
    }

    public function testUngradedSubmitEventDoesNothing(): void
    {
        $event = new AttemptSubmitted(
            $this->env->clock->now(),
            1,
            $this->quizId,
            $this->lessonId,
            $this->enrolmentId,
            self::LEARNER,
            $this->courseId,
            0.0,
            null,
            true,
        );

        ($this->completeQuizLessonOnAttempt)($event);

        self::assertNull($this->lessonProgressRow($this->enrolmentId, $this->lessonId));
        self::assertCount(0, $this->publishedEvents(LessonCompleted::class));
    }

    public function testReplayedEventEmitsLessonCompletedOnlyOnce(): void
    {
        $this->seedAttempt($this->quizId, $this->enrolmentId, self::LEARNER, 1, 'graded', 80.0, true);

        ($this->completeQuizLessonOnAttempt)($this->gradedEvent(true));
        $this->env->clock->advance('PT5M');
        ($this->completeQuizLessonOnAttempt)($this->gradedEvent(true));

        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
    }

    public function testPassingFinalQuizWithoutLessonStillCompletesPassFinalCourse(): void
    {
        $courseId = $this->seedCourse('pass_final');
        $moduleId = $this->seedModule($courseId);
        $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);
        $finalQuiz = $this->seedQuiz($courseId, null, isFinal: true);
        $this->seedAttempt($finalQuiz, $enrolmentId, self::LEARNER, 1, 'graded', 90.0, true);

        $event = new AttemptGraded(
            $this->env->clock->now(),
            2,
            $finalQuiz,
            null,
            $enrolmentId,
            self::LEARNER,
            $courseId,
            90.0,
            true,
        );

        ($this->completeQuizLessonOnAttempt)($event);

        self::assertCount(0, $this->publishedEvents(LessonCompleted::class));
        self::assertSame('completed', $this->enrolmentRow($enrolmentId)['status']);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
    }

    public function testListenerWiredThroughEventBusReactsToDispatch(): void
    {
        $this->seedAttempt($this->quizId, $this->enrolmentId, self::LEARNER, 1, 'graded', 80.0, true);

        $this->events->subscribe(AttemptGraded::class, $this->completeQuizLessonOnAttempt);
        $this->events->dispatch($this->gradedEvent(true));

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
    }
}
