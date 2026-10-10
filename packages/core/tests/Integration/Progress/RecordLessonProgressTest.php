<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class RecordLessonProgressTest extends ProgressTestCase
{
    private const LEARNER = 42;

    private int $courseId;
    private int $moduleId;
    private int $lessonId;
    private int $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse();
        $this->moduleId = $this->seedModule($this->courseId);
        $this->lessonId = $this->seedLesson($this->courseId, $this->moduleId, 'reading', durationSeconds: 600);
        $this->enrolmentId = $this->seedEnrolment(self::LEARNER, $this->courseId);
    }

    private function learner(): Principal
    {
        return Principal::user(self::LEARNER);
    }

    public function testGuestIsRejected(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->recordLessonProgress->execute(Principal::guest(), $this->enrolmentId, $this->lessonId);
    }

    public function testParallelLessonsShareOneWallClockBudget(): void
    {
        $second = $this->seedLesson($this->courseId, $this->moduleId, 'reading', durationSeconds: 600);
        foreach ([$this->lessonId, $second] as $lesson) {
            $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $lesson);
        }
        $this->env->clock->advance('PT15S');
        foreach ([$this->lessonId, $second] as $lesson) {
            $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $lesson, secondsDelta: 120);
        }
        $firstRow = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        $secondRow = $this->lessonProgressRow($this->enrolmentId, $second);
        self::assertNotNull($firstRow);
        self::assertNotNull($secondRow);
        self::assertSame(15, (int) $firstRow['seconds_spent'] + (int) $secondRow['seconds_spent']);
    }

    public function testUnknownEnrolmentIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->recordLessonProgress->execute($this->learner(), 999, $this->lessonId);
    }

    public function testForeignEnrolmentIsRejected(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->recordLessonProgress->execute(Principal::user(7), $this->enrolmentId, $this->lessonId);
    }

    public function testSuspendedEnrolmentRaisesConflict(): void
    {
        $suspended = $this->seedEnrolment(self::LEARNER, $this->seedCourse(), 'suspended');

        $this->expectException(ConflictException::class);

        $this->recordLessonProgress->execute($this->learner(), $suspended, $this->lessonId);
    }

    public function testCompletedEnrolmentKeepsRecordingReviewTime(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);
        self::assertSame('completed', $this->enrolmentRow($this->enrolmentId)['status']);

        $this->env->clock->advance('PT1H');
        $this->recordLessonProgress->execute(
            $this->learner(),
            $this->enrolmentId,
            $this->lessonId,
            secondsDelta: 45,
            positionSeconds: 300,
        );

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame(45, (int) $row['seconds_spent']);
        self::assertSame(300, (int) $row['position_seconds']);
        self::assertSame($this->nowString(), $row['last_heartbeat_at']);
    }

    public function testCompletedEnrolmentHeartbeatNeverRegressesOrReemits(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);

        $enrolment = $this->enrolmentRow($this->enrolmentId);
        self::assertSame('completed', $enrolment['status']);
        $completedAt = $enrolment['completed_at'];
        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $this->env->clock->advance('PT1H');
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);

        // The recompute inside the heartbeat ran against a completed
        // enrolment; its guarded update requires status "active", so no
        // second CourseCompleted can be dispatched.
        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $enrolment = $this->enrolmentRow($this->enrolmentId);
        self::assertSame('completed', $enrolment['status']);
        self::assertSame($completedAt, $enrolment['completed_at']);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
    }

    public function testRecomputeAfterCompletedEnrolmentHeartbeatDispatchesNoSecondCourseCompleted(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $this->env->clock->advance('PT15S');
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, secondsDelta: 15);
        $this->recomputeCourseProgress->execute($this->learner(), $this->enrolmentId);

        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
    }

    public function testUnpublishedLessonIsRejected(): void
    {
        $draftLesson = $this->seedLesson($this->courseId, $this->moduleId, 'video', state: 'draft');

        $this->expectException(EntityNotFoundException::class);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $draftLesson);
    }

    public function testLessonOfAnotherCourseIsRejected(): void
    {
        $otherCourse = $this->seedCourse();
        $otherLesson = $this->seedLesson($otherCourse, $this->seedModule($otherCourse));

        $this->expectException(EntityNotFoundException::class);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $otherLesson);
    }

    public function testInvalidProgressPercentIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, progressPercent: 101.0);
    }

    public function testNegativeSecondsDeltaIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, secondsDelta: -1);
    }

    public function testNegativePositionIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, positionSeconds: -1);
    }

    public function testFirstHeartbeatOpensProgressAndRecomputes(): void
    {
        $this->recordLessonProgress->execute(
            $this->learner(),
            $this->enrolmentId,
            $this->lessonId,
            progressPercent: 25.0,
            secondsDelta: 15,
            positionSeconds: 150,
        );

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('in_progress', $row['status']);
        self::assertSame(25.0, (float) $row['progress_percent']);
        self::assertSame(0, (int) $row['seconds_spent']);
        self::assertSame(150, (int) $row['position_seconds']);
        self::assertSame($this->nowString(), $row['first_accessed_at']);
        self::assertSame($this->nowString(), $row['last_heartbeat_at']);
        self::assertNull($row['completed_at']);

        $position = $this->videoPositionRow(self::LEARNER, $this->lessonId);
        self::assertNotNull($position);
        self::assertSame(150, (int) $position['position_seconds']);
        self::assertSame(600, (int) $position['duration_seconds']);

        $summary = $this->courseProgressRow($this->enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(1, (int) $summary['lessons_total']);
        self::assertSame(0, (int) $summary['lessons_completed']);

        $enrolment = $this->enrolmentRow($this->enrolmentId);
        self::assertSame(0.0, (float) $enrolment['progress_percent']);
        self::assertSame($this->nowString(), $enrolment['last_activity_at']);
    }

    public function testReplayedHeartbeatOnlyMovesHeartbeatAndCappedSeconds(): void
    {
        $heartbeat = function (): void {
            $this->recordLessonProgress->execute(
                $this->learner(),
                $this->enrolmentId,
                $this->lessonId,
                progressPercent: 40.0,
                secondsDelta: 500,
                positionSeconds: 200,
            );
        };

        $heartbeat();
        $first = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($first);
        self::assertSame(0, (int) $first['seconds_spent']);

        $this->env->clock->advance('PT15S');
        $heartbeat();

        $second = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($second);
        self::assertSame($first['status'], $second['status']);
        self::assertSame($first['progress_percent'], $second['progress_percent']);
        self::assertSame($first['position_seconds'], $second['position_seconds']);
        self::assertSame($first['first_accessed_at'], $second['first_accessed_at']);
        self::assertSame($first['completed_at'], $second['completed_at']);
        self::assertSame(15, (int) $second['seconds_spent']);
        self::assertSame($this->nowString(), $second['last_heartbeat_at']);
        self::assertNotSame($first['last_heartbeat_at'], $second['last_heartbeat_at']);
    }

    public function testProgressPercentNeverDecreases(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, progressPercent: 80.0);
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, progressPercent: 30.0);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame(80.0, (float) $row['progress_percent']);
    }

    public function testViewLessonCompletesOnCompletedFlag(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertSame(100.0, (float) $row['progress_percent']);
        self::assertSame($this->nowString(), $row['completed_at']);

        $events = $this->publishedEvents(LessonCompleted::class);
        self::assertCount(1, $events);
    }

    public function testViewLessonCompletesWhenPercentReachesHundred(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, progressPercent: 100.0);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
    }

    public function testLessonCompletedIsEmittedExactlyOnce(): void
    {
        $this->seedLesson($this->courseId, $this->moduleId);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);
        $this->env->clock->advance('PT15S');
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);

        self::assertCount(1, $this->publishedEvents(LessonCompleted::class));
    }

    public function testCompletedLessonNeverRegresses(): void
    {
        $this->seedLesson($this->courseId, $this->moduleId);

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, completed: true);
        $completedAt = $this->lessonProgressRow($this->enrolmentId, $this->lessonId)['completed_at'] ?? null;

        $this->env->clock->advance('PT1H');
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, progressPercent: 10.0);

        $row = $this->lessonProgressRow($this->enrolmentId, $this->lessonId);
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertSame(100.0, (float) $row['progress_percent']);
        self::assertSame($completedAt, $row['completed_at']);
    }

    public function testPassModeLessonIgnoresClientCompletedFlag(): void
    {
        $quizLesson = $this->seedLesson($this->courseId, $this->moduleId, 'quiz');

        $this->recordLessonProgress->execute(
            $this->learner(),
            $this->enrolmentId,
            $quizLesson,
            progressPercent: 100.0,
            completed: true,
        );

        $row = $this->lessonProgressRow($this->enrolmentId, $quizLesson);
        self::assertNotNull($row);
        self::assertSame('in_progress', $row['status']);
        self::assertNull($row['completed_at']);
        self::assertCount(0, $this->publishedEvents(LessonCompleted::class));
    }

    public function testSubmissionModeLessonIgnoresClientCompletedFlag(): void
    {
        $assignmentLesson = $this->seedLesson($this->courseId, $this->moduleId, 'assignment');

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $assignmentLesson, completed: true);

        $row = $this->lessonProgressRow($this->enrolmentId, $assignmentLesson);
        self::assertNotNull($row);
        self::assertSame('in_progress', $row['status']);
        self::assertCount(0, $this->publishedEvents(LessonCompleted::class));
    }

    public function testUnknownLessonTypeRecordsTimeButNeverCompletes(): void
    {
        $scormLesson = $this->seedLesson($this->courseId, $this->moduleId, 'scorm');

        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $scormLesson);
        $this->env->clock->advance('PT30S');
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $scormLesson, secondsDelta: 30, completed: true);

        $row = $this->lessonProgressRow($this->enrolmentId, $scormLesson);
        self::assertNotNull($row);
        self::assertSame('in_progress', $row['status']);
        self::assertSame(30, (int) $row['seconds_spent']);
    }

    public function testVideoPositionUpsertsPerUserAndLesson(): void
    {
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, positionSeconds: 100);
        $this->recordLessonProgress->execute($this->learner(), $this->enrolmentId, $this->lessonId, positionSeconds: 40);

        $position = $this->videoPositionRow(self::LEARNER, $this->lessonId);
        self::assertNotNull($position);
        self::assertSame(40, (int) $position['position_seconds']);

        $count = $this->env->database->count(
            Query::from('video_positions')
                ->where('user_id', '=', self::LEARNER)
                ->where('lesson_id', '=', $this->lessonId),
        );
        self::assertSame(1, $count);
    }
}
