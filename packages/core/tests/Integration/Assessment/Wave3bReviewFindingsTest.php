<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeRequest;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Application\Assessment\ReportableQuizGrade;

/**
 * The wave 3b review findings that live in the free build, each one a case
 * that failed before the fix and passes after it.
 *
 * They are gathered rather than spread through the suites they touch because
 * every one of them is a behaviour the design already stated and the code did
 * not do: reading them together is how the next reader sees which rules are
 * load bearing.
 */
final class Wave3bReviewFindingsTest extends AssessmentIntegrationTestCase
{
    /* ── An attempt awaiting a human carries no percentage ───────────── */

    /**
     * A paper of one auto-marked question and one essay recorded 100
     * percent over a maximum of the auto-marked half alone, because a
     * NotScored result is excluded from both sums and the percentage was
     * written anyway. Every aggregation method then counted it as finished:
     * AttemptScore::isGraded() is "percentage is not null", so the filter
     * every method runs first was dead code and the error ran the other way.
     */
    public function testASubmittedAttemptAwaitingAHumanHasNoPercentage(): void
    {
        [$quizId, $autoId] = $this->quizWithAnEssayBeside();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$autoId => 'a']);
        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status);
        self::assertNull($submitted->percentage, 'nobody has marked the essay yet');
        self::assertNull($submitted->passed);

        $score = new AttemptScore(
            (int) $submitted->id,
            $submitted->attemptNumber,
            $submitted->score,
            $submitted->maxScore,
            $submitted->percentage,
            $submitted->passed,
        );

        self::assertFalse($score->isGraded(), 'an unmarked paper is not a result');
        self::assertSame([], ReportableQuizGrade::counted([$score], QuizPolicy::COUNTED_ALL));
    }

    /**
     * And the human's mark fills it in, so nothing is lost: the attempt
     * becomes a result the moment the last pending answer closes.
     */
    public function testTheHumansMarkGivesTheAttemptItsPercentage(): void
    {
        [$quizId, $autoId, $essayId] = $this->quizWithAnEssayBeside();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$autoId => 'a']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $graded = $this->gradeAnswerManuallyUseCase()
            ->execute($this->grader(), $attempt->id, $essayId, 5.0);

        self::assertSame(Attempt::STATUS_GRADED, $graded->status);
        self::assertNotNull($graded->percentage);
        self::assertSame(100.0, $graded->percentage);
    }

    /* ── attempts_counted filters attempts, not graded attempts ─────── */

    /**
     * "Count the first attempt" used to mean "the first graded attempt": an
     * attempt still waiting on a rater was skipped and the next one was
     * promoted into its place, so a learner's reported grade came from an
     * attempt the setting does not name.
     */
    public function testCountingTheFirstAttemptWaitsForItRatherThanPromotingTheNext(): void
    {
        $ungraded = new AttemptScore(1, 1, null, null, null, null);
        $graded = new AttemptScore(2, 2, 6.0, 10.0, 60.0, true);

        self::assertSame(
            [],
            ReportableQuizGrade::counted([$ungraded, $graded], QuizPolicy::COUNTED_FIRST),
            'the counted attempt has no grade yet, so there is no reportable grade',
        );

        self::assertSame(
            [],
            ReportableQuizGrade::counted([$graded, $ungraded], QuizPolicy::COUNTED_LAST),
            'and the same the other way round',
        );
    }

    /* ── The try penalty charges tries, not revisions ────────────────── */

    /**
     * A try is a response submitted for processing. The runner posts the
     * whole answer map on every autosave, so counting changed autosaves
     * charged a learner for changing their mind: a learner who answered and
     * revised four times scored 2 of 10 for a correct answer while one who
     * never revised paid nothing.
     */
    public function testRevisingAnAnswerIsNotATry(): void
    {
        [$quizId, $questionId] = $this->quizWithOneTenMarkChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $save = $this->saveAnswersUseCase();

        foreach (['b', 'a', 'b', 'a'] as $answer) {
            $save->execute($this->learner(), $attempt->id, [$questionId => $answer]);
        }

        self::assertSame(1, $this->attempts->tryCountFor($attempt->id, $questionId));

        // Unprocessed revisions coalesce and cannot grow the log without limit.
        self::assertSame(1, $this->env->database->count(
            Query::from('attempt_answer_events')
                ->where('attempt_id', '=', $attempt->id)
                ->where('question_id', '=', $questionId),
        ));
    }

    /* ── A regrade names its actor, and reproduces the submit path ──── */

    /**
     * A regrade used to recompute the item at full fraction with no
     * quiz-level rules applied, so a learner charged a try penalty at submit
     * was refunded it by an unrelated key correction, and the award moved,
     * so corrected_key_only appended a row even though no key had changed.
     */
    public function testARegradeAppliesTheSameQuizRulesTheSubmitPathApplied(): void
    {
        [$quizId, $questionId] = $this->quizWithOneTenMarkChoice(tryPenalty: 0.5);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        // A checked try, then the right answer: two tries under the rule,
        // so the mark is halved at submit.
        $save = $this->saveAnswersUseCase();
        $save->execute($this->learner(), $attempt->id, [
            $questionId => ['response' => 'b', 'processed' => true],
        ]);
        $save->execute($this->learner(), $attempt->id, [$questionId => 'a']);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);
        $atSubmit = $submitted->score;

        $this->regradeQuizUseCase()->execute(
            $this->grader(),
            $quizId,
            new RegradeRequest(RegradePolicy::CorrectedKeyOnly, reason: 'A routine recheck.'),
        );

        $after = $this->attempts->byId($attempt->id);
        self::assertNotNull($after);
        self::assertSame($atSubmit, $after->score, 'a regrade must not hand back a penalty the learner was charged');
    }

    /* ── The frozen deadline includes the null case ──────────────────── */

    /**
     * An attempt started on a quiz with neither a time limit nor a close
     * time froze expires_at null, and every later read recomputed it from
     * the live quiz: an author adding a time limit while that attempt was
     * open handed the learner a deadline already in the past.
     */
    public function testAddingATimeLimitDoesNotExpireAnAttemptAlreadyOpen(): void
    {
        [$quizId] = $this->quizWithOneTenMarkChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);
        self::assertNull($attempt->expiresAt);

        $stored = $this->attempts->byId($attempt->id);
        self::assertNotNull($stored);

        // The author adds a one minute limit long after the attempt began.
        $withLimit = $this->quizzes->byId($quizId);
        self::assertNotNull($withLimit);

        self::assertNull(
            AttemptDeadline::of($stored, 60, $withLimit->policy),
            'the deadline was resolved at start, and the answer was "none"',
        );
    }

    /* ── Linear navigation is not a whole-map free pass ──────────────── */

    /**
     * Enforcing only against changes meant the runner's own whole-map
     * autosave answered every screen at once: a learner who answered
     * everything in one request was never stopped, while one editing
     * normally was.
     */
    public function testALinearAttemptRefusesAScreenTheLearnerHasNotReached(): void
    {
        [$quizId, $first, $second] = $this->linearQuizWithTwoScreens();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $this->expectException(ConflictException::class);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $first => 'a',
            $second => 'a',
        ]);
    }

    /**
     * And a refusal writes nothing at all, rather than leaving the keys it
     * had already reached behind.
     */
    public function testARefusedLinearSaveIsAtomic(): void
    {
        [$quizId, $first, $second] = $this->linearQuizWithTwoScreens();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        try {
            $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
                $first => 'a',
                $second => 'a',
            ]);
        } catch (ConflictException) {
            // Expected.
        }

        self::assertSame([], $this->attempts->answersFor($attempt->id));
    }

    /* ── The settings form does not lock when a package goes ────────── */

    /**
     * The form deliberately keeps an unresolvable stored key selected and
     * sends it back, and refusing it turned every field on the screen
     * unsaveable: an author could not change the pass mark of a quiz whose
     * behaviour no longer resolved, and no copy told them why.
     */
    public function testAQuizWhoseBehaviourNoLongerResolvesCanStillBeEdited(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(behaviour: 'deferred_feedback'));
        self::assertNotNull($quiz->id);

        // The package that provided it has gone: the stored key is written
        // straight to the row, which is what an uninstall leaves behind.
        $this->env->database->update('quizzes', ['behaviour' => 'interactive_multi_try'], ['id' => $quiz->id]);

        $updated = $this->updateQuizUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'A new title',
            passMark: 60,
            policy: new QuizPolicy(behaviour: 'interactive_multi_try'),
        );

        self::assertSame('A new title', $updated->title);
        self::assertSame(60, $updated->passMark);
        self::assertSame('interactive_multi_try', $updated->policy->behaviour, 'the stored key is left where it was');
    }

    /**
     * Moving it to another key nobody provides is still refused, which is
     * the write-side half of the registry-driven settings form.
     */
    public function testMovingToAnUnresolvableBehaviourIsStillRefused(): void
    {
        $courseId = $this->createCourse();
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $this->expectException(ValidationException::class);
        $this->updateQuizUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'Checkpoint',
            policy: new QuizPolicy(behaviour: 'invented_by_nobody'),
        );
    }

    /* ── The policy snapshot carries a schema version ────────────────── */

    public function testTheFrozenPolicySnapshotCarriesItsOwnVersion(): void
    {
        [$quizId] = $this->quizWithOneTenMarkChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        self::assertSame(
            AttemptPolicySet::SCHEMA_VERSION,
            $attempt->policySnapshot[AttemptPolicySet::KEY_SCHEMA_VERSION] ?? null,
        );
    }

    /**
     * A snapshot written by a newer build is refused rather than read under
     * this build's meanings, which is the same gate the definition version
     * keeps for a definition.
     */
    public function testASnapshotFromANewerBuildIsRefusedRatherThanGuessedAt(): void
    {
        $this->expectException(\RuntimeException::class);

        AttemptPolicySet::fromArray([
            AttemptPolicySet::KEY_BEHAVIOUR => 'deferred_feedback',
            AttemptPolicySet::KEY_SCHEMA_VERSION => AttemptPolicySet::SCHEMA_VERSION + 1,
        ]);
    }

    /* ── Integrity events are capped ─────────────────────────────────── */

    /**
     * The context was capped and the row count was not, so a learner could
     * post signals in a loop for the length of their own attempt onto an
     * append-only table nobody prunes. Reaching the cap answers "suppressed"
     * so the client stops reporting rather than retrying.
     */
    public function testIntegritySignalsAreCappedPerKind(): void
    {
        [$quizId] = $this->quizWithOneTenMarkChoice();

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quizId);
        self::assertNotNull($attempt->id);

        $recorder = $this->recordIntegrityEventUseCase();
        $cap = \Shondalai\VidyaLMS\Core\Application\Assessment\RecordIntegrityEvent::MAX_EVENTS_PER_KIND;

        for ($i = 0; $i < $cap; ++$i) {
            self::assertTrue($recorder->execute($this->learner(), $attempt->id, 'window_blur')->recorded);
        }

        self::assertFalse(
            $recorder->execute($this->learner(), $attempt->id, 'window_blur')->recorded,
            'the cap answers suppressed rather than growing the table for ever',
        );
    }

    /* ── A composite records one grade row per part ──────────────────── */

    /**
     * An auto-graded composite wrote one roll-up row and nothing else, so
     * attempt_grades.part_id was never written by any automatic path and the
     * per-part item analysis input the design lists as a free-build
     * obligation was not recorded. A later release cannot backfill it: every
     * composite graded before this was permanently unanalysable per part.
     */
    public function testAnAutoGradedCompositeWritesOneRowPerPartBesideTheRollUp(): void
    {
        $this->questionTypes->register(new \Shondalai\VidyaLMS\Core\Tests\Support\TwoPartAutoGradedType());

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $questionId = self::qid($this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            \Shondalai\VidyaLMS\Core\Tests\Support\TwoPartAutoGradedType::KEY,
            ['prompt' => 'Two parts.'],
            10.0,
        ));

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$questionId => 'anything']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grades = $this->attempts->gradesFor($attempt->id);

        self::assertCount(3, $grades, 'the roll-up plus one row per part');

        $byPart = [];

        foreach ($grades as $grade) {
            $byPart[$grade->partId ?? ''] = $grade;
        }

        self::assertSame(['', 'a', 'b'], array_keys($byPart));
        self::assertNull($byPart['']->partId, 'the roll-up carries a null part id, as the design states');
        self::assertSame(7.0, $byPart['']->pointsAwarded);
        self::assertSame(4.0, $byPart['a']->pointsAwarded);
        self::assertSame(4.0, $byPart['a']->maxPoints);
        self::assertSame(3.0, $byPart['b']->pointsAwarded);
        self::assertSame(6.0, $byPart['b']->maxPoints);
    }

    /* ── Fixtures ───────────────────────────────────────────────────── */

    /**
     * @return array{int, int, int} quiz, the auto-marked question, the essay
     */
    private function quizWithAnEssayBeside(): array
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $auto = self::qid($this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick the first letter.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 5.0));

        // An empty accepted list is the author declaring that a human marks
        // it, which is the design's authored intent rather than a guess from
        // whether an answer is present.
        $essay = self::qid($this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Explain your reasoning.',
            'accepted' => [],
        ], 5.0));

        return [$quiz->id, $auto, $essay];
    }

    /**
     * @return array{int, int}
     */
    private function quizWithOneTenMarkChoice(float $tryPenalty = 0.0): array
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(tryPenalty: $tryPenalty));
        self::assertNotNull($quiz->id);

        $questionId = self::qid($this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick the first letter.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 10.0));

        return [$quiz->id, $questionId];
    }

    /**
     * @return array{int, int, int} quiz, the question on screen one, the one on screen two
     */
    private function linearQuizWithTwoScreens(): array
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            navigationMode: QuizPolicy::NAVIGATION_LINEAR,
        ));
        self::assertNotNull($quiz->id);

        $first = self::qid($this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'First.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 1.0));

        $second = self::qid($this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Second.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 1.0));

        return [$quiz->id, $first, $second];
    }
}
