<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class GetAttemptForLearnerTest extends AssessmentIntegrationTestCase
{
    /**
     * @return array{int, int} quiz id and question id
     */
    private function quizWithSecretQuestion(string $showResults = Quiz::SHOW_RESULTS_AFTER_SUBMIT): array
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50, showResults: $showResults);
        \assert($quiz->id !== null);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick wisely.',
            'options' => [['key' => 'a', 'label' => 'First'], ['key' => 'b', 'label' => 'Second']],
            'correct' => 'a',
            'feedback' => 'The first one wins.',
        ], 2.0);

        return [$quiz->id, self::qid($question)];
    }

    public function testInProgressViewHidesAllCorrectness(): void
    {
        [$quizId, $questionId] = $this->quizWithSecretQuestion();
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'b']);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_IN_PROGRESS, $view['attempt']['status']);
        self::assertNull($view['attempt']['score']);
        self::assertCount(1, $view['questions']);
        self::assertSame('b', $view['questions'][0]['answer']);
        self::assertArrayNotHasKey('points_awarded', $view['questions'][0]);

        // The served questions carry no correctness of any kind, and the
        // secret itself is absent from the whole payload.
        //
        // The word check runs over the questions rather than over the whole
        // view because the attempt carries the frozen policy the runner has
        // to obey, whose own vocabulary contains "feedback" while carrying
        // no correctness at all. Each served question now carries that
        // policy's item session control too, for the same reason: the runner
        // gates a multi-try panel on it per item. So the feedback check is
        // the item's own key rather than the substring, and the secret
        // string over the whole payload is the stronger check anyway.
        $questions = json_encode($view['questions'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('correct', $questions);
        self::assertStringNotContainsString('accepted', $questions);
        self::assertArrayNotHasKey('feedback', $view['questions'][0]);
        self::assertStringNotContainsString(
            'The first one wins.',
            json_encode($view, JSON_THROW_ON_ERROR),
        );
    }

    public function testAfterSubmitViewRevealsAwardedPointsAndFeedback(): void
    {
        [$quizId, $questionId] = $this->quizWithSecretQuestion();
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_GRADED, $view['attempt']['status']);
        self::assertSame(2.0, $view['attempt']['score']);
        self::assertSame(100.0, $view['attempt']['percentage']);
        self::assertTrue($view['attempt']['passed']);
        self::assertSame(2.0, $view['questions'][0]['points_awarded']);
        self::assertSame('The first one wins.', $view['questions'][0]['feedback']);

        // The raw definition still never crosses: no correct answer in the presented shape.
        $json = json_encode($view['questions'][0]['definition'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('correct', $json);
    }

    public function testShowResultsNeverKeepsScoresHiddenAfterSubmit(): void
    {
        [$quizId, $questionId] = $this->quizWithSecretQuestion(Quiz::SHOW_RESULTS_NEVER);
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_GRADED, $view['attempt']['status']);
        self::assertNull($view['attempt']['score']);
        self::assertNull($view['attempt']['passed']);
        self::assertArrayNotHasKey('points_awarded', $view['questions'][0]);

        // The item's own feedback key, rather than the substring: every
        // served question carries the frozen item session control, whose
        // show_feedback flag is a rule the runner obeys and not a mark.
        self::assertArrayNotHasKey('feedback', $view['questions'][0]);
        self::assertStringNotContainsString(
            'The first one wins.',
            json_encode($view, JSON_THROW_ON_ERROR),
        );
    }

    public function testOnlyTheOwnerMayView(): void
    {
        [$quizId] = $this->quizWithSecretQuestion();
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);

        $this->expectException(NotAuthorizedException::class);
        $this->getAttemptForLearnerUseCase()->execute(Principal::user(self::OTHER_LEARNER_ID), $attempt->id);
    }

    public function testGuestIsDenied(): void
    {
        [$quizId] = $this->quizWithSecretQuestion();
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);

        $this->expectException(NotAuthorizedException::class);
        $this->getAttemptForLearnerUseCase()->execute(Principal::guest(), $attempt->id);
    }

    public function testUnknownAttemptIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->getAttemptForLearnerUseCase()->execute($this->learner(), 999);
    }

    public function testTimedAttemptViewCarriesTheServerDeadline(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, timeLimitSeconds: 600);
        \assert($quiz->id !== null);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        \assert($attempt->id !== null);
        $startedAt = $this->env->clock->now();

        $this->env->clock->advance('PT2M');
        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        // The deadline derives from started_at, not from "now".
        self::assertSame(
            $startedAt->modify('+600 seconds')->format('Y-m-d H:i:s'),
            $view['attempt']['expires_at'],
        );
    }

    public function testUntimedAttemptViewHasNullExpiresAt(): void
    {
        [$quizId] = $this->quizWithSecretQuestion();
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        \assert($attempt->id !== null);

        $view = $this->getAttemptForLearnerUseCase()->execute($this->learner(), $attempt->id);

        self::assertArrayHasKey('expires_at', $view['attempt']);
        self::assertNull($view['attempt']['expires_at']);
    }
}
