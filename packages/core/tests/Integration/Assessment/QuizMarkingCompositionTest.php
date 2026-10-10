<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The quiz-level composition order over the whole attempt pipeline rather
 * than over a value object: the marking rule produces a fraction, the try
 * penalty subtracts in fraction space, the negative marking gate runs last,
 * and only then is the quiz total floored.
 *
 * The unit test beside this one proves the arithmetic. This proves the two
 * things the arithmetic cannot: that the numbers reaching it come from the
 * attempt's frozen snapshot rather than from the quiz row, and that the try
 * count reaching it is the count of tries actually recorded.
 */
final class QuizMarkingCompositionTest extends AssessmentIntegrationTestCase
{
    /**
     * A try is a changed answer in the append-only event history, and under
     * the free build's default behaviour it can never be charged for.
     *
     * Deferred feedback closes every item session at submit, so the learner
     * has exactly one try at each item however many times they revise it
     * before submitting. The behaviour pins the penalty to zero for exactly
     * that reason, and this is the test that a quiz carrying a stored
     * try_penalty cannot quietly charge a learner for correcting themselves
     * on a quiz that never marked anything mid-attempt.
     */
    public function testTheFreeDefaultBehaviourNeverChargesATryPenaltyForARevision(): void
    {
        $quizId = $this->quizWithPenalty(0.2);
        $questionId = self::qid($this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quizId,
            'single_choice',
            [
                'prompt' => 'Pick the first letter.',
                'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
                'correct' => 'a',
            ],
            10.0,
        ));

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $save = $this->saveAnswersUseCase();
        $save->execute($this->learner(), $attempt->id, [$questionId => 'b']);
        $save->execute($this->learner(), $attempt->id, [$questionId => 'a']);
        $save->execute($this->learner(), $attempt->id, [$questionId => 'b']);
        $save->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        // Four changed answers and not one try: a try is a response
        // submitted for processing, and the runner posts the whole answer
        // map on every autosave.
        self::assertSame(1, $this->attempts->tryCountFor($attempt->id, $questionId), 'revision is not a try');

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(10.0, $submitted->score, 'the free default pins the penalty off');
        self::assertSame(0.0, $attempt->policy()->tryPenalty());
    }

    /**
     * The autosave that reposts the same answer is not a try. The runner
     * posts the whole answer map on every autosave, so an event per posted
     * key would make the try count the number of autosaves.
     */
    public function testAnUnchangedRepostIsNotATry(): void
    {
        $quizId = $this->quizWithPenalty(0.2);
        $questionId = self::qid($this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quizId,
            'true_false',
            ['prompt' => 'Water is wet.', 'correct' => true],
            4.0,
        ));

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $save = $this->saveAnswersUseCase();

        foreach (range(1, 5) as $ignored) {
            $save->execute($this->learner(), $attempt->id, [$questionId => true]);
        }

        self::assertSame(1, $this->attempts->tryCountFor($attempt->id, $questionId));
    }

    /**
     * The gate is the quiz's promise to its learners, and it is applied to
     * the item and to the total separately: the item is floored at its own
     * policy floor, and the total is floored at zero unless the quiz allows
     * a negative one.
     *
     * The free build registers no negative marking rule at all, so a quiz
     * naming one has it substituted away at attempt start and the
     * substitution recorded. This is that path end to end: nothing on the
     * site can charge a penalty nobody can name.
     */
    public function testAQuizNamingAnUnregisteredRuleRecordsTheSubstitutionAndChargesNothing(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, lessonId: $this->createLesson($courseId), policy: new QuizPolicy(
            negativeMarkingPolicy: 'formula_scoring',
            allowQuizNegative: true,
        ));
        self::assertNotNull($quiz->id);

        $questionId = self::qid($this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'single_choice',
            [
                'prompt' => 'Pick the first letter.',
                'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
                'correct' => 'a',
            ],
            5.0,
        ));

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        $policy = $attempt->policy();
        self::assertFalse($policy->allowsNegativeMarking());
        self::assertNull($policy->negativeMarkingPolicy());
        self::assertSame(
            'formula_scoring',
            $policy->toArray()['negative_marking_substituted_from'] ?? null,
        );

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'b']);
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(0.0, $submitted->score, 'a wrong answer costs nothing when no rule resolved');

        $grade = $this->env->database->selectOne(
            Query::from('attempt_grades')->where('attempt_id', '=', $attempt->id),
        );
        self::assertNotNull($grade);
        self::assertSame(0.0, (float) $grade['points_awarded']);
    }

    /**
     * An unscored question is excluded from both sums, and a notscored
     * result is excluded by the same rule, so neither a survey item nor a
     * pending human grade can move the percentage the aggregation reads.
     */
    public function testAnUnscoredQuestionReachesNeitherSum(): void
    {
        $quizId = $this->quizWithPenalty(0.0);
        $add = $this->addQuestionUseCase();

        $scored = self::qid($add->execute($this->teacher(), $quizId, 'true_false', [
            'prompt' => 'Water is wet.',
            'correct' => true,
        ], 4.0));

        $opinion = $add->execute($this->teacher(), $quizId, 'single_choice', [
            'prompt' => 'How was the module?',
            'options' => [['key' => 'a', 'label' => 'Good'], ['key' => 'b', 'label' => 'Bad']],
            'correct' => 'a',
        ], 6.0, scored: false);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $scored => true,
            self::qid($opinion) => 'b',
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(4.0, $submitted->score);
        self::assertSame(4.0, $submitted->maxScore, 'the unscored item is in neither sum');
        self::assertSame(100.0, $submitted->percentage);
    }

    /**
     * The whole quiz-level set is frozen at attempt start, so an edit to the
     * quiz between start and submit cannot move the mark of an attempt
     * already in flight. This is the composition order's own precondition:
     * two attempts of one quiz may legitimately be marked under different
     * rules, and the attempt is what says which.
     */
    public function testTheSnapshotRatherThanTheQuizDecidesTheMark(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, lessonId: $this->createLesson($courseId), policy: new QuizPolicy());
        self::assertNotNull($quiz->id);

        $questionId = self::qid($this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'true_false',
            ['prompt' => 'Water is wet.', 'correct' => true],
            10.0,
        ));

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => true]);

        // The author turns on a penalty and a negative rule mid-attempt.
        $this->quizzes->save(new Quiz(
            id: $quiz->id,
            courseId: $quiz->courseId,
            lessonId: $quiz->lessonId,
            title: $quiz->title,
            description: $quiz->description,
            passMark: $quiz->passMark,
            maxAttempts: $quiz->maxAttempts,
            timeLimitSeconds: $quiz->timeLimitSeconds,
            isFinal: $quiz->isFinal,
            showResults: $quiz->showResults,
            policy: new QuizPolicy(tryPenalty: 1.0, allowQuizNegative: true),
        ));

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(10.0, $submitted->score, 'the attempt is marked under what it started with');
        self::assertSame(ScoreStatus::Scored->value, (string) ($this->env->database->selectOne(
            Query::from('attempt_grades')->where('attempt_id', '=', $attempt->id),
        )['score_status'] ?? ''));
    }

    private function quizWithPenalty(float $penalty): int
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz(
            $courseId,
            lessonId: $this->createLesson($courseId),
            policy: new QuizPolicy(tryPenalty: $penalty),
        );
        self::assertNotNull($quiz->id);

        return $quiz->id;
    }
}
