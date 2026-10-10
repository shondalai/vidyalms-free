<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Tests\Support\LengthPenalisedEssayType;

final class GradeAnswerManuallyTest extends AssessmentIntegrationTestCase
{
    private int $quizId;
    private Question $autoQuestion;
    private Question $manualQuestion;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        \assert($quiz->id !== null);
        $this->quizId = $quiz->id;

        $this->autoQuestion = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'true_false', [
            'prompt' => 'Auto gradable.',
            'correct' => true,
        ], 4.0);

        $this->manualQuestion = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'short_answer', [
            'prompt' => 'Needs a human.',
            'accepted' => [],
        ], 6.0);
    }

    private function submittedAttemptId(): int
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        \assert($attempt->id !== null);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($this->autoQuestion) => true,
            self::qid($this->manualQuestion) => 'Because of reasons.',
        ]);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        return $attempt->id;
    }

    /**
     * @return list<AttemptGraded>
     */
    private function gradedEvents(): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (object $event): bool => $event instanceof AttemptGraded,
        ));
    }

    public function testManualGradeCompletesGradingAndEmitsOnce(): void
    {
        $attemptId = $this->submittedAttemptId();

        $graded = $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attemptId,
            $this->manualQuestion->id ?? 0,
            4.5,
            'Good reasoning.',
        );

        self::assertSame(Attempt::STATUS_GRADED, $graded->status);
        self::assertSame(8.5, $graded->score);
        self::assertSame(10.0, $graded->maxScore);
        self::assertSame(85.0, $graded->percentage);
        self::assertTrue($graded->passed);

        $events = $this->gradedEvents();
        self::assertCount(1, $events);
        self::assertSame($attemptId, $events[0]->attemptId);
        self::assertSame(85.0, $events[0]->percentage);
        self::assertTrue($events[0]->passed);

        $grades = $this->attempts->gradesFor($attemptId);
        self::assertCount(3, $grades);
        self::assertSame(self::GRADER_ID, $grades[2]->graderId);
        self::assertSame('Good reasoning.', $grades[2]->feedback);
    }

    /**
     * A type may reduce a human's mark, and the design names exactly one rule
     * that does: an extended response outside its word limit under `penalise`
     * multiplies the grade in proportion to the overage. The rule lives in
     * the package that ships the type, so the core asks for a multiplier and
     * knows nothing about words.
     *
     * The grade row keeps both numbers: raw_score is what the marker typed
     * and points_awarded is what the learner gets, so a mark reduced by a
     * length rule can be explained from the row rather than recomputed from a
     * definition that may have been edited since.
     */
    public function testATypeMayReduceTheHumansMarkAndTheRowKeepsBothNumbers(): void
    {
        $this->questionTypes->register(new LengthPenalisedEssayType());
        $essay = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $this->quizId,
            LengthPenalisedEssayType::KEY,
            ['prompt' => 'Explain.', 'max_words' => 3],
            10.0,
        );

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        \assert($attempt->id !== null);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($this->autoQuestion) => true,
            self::qid($this->manualQuestion) => 'Because of reasons.',
            self::qid($essay) => 'Far more words than three',
        ]);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attempt->id, $this->manualQuestion->id ?? 0, 6.0);
        $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attempt->id, $essay->id ?? 0, 8.0);

        $row = null;

        foreach ($this->attempts->gradesFor($attempt->id) as $grade) {
            if ($grade->questionId === ($essay->id ?? 0)) {
                $row = $grade;
            }
        }

        self::assertNotNull($row);
        self::assertSame(8.0, $row->rawScore, 'what the marker typed');
        self::assertSame(4.0, $row->pointsAwarded, 'what the learner gets after the type deducted');
    }

    /** A type that declares no deduction is graded exactly as it was. */
    public function testATypeThatDeclaresNoDeductionKeepsTheMarkTheGraderTyped(): void
    {
        $attemptId = $this->submittedAttemptId();

        $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attemptId,
            $this->manualQuestion->id ?? 0,
            4.5,
        );

        $grades = $this->attempts->gradesFor($attemptId);
        $last = $grades[\count($grades) - 1];

        self::assertSame(4.5, $last->pointsAwarded);
        self::assertSame(4.5, $last->rawScore);
    }

    public function testAwardIsCappedAtTheQuestionPoints(): void
    {
        $attemptId = $this->submittedAttemptId();

        $graded = $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attemptId,
            $this->manualQuestion->id ?? 0,
            50.0,
        );

        self::assertSame(10.0, $graded->score);
        self::assertSame(100.0, $graded->percentage);
    }

    public function testNegativeAwardIsFlooredAtZero(): void
    {
        $attemptId = $this->submittedAttemptId();

        $graded = $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attemptId,
            $this->manualQuestion->id ?? 0,
            -3.0,
        );

        self::assertSame(4.0, $graded->score);
        self::assertFalse($graded->passed);
    }

    public function testAttemptStaysSubmittedWhileManualQuestionsRemain(): void
    {
        $attemptId = $this->submittedAttemptId();

        // Overriding the auto graded question does not finish manual grading.
        $stillSubmitted = $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attemptId,
            $this->autoQuestion->id ?? 0,
            2.0,
        );

        self::assertSame(Attempt::STATUS_SUBMITTED, $stillSubmitted->status);
        self::assertNull($stillSubmitted->passed);
        self::assertCount(0, $this->gradedEvents());
    }

    public function testRegradeAddsRowsAndRecomputesFromTheLatestPerQuestion(): void
    {
        $attemptId = $this->submittedAttemptId();

        $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attemptId, $this->manualQuestion->id ?? 0, 6.0);
        $regraded = $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attemptId, $this->manualQuestion->id ?? 0, 1.0);

        // History: 2 automatic rows, 2 manual rows, nothing rewritten.
        $grades = $this->attempts->gradesFor($attemptId);
        self::assertCount(4, $grades);
        self::assertSame(6.0, $grades[2]->pointsAwarded);
        self::assertSame(1.0, $grades[3]->pointsAwarded);

        // Totals follow the latest row per question: 4 + 1 of 10.
        self::assertSame(5.0, $regraded->score);
        self::assertSame(50.0, $regraded->percentage);
        self::assertTrue($regraded->passed);

        // Regrading emits AttemptGraded again for each completion.
        self::assertCount(2, $this->gradedEvents());
    }

    public function testGradingRequiresTheGradeCapability(): void
    {
        $attemptId = $this->submittedAttemptId();

        $this->expectException(NotAuthorizedException::class);
        $this->gradeAnswerManuallyUseCase()->execute($this->learner(), $attemptId, $this->manualQuestion->id ?? 0, 5.0);
    }

    public function testInProgressAttemptCannotBeGraded(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        \assert($attempt->id !== null);

        $this->expectException(ConflictException::class);
        $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attempt->id, $this->manualQuestion->id ?? 0, 5.0);
    }

    public function testQuestionOutsideTheQuizIsRejected(): void
    {
        $attemptId = $this->submittedAttemptId();

        $otherQuiz = $this->createQuiz($this->createCourse('course-b'));
        \assert($otherQuiz->id !== null);
        $foreign = $this->addQuestionUseCase()->execute($this->teacher(), $otherQuiz->id, 'true_false', [
            'prompt' => 'Elsewhere.',
            'correct' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->gradeAnswerManuallyUseCase()->execute($this->grader(), $attemptId, $foreign->id ?? 0, 1.0);
    }
}
