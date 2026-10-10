<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;

final class CompleteAssignmentLessonOnGradeTest extends ProgressTestCase
{
    private const LEARNER = 42;

    private int $courseId;
    private int $lessonId;
    private int $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse('all_lessons');
        $moduleId = $this->seedModule($this->courseId);
        $this->lessonId = $this->seedLesson($this->courseId, $moduleId, 'assignment');
        $this->enrolmentId = $this->seedEnrolment(self::LEARNER, $this->courseId);
    }

    private function gradedEvent(float $points = 80.0): SubmissionGraded
    {
        return new SubmissionGraded(
            $this->env->clock->now(),
            1,
            1,
            $this->lessonId,
            $this->enrolmentId,
            self::LEARNER,
            $this->courseId,
            $points,
            100.0,
        );
    }

    public function testGradedSubmissionCompletesTheAssignmentLesson(): void
    {
        ($this->completeAssignmentLessonOnGrade)($this->gradedEvent());

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertSame($this->nowString(), $row['completed_at']);

        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));

        $summary = $this->courseProgressRow($this->enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(1, (int) $summary['lessons_completed']);
        self::assertSame('completed', $this->enrolmentRow($this->enrolmentId)['status']);
    }

    public function testRegradeEmitsLessonCompletedOnlyOnce(): void
    {
        ($this->completeAssignmentLessonOnGrade)($this->gradedEvent(55.0));
        $this->env->clock->advance('PT10M');
        ($this->completeAssignmentLessonOnGrade)($this->gradedEvent(95.0));

        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
    }

    public function testUnrelatedEventsAreIgnored(): void
    {
        $unrelated = new EnrolmentSuspended(
            $this->env->clock->now(),
            $this->enrolmentId,
            self::LEARNER,
            $this->courseId,
        );

        ($this->completeAssignmentLessonOnGrade)($unrelated);

        self::assertNull($this->lessonProgressRow($this->enrolmentId, $this->lessonId));
        self::assertNull($this->courseProgressRow($this->enrolmentId));
    }

    public function testListenerWiredThroughEventBusReactsToDispatch(): void
    {
        $this->events->subscribe(SubmissionGraded::class, $this->completeAssignmentLessonOnGrade);
        $this->events->dispatch($this->gradedEvent());

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
    }
}
