<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeRequest;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Regrade: a first-class operation over the frozen manifest and the
 * append-only grade history.
 */
final class RegradeTest extends AssessmentIntegrationTestCase
{
    private int $courseId = 0;
    private int $quizId = 0;
    private int $questionId = 0;
    private int $attemptId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $this->courseId);
        $quiz = $this->createQuiz($this->courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        // The author keys "true"; the learner answers "false" and scores
        // nothing, which is the setup every regrade story starts from.
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'true_false', [
            'prompt' => 'Green tea is oxidised.',
            'correct' => true,
        ], 10.0);
        $this->questionId = self::qid($question);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        $this->attemptId = (int) $attempt->id;
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId, [$this->questionId => false]);
        $this->submitAttemptUseCase()->execute($this->learner(), $this->attemptId);

        $submitted = $this->attempts->byId($this->attemptId);
        self::assertNotNull($submitted);
        self::assertSame(0.0, $submitted->score);
    }

    public function testCorrectedKeyOnlyAppendsAndMovesTheScore(): void
    {
        $this->correctTheKey();

        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly, reason: 'The key was wrong.'),
        );

        self::assertSame(1, $outcome->attemptsExamined);
        self::assertSame(1, $outcome->attemptsChanged);
        self::assertSame(1, $outcome->gradesAppended);
        self::assertSame(0.0, $outcome->items[0]->scoreBefore);
        self::assertSame(10.0, $outcome->items[0]->scoreAfter);
        self::assertFalse($outcome->items[0]->passedBefore);
        self::assertTrue($outcome->items[0]->passedAfter);

        $attempt = $this->attempts->byId($this->attemptId);
        self::assertNotNull($attempt);
        self::assertSame(10.0, $attempt->score);
        self::assertSame(100.0, $attempt->percentage);
        self::assertTrue($attempt->passed);
    }

    public function testItAppendsAndNeverMutates(): void
    {
        $this->correctTheKey();
        $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly, reason: 'The key was wrong.'),
        );

        $history = $this->attempts->gradesFor($this->attemptId);

        self::assertCount(2, $history, 'The original mark stays readable forever.');
        self::assertSame(0.0, $history[0]->pointsAwarded);
        self::assertSame(1, $history[0]->version);
        self::assertSame(10.0, $history[1]->pointsAwarded);
        self::assertSame(2, $history[1]->version);
        // The ledger names the algorithm, the reason **and the actor**: a
        // mark change with no accountable person is the same class of gap
        // AERA/APA/NCME 4.19 closes for the algorithm, on the one operation
        // that changes marks a cohort has already seen.
        $audit = $history[1]->scoringParams['regrade'] ?? null;

        self::assertIsArray($audit);
        self::assertSame('corrected_key_only', $audit['policy']);
        self::assertSame('The key was wrong.', $audit['reason']);
        self::assertSame(self::GRADER_ID, $audit['actor_id']);
        self::assertArrayHasKey('at', $audit);
    }

    public function testBothKeysNeverReducesAScore(): void
    {
        // The learner was right under the original key. The author "corrects"
        // it the other way; both_keys keeps what the learner had.
        $this->saveCorrectAnswerAndResubmit();
        $this->correctTheKey(false);

        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::BothKeys),
        );

        self::assertSame(0, $outcome->attemptsChanged);

        $attempt = $this->attempts->byId($this->attemptId);
        self::assertNotNull($attempt);
        self::assertSame(10.0, $attempt->score);
    }

    public function testFullCreditGivesEveryoneTheItem(): void
    {
        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::FullCredit),
        );

        self::assertSame(1, $outcome->attemptsChanged);

        $attempt = $this->attempts->byId($this->attemptId);
        self::assertNotNull($attempt);
        self::assertSame(10.0, $attempt->score);
    }

    public function testADryRunComputesTheSameAnswerAndWritesNothing(): void
    {
        $this->correctTheKey();

        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly, dryRun: true),
        );

        self::assertTrue($outcome->dryRun);
        self::assertSame(1, $outcome->attemptsChanged);
        self::assertSame(0, $outcome->gradesAppended);
        self::assertSame(10.0, $outcome->items[0]->scoreAfter);

        self::assertCount(1, $this->attempts->gradesFor($this->attemptId));

        $attempt = $this->attempts->byId($this->attemptId);
        self::assertNotNull($attempt);
        self::assertSame(0.0, $attempt->score, 'A dry run moves nothing.');
    }

    public function testAMarkAPersonGaveIsLeftAloneAndSaidSo(): void
    {
        $this->correctTheKey();
        $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $this->attemptId,
            $this->questionId,
            4.0,
            'Half marks for the reasoning.',
        );

        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly),
        );

        self::assertSame(0, $outcome->gradesAppended);
        self::assertNotSame([], $outcome->warnings);

        $attempt = $this->attempts->byId($this->attemptId);
        self::assertNotNull($attempt);
        self::assertSame(4.0, $attempt->score, 'A rater\'s judgement is not derivable from a key.');
    }

    public function testRegradingOneAttemptIsTheSameOperation(): void
    {
        $this->correctTheKey();

        $outcome = $this->regradeAttemptUseCase()->execute(
            $this->grader(),
            $this->attemptId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly),
        );

        self::assertSame(1, $outcome->attemptsExamined);
        self::assertSame(1, $outcome->gradesAppended);
    }

    public function testAnAttemptStillInProgressCannotBeRegraded(): void
    {
        $this->env->database->update(
            'attempts',
            ['status' => Attempt::STATUS_IN_PROGRESS],
            ['id' => $this->attemptId],
        );

        $this->expectException(ConflictException::class);
        $this->regradeAttemptUseCase()->execute(
            $this->grader(),
            $this->attemptId,
            new RegradeRequest(RegradePolicy::FullCredit),
        );
    }

    public function testAQuestionThatIsNotPartOfTheQuizIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::FullCredit, [$this->questionId + 5000]),
        );
    }

    public function testARestrictedRegradeLeavesEveryOtherQuestionAlone(): void
    {
        $other = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'true_false', [
            'prompt' => 'Tea is a plant.',
            'correct' => true,
        ], 10.0);

        $outcome = $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $this->quizId,
            new RegradeRequest(RegradePolicy::FullCredit, [self::qid($other)]),
        );

        // The second question was published after the attempt started, so it
        // is not on the frozen manifest and nothing about the attempt moves.
        self::assertSame(0, $outcome->gradesAppended);
    }

    public function testALearnerCannotRegradeTheirOwnAttempt(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->regradeAttemptUseCase()->execute(
            $this->learner(),
            $this->attemptId,
            new RegradeRequest(RegradePolicy::FullCredit),
        );
    }

    public function testAGuestIsRefused(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->regradeAttemptUseCase()->execute(
            Principal::guest(),
            $this->attemptId,
            new RegradeRequest(RegradePolicy::FullCredit),
        );
    }

    /**
     * The author fixes the item. The question row moves; the attempt's frozen
     * manifest does not, which is what a regrade grades the stored response
     * against.
     */
    private function correctTheKey(bool $correct = false): void
    {
        $question = $this->questions->byId($this->questionId);
        self::assertInstanceOf(Question::class, $question);

        $this->updateQuestionUseCase()->execute(
            $this->teacher(),
            $this->questionId,
            ['prompt' => 'Green tea is oxidised.', 'correct' => $correct],
            10.0,
        );
    }

    private function saveCorrectAnswerAndResubmit(): void
    {
        $this->env->database->delete('attempt_grades', ['attempt_id' => $this->attemptId]);
        $this->env->database->update(
            'attempts',
            ['status' => Attempt::STATUS_IN_PROGRESS, 'submitted_at' => null],
            ['id' => $this->attemptId],
        );
        $this->saveAnswersUseCase()->execute($this->learner(), $this->attemptId, [$this->questionId => true]);
        $this->submitAttemptUseCase()->execute($this->learner(), $this->attemptId);
    }
}
