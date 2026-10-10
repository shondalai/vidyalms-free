<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\ManualGradingStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Tests\Support\TwoPartEssayType;

/**
 * Manual grading marks against the frozen attempt, and per part.
 *
 * Two defects met here. The use case took the maximum, the clamp and the
 * state fraction from the LIVE questions row while SubmitAttempt used the
 * frozen points from the manifest, so reweighting a question after submission
 * moved an already-submitted attempt's totals and its pass flag: a learner who
 * scored 1 of 2 read 21 of 21 at 100 percent and passed. And the completion
 * check keyed on the question alone, so the first manual row closed a
 * multi-part item and the second part was never graded.
 */
final class ManualGradingFrozenTest extends AssessmentIntegrationTestCase
{
    private int $quizId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;
    }

    public function testReweightingAQuestionAfterSubmissionDoesNotMoveTheAttemptTotals(): void
    {
        $essayId = $this->addEssay(1.0);
        $autoId = $this->addChoice(1.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$autoId => 'a']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        // The instructor reweights the essay from 1 to 20 after submission.
        $this->updateQuestionUseCase()->execute($this->teacher(), $essayId, ['prompt' => 'Explain.', 'accepted' => []], 20.0);

        $graded = $this->gradeAnswerManuallyUseCase()
            ->execute($this->grader(), $attempt->id, $essayId, 20.0, 'Full marks.');

        self::assertSame(Attempt::STATUS_GRADED, $graded->status);
        self::assertSame(2.0, $graded->maxScore, 'the attempt keeps the maximum it was served');
        self::assertSame(2.0, $graded->score, 'and the grader could award at most the frozen part maximum');
        self::assertSame(100.0, $graded->percentage);

        $grades = $this->attempts->gradesFor($attempt->id);
        $manual = array_values(array_filter($grades, static fn ($grade): bool => $grade->isManual()));

        self::assertCount(1, $manual);
        self::assertSame(1.0, $manual[0]->maxPoints, 'the row records the frozen maximum, not the live one');
        self::assertSame(1.0, $manual[0]->pointsAwarded, 'the award is clamped to it');
    }

    public function testAMultiPartItemNeedsOneManualRowPerPendingPart(): void
    {
        $this->questionTypes->register(new TwoPartEssayType());

        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            TwoPartEssayType::KEY,
            ['prompt' => 'Two parts.'],
            4.0,
        );
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $status = $this->manualGradingStatus();
        self::assertSame(2, $status->pendingCountFor($this->reload($attempt->id)), 'two parts, two pending rows');

        $afterFirst = $this->gradeAnswerManuallyUseCase()
            ->execute($this->grader(), $attempt->id, $question->id, 1.0, null, 'a');

        self::assertSame(
            Attempt::STATUS_SUBMITTED,
            $afterFirst->status,
            'the first manual row must not close a multi-part item',
        );
        self::assertSame(1, $status->pendingCountFor($this->reload($attempt->id)));

        $afterSecond = $this->gradeAnswerManuallyUseCase()
            ->execute($this->grader(), $attempt->id, $question->id, 99.0, null, 'b');

        self::assertSame(Attempt::STATUS_GRADED, $afterSecond->status);
        self::assertSame(0, $status->pendingCountFor($this->reload($attempt->id)));

        $manual = array_values(array_filter(
            $this->attempts->gradesFor($attempt->id),
            static fn ($grade): bool => $grade->isManual(),
        ));

        self::assertCount(2, $manual);
        self::assertSame(['a', 'b'], array_map(static fn ($grade): ?string => $grade->partId, $manual));
        // Weights 1 and 3 over 4 points: the parts are worth 1 and 3, and the
        // clamp is against the part rather than the item.
        self::assertSame(1.0, $manual[0]->maxPoints);
        self::assertSame(3.0, $manual[1]->maxPoints);
        self::assertSame(3.0, $manual[1]->pointsAwarded, 'an over-award is clamped to the part maximum');
    }

    public function testAPartIdTheTypeDoesNotDeclareIsRefused(): void
    {
        $this->questionTypes->register(new TwoPartEssayType());

        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            TwoPartEssayType::KEY,
            ['prompt' => 'Two parts.'],
            4.0,
        );
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::class);
        $this->gradeAnswerManuallyUseCase()
            ->execute($this->grader(), $attempt->id, $question->id, 1.0, null, 'nope');
    }

    private function addEssay(float $points): int
    {
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'short_answer', [
            'prompt' => 'Explain.',
            'accepted' => [],
        ], $points);

        self::assertNotNull($question->id);

        return $question->id;
    }

    private function addChoice(float $points): int
    {
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'single_choice', [
            'prompt' => 'Pick.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], $points);

        self::assertNotNull($question->id);

        return $question->id;
    }

    private function manualGradingStatus(): ManualGradingStatus
    {
        return new ManualGradingStatus($this->attempts, $this->quizzes, $this->manifest, $this->typeResolver);
    }

    private function reload(int $attemptId): Attempt
    {
        $attempt = $this->attempts->byId($attemptId);
        self::assertNotNull($attempt);

        return $attempt;
    }
}
