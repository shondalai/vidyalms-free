<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class GetMyCourseProgressTest extends ProgressTestCase
{
    private const LEARNER = 42;

    private int $courseId;
    private int $moduleId;
    private int $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse();
        $this->moduleId = $this->seedModule($this->courseId);
        $this->enrolmentId = $this->seedEnrolment(self::LEARNER, $this->courseId);
    }

    public function testGuestIsRejected(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->getMyCourseProgress->execute(Principal::guest(), $this->enrolmentId);
    }

    public function testUnknownEnrolmentIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->getMyCourseProgress->execute(Principal::user(self::LEARNER), 999);
    }

    public function testStrangerCannotReadAnotherLearnersProgress(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->getMyCourseProgress->execute(Principal::user(7), $this->enrolmentId);
    }

    public function testFreshEnrolmentHasNoSummaryAndNoLessonRows(): void
    {
        $result = $this->getMyCourseProgress->execute(Principal::user(self::LEARNER), $this->enrolmentId);

        self::assertSame($this->enrolmentId, $result->enrolmentId);
        self::assertSame($this->courseId, $result->courseId);
        self::assertSame('active', $result->enrolmentStatus);
        self::assertNull($result->summary);
        self::assertSame([], $result->lessons);
    }

    public function testReturnsSummaryAndPerLessonRows(): void
    {
        $lessonA = $this->seedLesson($this->courseId, $this->moduleId);
        $lessonB = $this->seedLesson($this->courseId, $this->moduleId);

        $learner = Principal::user(self::LEARNER);
        $this->recordLessonProgress->execute($learner, $this->enrolmentId, $lessonA, completed: true);
        $this->recordLessonProgress->execute($learner, $this->enrolmentId, $lessonB, progressPercent: 30.0, secondsDelta: 15);

        $result = $this->getMyCourseProgress->execute($learner, $this->enrolmentId);

        self::assertNotNull($result->summary);
        self::assertSame(2, $result->summary->lessonsTotal);
        self::assertSame(1, $result->summary->lessonsCompleted);
        self::assertSame(50.0, $result->summary->percent);
        self::assertNull($result->summary->quizAverage);
        self::assertNull($result->summary->finalQuizPassed);

        self::assertCount(2, $result->lessons);

        $byLesson = [];
        foreach ($result->lessons as $lesson) {
            $byLesson[$lesson->lessonId] = $lesson;
        }

        self::assertArrayHasKey($lessonA, $byLesson);
        self::assertArrayHasKey($lessonB, $byLesson);
        self::assertSame(LessonProgress::STATUS_COMPLETED, $byLesson[$lessonA]->status());
        self::assertSame(LessonProgress::STATUS_IN_PROGRESS, $byLesson[$lessonB]->status());
        self::assertSame(30.0, $byLesson[$lessonB]->progressPercent());
        self::assertSame(0, $byLesson[$lessonB]->secondsSpent());
    }
}
