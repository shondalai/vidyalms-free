<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SaveAnswersTest extends AssessmentIntegrationTestCase
{
    private int $quizId;
    private int $questionId;
    private Attempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        \assert($quiz->id !== null);
        $this->quizId = $quiz->id;

        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            'true_false',
            ['prompt' => 'PHP is a language.', 'correct' => true],
        );
        \assert($question->id !== null);
        $this->questionId = $question->id;

        $this->attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
    }

    private function attemptId(): int
    {
        \assert($this->attempt->id !== null);

        return $this->attempt->id;
    }

    public function testOversizedAnswerIsRefusedBeforeAnyWrite(): void
    {
        foreach (['response' => 262145, 'ui_state' => 16385, 'raw_input' => 65537] as $field => $size) {
            $wire = ['response' => true, $field => $field === 'ui_state' ? ['text' => str_repeat('x', $size)] : str_repeat('x', $size)];
            try {
                $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => $wire]);
                self::fail('Oversized answer was accepted.');
            } catch (ValidationException) {
                self::assertSame(0, $this->env->database->count(Query::from('attempt_answers')));
                self::assertSame(0, $this->env->database->count(Query::from('attempt_answer_events')));
            }
        }
    }

    public function testAutosavesStayBoundedAndDoNotEraseProcessedTries(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => $i % 2 === 0]);
        }
        self::assertSame(1, $this->env->database->count(Query::from('attempt_answer_events')));
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => ['response' => true, 'processed' => true]]);
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => false]);
        self::assertSame(1, $this->env->database->count(Query::from('attempt_answer_events')->where('processed', '=', 1)));
        self::assertSame(1, $this->env->database->count(Query::from('attempt_answer_events')->where('processed', '=', 0)));
        self::assertSame(2, $this->attempts->tryCountFor($this->attemptId(), $this->questionId));
    }

    public function testAnswersAreSavedAndReplayedSavesStayOneRow(): void
    {
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => true]);
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => false]);

        self::assertSame(1, $this->env->database->count(Query::from('attempt_answers')));
        self::assertSame([$this->questionId => false], $this->attempts->answersFor($this->attemptId()));
    }

    public function testOnlyTheOwnerMaySaveAnswers(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->saveAnswersUseCase()->execute(
            Principal::user(self::OTHER_LEARNER_ID),
            $this->attemptId(),
            [$this->questionId => true],
        );
    }

    public function testGuestIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->saveAnswersUseCase()->execute(Principal::guest(), $this->attemptId(), [$this->questionId => true]);
    }

    public function testUnknownQuestionIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [999 => true]);
    }

    /**
     * The assertion this replaces was the defect, not the fix.
     *
     * It asserted that removing a question mid-attempt refuses the learner's
     * answer to it, which was a consequence of grading whatever the quiz
     * contained at submit time rather than what was served. The attempt now
     * carries a frozen manifest of the questions it actually served, and
     * grading reads that, so the removal cannot reach an attempt already in
     * flight. Refusing the save while still grading the question would throw
     * the learner's work away and then mark them zero for it.
     */
    public function testRemovingAQuestionDoesNotReachAnAttemptAlreadyInFlight(): void
    {
        $this->removeQuestionUseCase()->execute($this->teacher(), $this->questionId);

        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => true]);

        self::assertSame([$this->questionId => true], $this->attempts->answersFor($this->attemptId()));

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $this->attemptId());

        self::assertSame(1.0, $submitted->maxScore, 'the served question is still graded');
        self::assertSame(1.0, $submitted->score);
    }

    public function testAnswersCannotBeSavedAfterSubmission(): void
    {
        $this->submitAttemptUseCase()->execute($this->learner(), $this->attemptId());

        $this->expectException(ConflictException::class);
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => true]);
    }

    public function testUntimedQuizAcceptsSavesLongAfterTheStart(): void
    {
        $this->env->clock->advance('PT10H');

        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId(), [$this->questionId => true]);

        self::assertSame([$this->questionId => true], $this->attempts->answersFor($this->attemptId()));
    }

    public function testTimedQuizAcceptsSavesBeforeTheDeadline(): void
    {
        [$attemptId, $questionId] = $this->startTimedAttempt(600);

        $this->env->clock->advance('PT9M');
        $this->saveAnswersUseCase()->execute($this->learner(), $attemptId, [$questionId => true]);

        self::assertSame([$questionId => true], $this->attempts->answersFor($attemptId));
    }

    public function testTimedQuizAcceptsSavesWithinTheGrace(): void
    {
        [$attemptId, $questionId] = $this->startTimedAttempt(600);

        // 10:30 past the start: 30 seconds beyond the deadline, inside the
        // fixed 60 second grace absorbing clock skew and slow submits.
        $this->env->clock->advance('PT10M30S');
        $this->saveAnswersUseCase()->execute($this->learner(), $attemptId, [$questionId => true]);

        self::assertSame([$questionId => true], $this->attempts->answersFor($attemptId));
    }

    public function testTimedQuizRefusesSavesPastDeadlinePlusGraceAndStoresNothing(): void
    {
        [$attemptId, $questionId] = $this->startTimedAttempt(600);

        $this->env->clock->advance('PT11M1S');

        try {
            $this->saveAnswersUseCase()->execute($this->learner(), $attemptId, [$questionId => true]);
            self::fail('Expected a conflict for a save past deadline plus grace.');
        } catch (ConflictException $exception) {
            self::assertSame('The attempt time expired; answers can no longer be saved.', $exception->getMessage());
        }

        self::assertSame([], $this->attempts->answersFor($attemptId));
    }

    /**
     * The deadline belongs to the attempt, not to the quiz row.
     *
     * attempts.expires_at was written at start and hydrated, and then read by
     * nothing: every check recomputed started_at plus the quiz's current
     * time_limit_seconds. An instructor shortening the limit while somebody was
     * sitting the quiz therefore expired that learner's attempt underneath
     * them, retroactively, with their answers refused and nothing stored.
     */
    public function testShorteningTheQuizLimitDoesNotExpireAnAttemptAlreadyInFlight(): void
    {
        [$attemptId, $questionId, $quizId] = $this->startTimedAttempt(3600);

        $this->env->clock->advance('PT30M');

        // The instructor cuts the limit from an hour to five minutes. The
        // attempt in flight was served an hour and keeps it.
        $this->updateQuizUseCase()->execute($this->teacher(), $quizId, 'Timed quiz', timeLimitSeconds: 300);

        $this->saveAnswersUseCase()->execute($this->learner(), $attemptId, [$questionId => true]);

        self::assertSame(
            [$questionId => true],
            $this->attempts->answersFor($attemptId),
            'the answer is stored against the deadline the attempt was started with',
        );
    }

    /**
     * @return array{int, int, int} attempt id, question id and quiz id
     */
    private function startTimedAttempt(int $timeLimitSeconds): array
    {
        $courseId = $this->createCourse('course-timed');
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, timeLimitSeconds: $timeLimitSeconds);
        \assert($quiz->id !== null);

        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'true_false',
            ['prompt' => 'Clocks are honest.', 'correct' => true],
        );

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        \assert($attempt->id !== null);

        return [$attempt->id, self::qid($question), $quiz->id];
    }
}
