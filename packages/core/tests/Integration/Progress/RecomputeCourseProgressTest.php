<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class RecomputeCourseProgressTest extends ProgressTestCase
{
    private const LEARNER = 42;

    private function learner(): Principal
    {
        return Principal::user(self::LEARNER);
    }

    public function testGuestIsRejected(): void
    {
        $courseId = $this->seedCourse();
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->expectException(NotAuthorizedException::class);

        $this->recomputeCourseProgress->execute(Principal::guest(), $enrolmentId);
    }

    public function testUnknownEnrolmentIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->recomputeCourseProgress->execute($this->learner(), 999);
    }

    public function testStrangerWithoutManageCapabilityIsRejected(): void
    {
        $courseId = $this->seedCourse();
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->expectException(NotAuthorizedException::class);

        $this->recomputeCourseProgress->execute(Principal::user(7), $enrolmentId);
    }

    public function testManagerMayRecomputeForOthers(): void
    {
        $courseId = $this->seedCourse();
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);
        $this->env->authorization->grant(7, Capability::MANAGE);

        $this->recomputeCourseProgress->execute(Principal::user(7), $enrolmentId);

        self::assertNotNull($this->courseProgressRow($enrolmentId));
    }

    public function testAllLessonsRuleCompletesCourseEndToEnd(): void
    {
        $courseId = $this->seedCourse('all_lessons');
        $moduleId = $this->seedModule($courseId);
        $lessonA = $this->seedLesson($courseId, $moduleId);
        $lessonB = $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonA, completed: true);

        $enrolment = $this->enrolmentRow($enrolmentId);
        self::assertSame('active', $enrolment['status']);
        self::assertSame(50.0, (float) $enrolment['progress_percent']);
        self::assertCount(0, $this->publishedEvents(CourseCompleted::class));

        $this->env->clock->advance('PT15S');
        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonB, completed: true);

        $enrolment = $this->enrolmentRow($enrolmentId);
        self::assertSame('completed', $enrolment['status']);
        self::assertSame(100.0, (float) $enrolment['progress_percent']);
        self::assertSame($this->nowString(), $enrolment['completed_at']);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(2, (int) $summary['lessons_total']);
        self::assertSame(2, (int) $summary['lessons_completed']);
        self::assertSame(100.0, (float) $summary['percent']);
        self::assertSame($this->nowString(), $summary['computed_at']);

        $events = $this->publishedEvents(CourseCompleted::class);
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(CourseCompleted::class, $event);
        self::assertSame($enrolmentId, $event->enrolmentId);
        self::assertSame($courseId, $event->courseId);
        self::assertSame(self::LEARNER, $event->userId);
    }

    public function testCourseCompletedIsNotEmittedAgainOnLaterRecompute(): void
    {
        $courseId = $this->seedCourse('all_lessons');
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonId, completed: true);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $completedAt = $this->enrolmentRow($enrolmentId)['completed_at'];

        $this->env->clock->advance('PT1H');
        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);
        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);

        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
        self::assertSame($completedAt, $this->enrolmentRow($enrolmentId)['completed_at']);
    }

    public function testPercentageRuleCompletesAtThreshold(): void
    {
        $courseId = $this->seedCourse('percentage', 50);
        $moduleId = $this->seedModule($courseId);
        $lessonA = $this->seedLesson($courseId, $moduleId);
        $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonA, completed: true);

        $enrolment = $this->enrolmentRow($enrolmentId);
        self::assertSame('completed', $enrolment['status']);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));
    }

    public function testPercentageRuleWithoutThresholdNeverCompletes(): void
    {
        $courseId = $this->seedCourse('percentage', null);
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonId, completed: true);

        self::assertSame('active', $this->enrolmentRow($enrolmentId)['status']);
        self::assertCount(0, $this->publishedEvents(CourseCompleted::class));
    }

    public function testPassFinalRuleCompletesOnlyWhenFinalQuizPassed(): void
    {
        $courseId = $this->seedCourse('pass_final');
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);
        $quizId = $this->seedQuiz($courseId, isFinal: true);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonId, completed: true);
        self::assertSame('active', $this->enrolmentRow($enrolmentId)['status']);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(0, (int) $summary['final_quiz_passed']);

        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER, 1, 'graded', 45.0, false);
        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);
        self::assertSame('active', $this->enrolmentRow($enrolmentId)['status']);
        self::assertCount(0, $this->publishedEvents(CourseCompleted::class));

        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER, 2, 'graded', 85.0, true);
        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);

        $enrolment = $this->enrolmentRow($enrolmentId);
        self::assertSame('completed', $enrolment['status']);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(1, (int) $summary['final_quiz_passed']);
    }

    public function testQuizAverageUsesBestPercentagePerQuiz(): void
    {
        $courseId = $this->seedCourse();
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);
        $quizA = $this->seedQuiz($courseId);
        $quizB = $this->seedQuiz($courseId);

        $this->seedAttempt($quizA, $enrolmentId, self::LEARNER, 1, 'graded', 40.0, false);
        $this->seedAttempt($quizA, $enrolmentId, self::LEARNER, 2, 'graded', 80.0, true);
        $this->seedAttempt($quizB, $enrolmentId, self::LEARNER, 1, 'graded', 60.0, true);
        $this->seedAttempt($quizB, $enrolmentId, self::LEARNER, 2, 'in_progress');

        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(70.0, (float) $summary['quiz_average']);
        self::assertNull($summary['final_quiz_passed']);
    }

    public function testQuizAverageIsNullWithoutGradedAttempts(): void
    {
        $courseId = $this->seedCourse();
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);
        $quizId = $this->seedQuiz($courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER, 1, 'in_progress');

        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertNull($summary['quiz_average']);
    }

    public function testUnpublishedLessonsCountOnNeitherSide(): void
    {
        $courseId = $this->seedCourse('all_lessons');
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId);
        $draftLesson = $this->seedLesson($courseId, $moduleId, state: 'draft');
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recordLessonProgress->execute($this->learner(), $enrolmentId, $lessonId, completed: true);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(1, (int) $summary['lessons_total']);
        self::assertSame(1, (int) $summary['lessons_completed']);
        self::assertSame('completed', $this->enrolmentRow($enrolmentId)['status']);
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $draftRow = $this->lessonProgressRow($enrolmentId, $draftLesson);
        self::assertNull($draftRow);
    }

    public function testCourseWithoutPublishedLessonsNeverCompletes(): void
    {
        $courseId = $this->seedCourse('all_lessons');
        $enrolmentId = $this->seedEnrolment(self::LEARNER, $courseId);

        $this->recomputeCourseProgress->execute($this->learner(), $enrolmentId);

        $summary = $this->courseProgressRow($enrolmentId);
        self::assertNotNull($summary);
        self::assertSame(0, (int) $summary['lessons_total']);
        self::assertSame(0.0, (float) $summary['percent']);
        self::assertSame('active', $this->enrolmentRow($enrolmentId)['status']);
        self::assertCount(0, $this->publishedEvents(CourseCompleted::class));
    }
}
