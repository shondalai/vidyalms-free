<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptTotals;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Rubric\ResolvedRubric;
use Shondalai\VidyaLMS\Core\Registry\Rubric\RubricProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry;

/**
 * Adds one manual grade row for one question of a submitted attempt. Grade
 * history is append-only: a re-grade adds another row and totals are always
 * recomputed from the latest row per question. Once every question that
 * needs a human has a manual grade row, the attempt becomes "graded", the
 * pass flag is settled and AttemptGraded is emitted. Grading a question
 * again on an already graded attempt re-opens nothing: it recomputes and
 * emits AttemptGraded with a later timestamp.
 *
 * Which questions need a human is read from the authored intent through the
 * type resolution, never from whether an answer is present, and an
 * unresolvable type counts as needing one. That is the explicit route out for
 * state already in flight: without it a submitted attempt with a pending
 * essay whose package has been removed stays submitted with a null pass flag
 * forever. A grader may close such an item at zero or at full marks, the
 * audit row names the missing package, and completion then treats it as
 * done.
 */
final class GradeAnswerManually
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuestionRepository $questions,
        private readonly AttemptRepository $attempts,
        private readonly AttemptManifest $manifest,
        private readonly QuestionTypes $types,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        /**
         * Rubrics, when a package provides them. Optional and last so an
         * adapter built before the seam existed keeps constructing this use
         * case, and so a site with no provider marks on points exactly as it
         * always did.
         */
        private readonly ?RubricRegistry $rubrics = null,
    ) {
    }

    /**
     * The rubric a question is marked against, refused when the request
     * carries per-criterion marks and this site cannot record them.
     *
     * @return array{RubricProviderInterface, ResolvedRubric}
     */
    private function rubricFor(int $questionId): array
    {
        $resolved = $this->rubrics?->forQuestion($questionId);

        if ($resolved === null) {
            throw ValidationException::forField(
                'criteria',
                'This answer is not marked against a rubric on this site, so give a mark instead.',
            );
        }

        return $resolved;
    }

    /**
     * @param array{RubricProviderInterface, ResolvedRubric} $resolved
     * @param array<int, int>                                $criteria
     */
    private function rubricMark(array $resolved, array $criteria, float $maximum): float
    {
        [$provider, $rubric] = $resolved;
        $points = $provider->pointsFor($rubric, $criteria, $maximum);

        // Null is pending, never zero: a partly marked rubric must not close
        // the item, because an unmarked criterion would read as a wrong
        // answer and settle the attempt's pass flag on it.
        if ($points === null) {
            throw ValidationException::forField(
                'criteria',
                'Every criterion of the rubric needs a level before the mark can be recorded.',
            );
        }

        return max(0.0, min($points, $maximum));
    }

    /**
     * @param array<int, int> $criteria
     */
    private function requirePoints(?float $pointsAwarded, array $criteria): float
    {
        if ($pointsAwarded !== null) {
            return $pointsAwarded;
        }

        throw ValidationException::forField(
            'points_awarded',
            $criteria === []
                ? 'Give a mark, or the levels of the rubric this answer is marked against.'
                : 'This answer is not marked against a rubric on this site, so give a mark instead.',
        );
    }

    /**
     * @param list<AttemptGrade> $grades
     */
    private function latestGradeId(array $grades, int $questionId, ?string $partId): int
    {
        $id = 0;

        foreach ($grades as $grade) {
            if ($grade->questionId === $questionId && $grade->partId === $partId && $grade->id !== null) {
                $id = max($id, $grade->id);
            }
        }

        return $id;
    }

    public function currentForGrader(Principal $principal, int $attemptId): Attempt
    {
        $this->access->requireSignedIn($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);
        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE);

        return $attempt;
    }

    /**
     * @param array<int, int>    $criteria the level selected per criterion,
     *                                     when the answer is marked against a
     *                                     rubric rather than on points
     * @param array<int, string> $comments the rater's per-criterion comment
     */
    public function execute(
        Principal $principal,
        int $attemptId,
        int $questionId,
        ?float $pointsAwarded = null,
        ?string $feedback = null,
        ?string $partId = null,
        array $criteria = [],
        array $comments = [],
    ): Attempt {
        $attempt = $this->currentForGrader($principal, $attemptId);
        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        if (!$attempt->isSubmitted()) {
            throw new ConflictException('Only a submitted attempt can be graded.');
        }

        // The live row answers one question only: is this question part of
        // this attempt's quiz at all. Everything the mark is computed from
        // comes off the frozen manifest, because reweighting a question after
        // submission must not move an already submitted attempt's totals.
        $question = $this->questions->byId($questionId);

        if ($question === null || $question->quizId !== $attempt->quizId) {
            throw ValidationException::forField('question_id', 'The question is not part of this attempt.');
        }

        $served = $this->servedQuestion($attempt, $questionId)
            ?? throw ValidationException::forField('question_id', 'The question is not part of this attempt.');

        $resolved = $this->types->forServed($served);
        $maximum = $resolved->partMaximum($served->definition, $served->points, $partId);

        $now = $this->clock->now();

        // **Exactly one of points and criteria**, which is what the contract
        // says and what the shipped gradebook queue already sends. A site
        // with no rubric provider refuses the criteria rather than dropping
        // them: accepting a per-criterion mark nobody can record would lose
        // the rater's judgement while telling them it was saved.
        $resolvedRubric = $criteria === [] ? null : $this->rubricFor($questionId);
        $marked = $resolvedRubric === null
            ? round(max(0.0, min($this->requirePoints($pointsAwarded, $criteria), $maximum)), 2)
            : round($this->rubricMark($resolvedRubric, $criteria, $maximum), 2);

        /*
         * The one deduction the design gives a human's mark: an extended
         * response outside its word limit under `penalise` is multiplied by
         * max(0, 1 - penalty * overage), in proportion to the overage rather
         * than as a step at the boundary. WordLimit computed it and nothing
         * called it, so an author who chose penalise got warn.
         *
         * The row keeps both numbers: raw_score is what the marker typed and
         * points_awarded is what the learner gets, so a mark reduced by a
         * length rule can be explained from the row rather than recomputed
         * from a definition that may have been edited since.
         */
        $answer = $this->attempts->answersFor($attemptId)[$questionId] ?? null;
        $awarded = round($marked * $resolved->manualGradeMultiplier($served->definition, $answer, $partId), 2);

        $this->attempts->addGrade(new AttemptGrade(
            id: null,
            attemptId: $attemptId,
            questionId: $questionId,
            graderId: $principal->userId,
            pointsAwarded: $awarded,
            maxPoints: $maximum,
            feedback: $feedback,
            gradedAt: $now,
            // What the marker typed, before any deduction the type applies.
            rawScore: $marked,
            policyKey: 'manual',
            // The human has decided, so the item session is final and the
            // mark counts. That is what moves the row out of the pending
            // queue rather than a separate flag somewhere else.
            sessionStatus: SessionStatus::Final,
            scoreStatus: ScoreStatus::Scored,
            state: GradeState::derive(
                $maximum > 0.0 ? $awarded / $maximum : 0.0,
                SessionStatus::Final,
                ScoreStatus::Scored,
            ),
            partId: $partId,
            rubricVersionId: $resolvedRubric === null ? null : $resolvedRubric[1]->versionId,
        ));

        $grades = $this->attempts->gradesFor($attemptId);

        if ($resolvedRubric !== null) {
            // The selected level ids, beside the grade row they belong to.
            // Written after the row so the awards point at a grade that
            // exists, and once per row so a regrade keeps the history of
            // what each rater chose exactly as durable as the marks.
            [$provider, $rubric] = $resolvedRubric;
            $provider->record($this->latestGradeId($grades, $questionId, $partId), $rubric, $criteria, $comments);
        }

        if (!$this->manualGradingComplete($attempt, $grades)) {
            return $this->reload($attemptId);
        }

        $totals = AttemptTotals::fromGradeHistory($grades);
        $passed = $totals->passed($quiz->passMark);

        $this->attempts->recordGradingOutcome(
            $attemptId,
            Attempt::STATUS_GRADED,
            $totals->score,
            $totals->maxScore,
            $totals->percentage,
            $passed,
        );

        $this->events->dispatch(new AttemptGraded(
            $now,
            $attemptId,
            $attempt->quizId,
            $quiz->lessonId,
            $attempt->enrolmentId,
            $attempt->userId,
            $quiz->courseId,
            $totals->percentage,
            $passed,
        ));

        return $this->reload($attemptId);
    }

    /**
     * Feedback with no mark, which is the only thing an accepted AI feedback
     * draft may become.
     *
     * {@see execute()} could not express it. Passing a null mark and no rubric
     * criteria reaches requirePoints(), which throws "Give a mark, or the
     * levels of the rubric this answer is marked against", so every gradebook
     * feedback draft was permanently unacceptable AND the 422 a reviewer got
     * invited them to do the one thing the whole design forbids: supply a mark
     * beside the generated text.
     *
     * So the write is its own path, and it is deliberately narrower than the
     * marking one. It records a comment against the answer and moves nothing
     * else: no points, no session-status transition to Final, no score status,
     * no totals recomputation and no AttemptGraded event. The mark on the row
     * is copied from the latest roll-up grade for that question when there is
     * one, so an already-marked answer keeps its mark and the totals are
     * arithmetically unchanged; when there is none, the row is written unscored
     * and still awaiting a human, so it contributes nothing and the item stays
     * in the marking queue.
     */
    public function recordFeedback(
        Principal $principal,
        int $attemptId,
        int $questionId,
        string $feedback,
        ?string $partId = null,
    ): Attempt {
        $this->access->requireSignedIn($principal);

        $text = trim($feedback);

        if ($text === '') {
            throw ValidationException::forField('feedback', 'Feedback with nothing in it is not feedback.');
        }

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);
        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE);

        if (!$attempt->isSubmitted()) {
            throw new ConflictException('Only a submitted attempt can be given feedback.');
        }

        $question = $this->questions->byId($questionId);

        if ($question === null || $question->quizId !== $attempt->quizId) {
            throw ValidationException::forField('question_id', 'The question is not part of this attempt.');
        }

        $served = $this->servedQuestion($attempt, $questionId)
            ?? throw ValidationException::forField('question_id', 'The question is not part of this attempt.');

        $resolved = $this->types->forServed($served);
        $maximum = $resolved->partMaximum($served->definition, $served->points, $partId);
        $previous = $this->latestGradeFor($attemptId, $questionId, $partId);

        $this->attempts->addGrade(new AttemptGrade(
            id: null,
            attemptId: $attemptId,
            questionId: $questionId,
            graderId: $principal->userId,
            pointsAwarded: $previous === null ? 0.0 : $previous->pointsAwarded,
            maxPoints: $previous === null ? $maximum : $previous->maxPoints,
            feedback: $text,
            gradedAt: $this->clock->now(),
            partId: $partId,
            rawScore: $previous?->rawScore,
            policyKey: $previous === null ? 'feedback_only' : $previous->policyKey,
            // Unchanged from whatever the answer already was. A feedback row
            // that moved the session to Final would close a pending item on
            // the strength of a comment, and one that reported Scored with a
            // zero would silently wipe an existing mark out of the totals.
            sessionStatus: $previous === null
                ? SessionStatus::PendingExternalScoring
                : $previous->sessionStatus,
            scoreStatus: $previous === null ? ScoreStatus::NotScored : $previous->scoreStatus,
            state: $previous === null ? GradeState::Incorrect : $previous->state,
            rubricVersionId: $previous?->rubricVersionId,
        ));

        return $this->reload($attemptId);
    }

    /**
     * The latest grade row for one question, or one part of it.
     *
     * @return AttemptGrade|null
     */
    private function latestGradeFor(int $attemptId, int $questionId, ?string $partId): ?AttemptGrade
    {
        $latest = null;

        foreach ($this->attempts->gradesFor($attemptId) as $grade) {
            if ($grade->questionId === $questionId && $grade->partId === $partId) {
                $latest = $grade;
            }
        }

        return $latest;
    }

    private function reload(int $attemptId): Attempt
    {
        return $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);
    }

    /**
     * Every part that needs a human must have at least one manual grade row.
     *
     * Keyed on (question, part) rather than on the question alone, which is
     * what stops the first manual row closing a multi-part item. A type with
     * no parts has one roll-up part whose id is null, so the free build's own
     * six exercise the per-part path unchanged.
     *
     * @param list<AttemptGrade> $grades
     */
    private function manualGradingComplete(Attempt $attempt, array $grades): bool
    {
        $manuallyGraded = ManualGradingStatus::manualKeysOf($grades);

        foreach ($this->manualPartKeys($attempt) as $key) {
            if (!isset($manuallyGraded[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function manualPartKeys(Attempt $attempt): array
    {
        $keys = [];

        foreach ($this->manifest->forAttempt($attempt) as $question) {
            $resolved = $this->types->forServed($question);

            foreach ($resolved->pendingPartIds($question->definition, $question->externalScored) as $partId) {
                $keys[] = ManualGradingStatus::partKey($question->questionId, $partId);
            }
        }

        return $keys;
    }

    private function servedQuestion(Attempt $attempt, int $questionId): ?ServedQuestion
    {
        foreach ($this->manifest->forAttempt($attempt) as $question) {
            if ($question->questionId === $questionId) {
                return $question;
            }
        }

        return null;
    }
}
