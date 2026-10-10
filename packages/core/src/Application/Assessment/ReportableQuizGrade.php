<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregatedGrade;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationContext;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore;

/**
 * The one grade a quiz reports for one learner.
 *
 * **The composition order is fixed and stated, because this is the
 * reportable grade for a whole quiz and two settings that can disagree is
 * not an academic problem.** attempts_counted filters the attempt set;
 * attempt_aggregation reduces the filtered set. So "first" or "last" counted
 * makes the aggregator a no-op by construction, and "counted: first" with
 * "aggregation: highest" is the first attempt and not the highest of all.
 *
 * Two orthogonal settings rather than one four-valued grading method,
 * following Blackboard rather than Moodle, because the split expresses every
 * option the surveyed systems offer plus combinations none of them supports.
 */
final class ReportableQuizGrade
{
    public function __construct(private readonly AggregationMethodRegistry $methods)
    {
    }

    /**
     * @param list<AttemptScore> $attempts every attempt with a result, oldest first
     */
    public function of(
        array $attempts,
        QuizPolicy $policy,
        int $passMark,
        int $quizId = 0,
        int $userId = 0,
    ): ?AggregatedGrade {
        // **The rule the attempts were sat under, not the one the quiz row
        // carries today.** Editing attempt_aggregation on a quiz with graded
        // attempts moved every learner's reported percentage instantly, with
        // no grade row, no event and nothing anywhere saying so. The
        // snapshot is frozen at StartAttempt for exactly this; the live
        // policy stands in only for attempts taken before it was written.
        $frozen = self::frozenPolicy($attempts);
        $countedRule = $frozen['counted'] ?? $policy->attemptsCounted;
        $aggregation = $frozen['aggregation'] ?? $policy->attemptAggregation;
        $params = $frozen['params'] ?? $policy->attemptAggregationParams;

        $counted = self::counted($attempts, $countedRule);

        if ($counted === []) {
            return null;
        }

        $method = $this->methods->resolve($aggregation);
        $grade = $method->aggregate(
            $counted,
            AggregationContext::create($quizId, $userId, $passMark, $params),
        );

        return $grade?->withMethod($aggregation, $method->key());
    }

    /**
     * The counting and aggregation rules frozen on the newest attempt that
     * carries them.
     *
     * The newest rather than the oldest, because a learner who started a
     * further attempt after the setting changed was told the new rule before
     * they sat it, and because an attempt with no snapshot at all (one taken
     * before the column existed) must not silence one that has it.
     *
     * @param list<AttemptScore> $attempts oldest first
     *
     * @return array{counted?: string, aggregation?: string, params?: array<string, mixed>}
     */
    private static function frozenPolicy(array $attempts): array
    {
        foreach (array_reverse($attempts) as $attempt) {
            if ($attempt->aggregation === null && $attempt->attemptsCounted === null) {
                continue;
            }

            $frozen = [];

            if ($attempt->attemptsCounted !== null) {
                $frozen['counted'] = $attempt->attemptsCounted;
            }

            if ($attempt->aggregation !== null) {
                $frozen['aggregation'] = $attempt->aggregation;
                $frozen['params'] = $attempt->aggregationParams;
            }

            return $frozen;
        }

        return [];
    }

    /**
     * The percentage alone, which is what the learner-facing payloads carry.
     *
     * @param list<AttemptScore> $attempts
     */
    public function percentageOf(array $attempts, QuizPolicy $policy, int $passMark): ?float
    {
        return $this->of($attempts, $policy, $passMark)?->percentage;
    }

    /**
     * The filter half of the rule.
     *
     * @param list<AttemptScore> $attempts oldest first
     *
     * @return list<AttemptScore>
     */
    public static function counted(array $attempts, string $attemptsCounted): array
    {
        if ($attempts === []) {
            return [];
        }

        // **The filter picks from the whole set, and only then drops what is
        // not graded yet.** Filtering first made "first" and "last" mean the
        // first and last *graded* attempt: with attempt one still waiting on
        // an essay and attempt two at 60 percent, "count the first attempt"
        // reported 60 percent from attempt two, which is not the attempt the
        // setting names. The design states the composition as
        // attempts_counted filtering the attempt set and attempt_aggregation
        // reducing the filtered one, which makes an ungraded counted attempt
        // mean no reportable grade rather than a promotion of the next.
        $counted = match ($attemptsCounted) {
            QuizPolicy::COUNTED_FIRST => [$attempts[0]],
            QuizPolicy::COUNTED_LAST => [$attempts[\count($attempts) - 1]],
            default => $attempts,
        };

        return array_values(array_filter(
            $counted,
            static fn (AttemptScore $attempt): bool => $attempt->isGraded(),
        ));
    }

    /**
     * The attempts of one learner as an aggregator sees them. An attempt
     * still open has no result, and an abandoned one is not counted by the
     * definition of must_submit, so neither reaches the set.
     *
     * @param list<Attempt> $attempts oldest first
     *
     * @return list<AttemptScore>
     */
    public static function scoresOf(array $attempts): array
    {
        $scores = [];

        foreach ($attempts as $attempt) {
            if (!$attempt->countsTowardsGrade() || $attempt->id === null) {
                continue;
            }

            $frozen = $attempt->policySnapshot === null || $attempt->policySnapshot === []
                ? null
                : $attempt->policy();

            $scores[] = new AttemptScore(
                $attempt->id,
                $attempt->attemptNumber,
                $attempt->score,
                $attempt->maxScore,
                $attempt->percentage,
                $attempt->passed,
                $attempt->submittedAt,
                $frozen?->attemptsCounted(),
                $frozen?->attemptAggregation(),
                $frozen?->attemptAggregationParams() ?? [],
            );
        }

        return $scores;
    }
}
