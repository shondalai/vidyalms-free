<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\AnswerSubmission;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptTotals;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The regrade engine: a first-class operation over the frozen manifest and
 * the append-only grade history, shared by the per-attempt and per-quiz use
 * cases.
 *
 * **It appends and never mutates.** Every changed mark is a new
 * attempt_grades row with the next version number, which keeps the
 * append-only invariant, gives every result for the same item a different
 * datestamp as QTI's results model requires, and leaves the original mark
 * readable forever. Nothing here updates a grade row, and there is no method
 * on the repository that could.
 *
 * **What it grades against.** The response, the presented projection, the
 * choice sequence, the points and the served order all come from the frozen
 * manifest, so a regrade sees exactly what the learner was asked. The
 * marking rule comes from the question's current definition, because that is
 * what a corrected key is: the author fixed the item, and the whole point of
 * corrected_key_only is to apply the fix. When the question row is gone, the
 * frozen definition stands in, so a regrade of an old cohort still runs.
 *
 * **What it will not touch.** A mark a human gave, and an item still waiting
 * for one, under every policy but full credit. A rater's judgement is not
 * derivable from a key, corrected or otherwise, and silently replacing one
 * with a machine's answer would be the worst failure this operation could
 * have. Those questions are skipped and named in the warnings.
 */
