<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class CreateQuizTest extends AssessmentIntegrationTestCase
{
    public function testTeacherCreatesAQuiz(): void
    {
        $courseId = $this->createCourse();

        $quiz = $this->createQuizUseCase()->execute(
            $this->teacher(),
            $courseId,
            'Final exam',
            passMark: 70,
            maxAttempts: 3,
            isFinal: true,
        );

        self::assertNotNull($quiz->id);
        self::assertSame($courseId, $quiz->courseId);

        $row = $this->env->database->selectOne(Query::from('quizzes')->where('id', '=', $quiz->id));
        self::assertNotNull($row);
        self::assertSame('Final exam', $row['title']);
        self::assertSame(70, (int) $row['pass_mark']);
        self::assertSame(1, (int) $row['is_final']);
        self::assertSame($this->now(), $row['created_at']);
    }

    public function testGuestAndNonTeacherAreDenied(): void
    {
        $courseId = $this->createCourse();

        try {
            $this->createQuizUseCase()->execute(Principal::guest(), $courseId, 'Nope');
            self::fail('Guests must not create quizzes.');
        } catch (NotAuthorizedException) {
        }

        $this->expectException(NotAuthorizedException::class);
        $this->createQuizUseCase()->execute($this->learner(), $courseId, 'Nope');
    }

    public function testMissingCourseIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->createQuizUseCase()->execute($this->teacher(), 999, 'Orphan');
    }

    public function testPassMarkMustBeAtMostOneHundred(): void
    {
        $courseId = $this->createCourse();

        $this->expectException(ValidationException::class);
        $this->createQuizUseCase()->execute($this->teacher(), $courseId, 'Bad', passMark: 101);
    }

    public function testLessonMustBelongToTheCourse(): void
    {
        $courseId = $this->createCourse();
        $otherCourseId = $this->createCourse('course-b');
        $foreignLessonId = $this->createLesson($otherCourseId);

        $this->expectException(ValidationException::class);
        $this->createQuizUseCase()->execute($this->teacher(), $courseId, 'Bad', lessonId: $foreignLessonId);
    }

    public function testLessonMustBeAQuizLesson(): void
    {
        $courseId = $this->createCourse();
        $videoLessonId = $this->createLesson($courseId, type: 'video');

        $this->expectException(ValidationException::class);
        $this->createQuizUseCase()->execute($this->teacher(), $courseId, 'Bad', lessonId: $videoLessonId);
    }

    public function testQuizLessonOfTheCourseIsAccepted(): void
    {
        $courseId = $this->createCourse();
        $lessonId = $this->createLesson($courseId);

        $quiz = $this->createQuizUseCase()->execute($this->teacher(), $courseId, 'Attached', lessonId: $lessonId);

        self::assertSame($lessonId, $quiz->lessonId);
    }
}
