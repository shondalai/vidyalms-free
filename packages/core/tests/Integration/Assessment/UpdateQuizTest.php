<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class UpdateQuizTest extends AssessmentIntegrationTestCase
{
    public function testTeacherUpdatesEveryField(): void
    {
        $courseId = $this->createCourse();
        $lessonId = $this->createLesson($courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $updated = $this->updateQuizUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'Renamed',
            lessonId: $lessonId,
            description: 'Now with a description.',
            passMark: 80,
            maxAttempts: 2,
            timeLimitSeconds: 600,
            isFinal: true,
            showResults: Quiz::SHOW_RESULTS_NEVER,
        );

        self::assertSame('Renamed', $updated->title);

        $reloaded = $this->quizzes->byId($quiz->id);
        self::assertNotNull($reloaded);
        self::assertSame(80, $reloaded->passMark);
        self::assertSame($lessonId, $reloaded->lessonId);
        self::assertSame(2, $reloaded->maxAttempts);
        self::assertSame(600, $reloaded->timeLimitSeconds);
        self::assertTrue($reloaded->isFinal);
        self::assertSame(Quiz::SHOW_RESULTS_NEVER, $reloaded->showResults);
    }

    public function testNonTeacherIsDenied(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(NotAuthorizedException::class);
        $this->updateQuizUseCase()->execute($this->learner(), $quiz->id, 'Nope');
    }

    public function testUnknownQuizIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->updateQuizUseCase()->execute($this->teacher(), 999, 'Ghost');
    }

    public function testLessonOfAnotherCourseIsRejected(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);
        $foreignLessonId = $this->createLesson($this->createCourse('course-b'));

        $this->expectException(ValidationException::class);
        $this->updateQuizUseCase()->execute($this->teacher(), $quiz->id, 'Bad', lessonId: $foreignLessonId);
    }

    public function testUnknownShowResultsIsRejected(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(ValidationException::class);
        $this->updateQuizUseCase()->execute($this->teacher(), $quiz->id, 'Bad', showResults: 'sometimes');
    }
}
