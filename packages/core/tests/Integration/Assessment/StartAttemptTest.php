<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class StartAttemptTest extends AssessmentIntegrationTestCase
{
    public function testUnscheduledUnattachedQuizIsHiddenUntilExplicitlyOpened(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);
        $this->env->database->update('quizzes', ['opens_at' => null], ['id' => $quiz->id]);
        try {
            $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
            self::fail('An unfinished quiz became available without an opening date.');
        } catch (EntityNotFoundException) {
            self::assertSame(0, $this->env->database->count(Query::from('attempts')));
        }
        $this->env->database->update('quizzes', ['opens_at' => $this->now()], ['id' => $quiz->id]);
        self::assertSame(Attempt::STATUS_IN_PROGRESS, $this->startAttemptUseCase()->execute($this->learner(), $quiz->id)->status);
    }

    public function testEnrolledLearnerStartsAnAttempt(): void
    {
        $courseId = $this->createCourse();
        $enrolmentId = $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        self::assertNotNull($attempt->id);
        self::assertSame(1, $attempt->attemptNumber);
        self::assertSame($enrolmentId, $attempt->enrolmentId);
        self::assertSame(Attempt::STATUS_IN_PROGRESS, $attempt->status);
        self::assertSame($this->now(), $attempt->startedAt->format('Y-m-d H:i:s'));
    }

    public function testGuestIsDenied(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(NotAuthorizedException::class);
        $this->startAttemptUseCase()->execute(Principal::guest(), $quiz->id);
    }

    public function testUnknownQuizIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->startAttemptUseCase()->execute($this->learner(), 999);
    }

    public function testLearnerWithoutEnrolmentIsDenied(): void
    {
        $quiz = $this->createQuiz($this->createCourse());
        self::assertNotNull($quiz->id);

        $this->expectException(NotAuthorizedException::class);
        $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
    }

    public function testSuspendedEnrolmentIsDenied(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId, status: 'suspended');
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $this->expectException(NotAuthorizedException::class);
        $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
    }

    public function testStartingAgainReturnsTheOpenAttemptUnchanged(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $first = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $this->env->clock->advance('PT5M');
        $second = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->startedAt->format('Y-m-d H:i:s'), $second->startedAt->format('Y-m-d H:i:s'));
        self::assertSame(1, $this->env->database->count(Query::from('attempts')));
    }

    public function testAttemptLimitIsEnforced(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, maxAttempts: 1);
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $this->expectException(ConflictException::class);
        $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
    }

    public function testAttemptNumbersIncrementAcrossSubmissions(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, maxAttempts: 3);
        self::assertNotNull($quiz->id);

        $first = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($first->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $first->id);

        $second = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        self::assertSame(2, $second->attemptNumber);
    }

    public function testStartPayloadCarriesTheServerDeadlineOnTimedQuizzes(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, timeLimitSeconds: 600);
        self::assertNotNull($quiz->id);

        $api = (new CoreKernel($this->env->ports))->apiKernel();
        $response = $api->handle(new ApiRequest('POST', '/quizzes/' . $quiz->id . '/attempts', $this->learner()));

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertSame($this->now(), $response->data['started_at']);

        $expected = $this->env->clock->now()->modify('+600 seconds')->format('Y-m-d H:i:s');
        self::assertSame($expected, $response->data['expires_at']);
    }

    public function testStartPayloadHasNullExpiresAtOnUntimedQuizzes(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $api = (new CoreKernel($this->env->ports))->apiKernel();
        $response = $api->handle(new ApiRequest('POST', '/quizzes/' . $quiz->id . '/attempts', $this->learner()));

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('expires_at', $response->data);
        self::assertNull($response->data['expires_at']);
    }
}