final class AttemptRegrader
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly AttemptManifest $manifest,
        private readonly QuestionRepository $questions,
        private readonly QuestionTypes $types,
        private readonly ScoringPolicyRegistry $policies,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
        private readonly LocalePort $locale = new FixedLocale(),
    ) {
    }

    /**
     * @param list<Attempt> $attempts the closed attempts to re-run
     */
    public function run(array $attempts, Quiz $quiz, RegradeRequest $request): RegradeOutcome
    {
        $examined = 0;
        $changedAttempts = 0;
        $appended = 0;
        $questionsRegraded = 0;
        $items = [];
        $warnings = [];

        foreach ($attempts as $attempt) {
            if ($attempt->id === null || !$attempt->countsTowardsGrade()) {
                continue;
            }

            ++$examined;

            $change = $this->runOne($attempt, $quiz, $request, $warnings);

            $appended += $change->appended;
            $questionsRegraded += $change->appended;

            if ($change->change !== null) {
                ++$changedAttempts;
                $items[] = $change->change;
            }
        }

        return new RegradeOutcome(
            $request->policy,
            $request->dryRun,
            $examined,
            $changedAttempts,
            $request->dryRun ? 0 : $appended,
            $questionsRegraded,
            $items,
            $warnings,
        );
    }

    /**
     * @param list<string> $warnings
     */
    private function runOne(Attempt $attempt, Quiz $quiz, RegradeRequest $request, array &$warnings): RegradeStep
    {
        $attemptId = (int) $attempt->id;
        $served = $this->manifest->forAttempt($attempt);
        $latest = AttemptTotals::latestPerQuestion($this->attempts->gradesFor($attemptId));
        $answers = $this->attempts->submissionsFor($attemptId);
        $policy = $attempt->policy();
        $now = $this->clock->now();

        $appended = 0;
        $score = 0.0;
        $maxScore = 0.0;
        $pendingHuman = false;

        foreach ($served as $question) {
            $existing = $latest[$question->questionId] ?? null;
            $award = $existing->pointsAwarded ?? 0.0;
            $counts = $existing === null || $existing->counts();

            if ($this->isEligible($question, $existing, $request, $warnings)) {
                $regraded = $this->awardFor(
                    $question,
                    $existing,
                    $answers[$question->questionId] ?? null,
                    $attempt,
                    $policy->toArray(),
                    $request,
                );

                if ($regraded !== null && $this->moved($award, $regraded->award)) {
                    ++$appended;
                    $award = $regraded->award;
                    $counts = $regraded->counts;

                    if (!$request->dryRun) {
                        $this->attempts->addGrade($this->gradeRow(
                            $attemptId,
                            $question,
                            $regraded,
                            $existing,
                            $request,
                            $now,
                        ));
                    }
                }
            }

            if ($existing !== null && $existing->sessionStatus->awaitsHuman()) {
                $pendingHuman = true;
            }

            if (!$question->contributesToScore() || !$counts) {
                continue;
            }

            $score += $award;
            $maxScore += $question->points;
        }

        if ($appended === 0) {
            return new RegradeStep(0, null);
        }

        $totals = AttemptTotals::fromPoints(AttemptMarking::quizTotal($score, $policy), $maxScore);
        $passed = $pendingHuman ? null : $totals->passed($quiz->passMark);
        $status = $pendingHuman ? $attempt->status : Attempt::STATUS_GRADED;

        if (!$request->dryRun) {
            $this->attempts->recordGradingOutcome(
                $attemptId,
                $status,
                $totals->score,
                $totals->maxScore,
                $totals->percentage,
                $passed,
            );
        }

        $moved = $this->moved($attempt->score ?? 0.0, $totals->score)
            || $this->moved($attempt->percentage ?? 0.0, $totals->percentage)
            || $attempt->passed !== $passed;

        return new RegradeStep($appended, $moved ? new RegradeAttemptChange(
            $attemptId,
            $attempt->userId,
            $attempt->attemptNumber,
            $attempt->score,
            $totals->score,
            $attempt->percentage,
            $totals->percentage,
            $attempt->passed,
            $passed,
            $appended,
        ) : null);
    }

    /**
     * @param list<string> $warnings
     */
    private function isEligible(
        ServedQuestion $question,
        ?AttemptGrade $existing,
        RegradeRequest $request,
        array &$warnings,
    ): bool {
        if (!$request->covers($question->questionId) || !$question->scored) {
            return false;
        }

        if ($request->policy->overridesHumanGrades()) {
            return true;
        }

        if ($existing !== null && $existing->graderId !== null) {
            $warnings[] = $this->translator->translate('vidyalms.regrade.warning.human_grade_kept');

            return false;
        }

        if ($existing !== null && $existing->sessionStatus->awaitsHuman()) {
            $warnings[] = $this->translator->translate('vidyalms.regrade.warning.pending_human_kept');

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $policySnapshot
     */
    private function awardFor(
        ServedQuestion $question,
        ?AttemptGrade $existing,
        ?AnswerSubmission $submission,
        Attempt $attempt,
        array $policySnapshot,
        RegradeRequest $request,
    ): ?RegradedAward {
        $points = $question->points;

        return match ($request->policy) {
            RegradePolicy::FullCredit => new RegradedAward($points, 1.0, true, null),
            RegradePolicy::AnyAnswer => $submission === null
                ? null
                : new RegradedAward($points, 1.0, true, null),
            RegradePolicy::CorrectedKeyOnly, RegradePolicy::BothKeys => $this->remarked(
                $question,
                $existing,
                $submission,
                $attempt,
                $policySnapshot,
                $request->policy,
            ),
        };
    }

    /**
     * @param array<string, mixed> $policySnapshot
     */
    private function remarked(
        ServedQuestion $question,
        ?AttemptGrade $existing,
        ?AnswerSubmission $submission,
        Attempt $attempt,
        array $policySnapshot,
        RegradePolicy $policy,
    ): ?RegradedAward {
        if ($submission === null) {
            return null;
        }

        $live = $this->questions->byId($question->questionId);
        $resolved = $live === null ? $this->types->forServed($question) : $this->types->forQuestion($live);
        $definition = $live->definition ?? $question->definition;

        $result = $resolved->grade($definition, $submission->response, GradingContext::create(
            policies: $this->policies,
            presented: $question->presented,
            choiceSequence: $question->choiceSequence,
            attemptPolicy: $policySnapshot,
            locale: $this->locale->current(),
            try: $this->attempts->tryCountFor($attempt->id ?? 0, $question->questionId),
            certainty: $submission->certainty,
            policyOverride: $question->policyKey,
            policyOverrideParams: $question->policyParams,
        ));

        // **The same quiz-level rules the submit path applies, in the same
        // order.** Without them a regrade recomputed the item at full
        // fraction, handing back a try penalty the learner was charged at
        // submit (and moving the award, so corrected_key_only appended a row
        // even when no key had changed), and a policy carrying a negative
        // floor reached the grade row unclamped on a quiz that had not
        // enabled negative marking.
        $result = AttemptMarking::apply(
            $result,
            $attempt->policy(),
            $this->attempts->tryCountFor($attempt->id ?? 0, $question->questionId),
        );

        $award = $result->awardedAgainst($question->points);

        if ($policy === RegradePolicy::BothKeys && $existing !== null) {
            // No score is reduced, which is exactly what "both keys" means:
            // the learner keeps whatever the previous key gave them.
            $award = max($award, $existing->pointsAwarded);
        }

        return new RegradedAward(
            $award,
            $result->fraction,
            $result->scoreStatus === ScoreStatus::Scored,
            $result,
        );
    }

    private function gradeRow(
        int $attemptId,
        ServedQuestion $question,
        RegradedAward $award,
        ?AttemptGrade $existing,
        RegradeRequest $request,
        \DateTimeImmutable $now,
    ): AttemptGrade {
        $result = $award->result;

        return new AttemptGrade(
            id: null,
            attemptId: $attemptId,
            questionId: $question->questionId,
            graderId: null,
            pointsAwarded: $award->award,
            maxPoints: $question->points,
            feedback: $result->feedback ?? $existing?->feedback,
            gradedAt: $now,
            rawScore: $result?->rawScore,
            minScore: $result?->minScore,
            policyKey: $result->policyKey ?? $existing?->policyKey,
            // The regrade names itself in the scoring parameters, so the
            // append-only history proves what was awarded, under which
            // operation, why, **and by whom**. A mark change with no
            // accountable actor is the same class of gap AERA/APA/NCME 4.19
            // closes for the algorithm: the actor is an explicit field
            // rather than grader_id, because grader_id means a human marked
            // the answer and a regrade is a machine re-running a rule on
            // that person's authority.
            scoringParams: [
                'regrade' => array_filter([
                    'policy' => $request->policy->value,
                    'reason' => $request->reason,
                    'actor_id' => $request->actorId,
                    'at' => $now->format('Y-m-d H:i:s'),
                ], static fn (mixed $value): bool => $value !== null),
            ] + ($result->scoringParams ?? []),
            sessionStatus: SessionStatus::Final,
            scoreStatus: $award->counts ? ScoreStatus::Scored : ScoreStatus::NotScored,
            state: $result?->state() ?? $this->stateFor($award, $question->points),
            answeredStatus: $result->answeredStatus ?? AnsweredStatus::Answered,
            feedbackIds: $result->feedbackIds ?? [],
            version: ($existing->version ?? 1) + 1,
        );
    }

    private function stateFor(RegradedAward $award, float $points): GradeState
    {
        return GradeState::derive(
            $points > 0.0 ? $award->award / $points : $award->fraction,
            SessionStatus::Final,
            $award->counts ? ScoreStatus::Scored : ScoreStatus::NotScored,
        );
    }

    /**
     * Comparison at the precision the column stores, so a float artefact
     * never reports a cohort as changed.
     */
    private function moved(float $before, float $after): bool
    {
        return abs(round($before, 2) - round($after, 2)) >= 0.005;
    }
}
