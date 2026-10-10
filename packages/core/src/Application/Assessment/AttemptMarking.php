<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradePart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;

/**
 * The quiz-level rules that act on a mark after the question type produced
 * it: the try penalty and the negative marking gate.
 *
 * They live here rather than in any question type because no type may see a
 * quiz policy, and here rather than in a scoring policy because a policy
 * marks one response against one key and knows nothing about how many tries
 * the learner has had or what this quiz promised its learners about
 * penalties.
 *
 * **The composition order is stated, because two rules that can disagree is
 * not an academic problem.** The type's policy produces a fraction. The try
 * penalty subtracts in fraction space, where both operands are dimensionless
 * and the floor is a fraction, and only then is the result multiplied by the
 * item maximum. The negative marking gate is last, and it is a gate rather
 * than an arithmetic step: a quiz that did not enable negative marking
 * cannot award a negative mark whatever a policy's own floor says.
 *
 * Written against a raw score instead, the penalty disagreed with its own
 * worked check by a factor of the item maximum, and it compared a fraction
 * floor against a raw score, so a part with a floor of minus two would have
 * floored a ten mark item at minus two marks rather than at minus twenty.
 */
final class AttemptMarking
{
    private function __construct()
    {
    }

    /**
     * The mark this attempt's policy actually awards for one graded answer.
     *
     * @param int $tries the number of tries taken, from the append-only
     *                   answer event history; one try means no penalty
     */
    /**
     * @param int                $tries       the tries taken on the item as a
     *                                        whole, from the append-only
     *                                        answer event history; one try
     *                                        means no penalty
     * @param array<string, int> $triesByPart the tries taken on each part,
     *                                        for a composite. A part with no
     *                                        entry falls back to the item's
     *                                        count.
     */
    public static function apply(
        GradeResult $result,
        AttemptPolicySet $policy,
        int $tries,
        array $triesByPart = [],
    ): GradeResult {
        // Nothing was scored, so there is nothing for a quiz rule to act on.
        // Penalising a pending human grade or a registry miss would turn an
        // unmarked item into a negative one.
        if ($result->scoreStatus === ScoreStatus::NotScored) {
            return $result;
        }

        $penalised = self::withTryPenalty($result, $policy->tryPenalty(), $tries, $triesByPart);

        return self::withNegativeMarkingGate($penalised, $policy);
    }

    /**
     * The quiz total, floored at 0 unless the quiz allows a negative total.
     */
    public static function quizTotal(float $score, AttemptPolicySet $policy): float
    {
        if ($policy->allowsQuizNegativeTotal()) {
            return $score;
        }

        return max(0.0, $score);
    }

    /**
     * f_final = max(floor_fraction, f - penalty * (tries - 1)), applied per
     * part, then multiplied by the item maximum by the caller.
     *
     * Worked check, which is also a unit test so the two cannot drift: a ten
     * mark question at penalty 0.2 answered correctly on the third try
     * scores (1 - 0.2 * 2) * 10 = 6.
     */
    /**
     * @param array<string, int> $triesByPart
     */
    private static function withTryPenalty(
        GradeResult $result,
        float $penalty,
        int $tries,
        array $triesByPart,
    ): GradeResult {
        if ($penalty <= 0.0) {
            return $result;
        }

        $floor = $result->floorFraction;

        if ($result->parts === []) {
            return $tries <= 1 ? $result : $result->withFraction(max($floor, $result->fraction - $penalty * ($tries - 1)));
        }

        // **Per part, and the roll-up follows the parts rather than being
        // penalised a second time.** Penalising both independently made the
        // two disagree whenever a part hit its floor: parts [1.0, 0.0] at
        // penalty 0.5 became [0.5, 0.0], whose mean is 0.25, while the
        // roll-up went to 0.0. The roll-up therefore moves by exactly what
        // the parts moved by, which preserves whatever aggregation the item
        // itself applied (a weighted composite is not a mean) and reproduces
        // the plain subtraction when no part is floored.
        $parts = [];

        foreach ($result->parts as $part) {
            $partTries = $triesByPart[$part->partId] ?? $tries;
            $parts[] = self::penalisedPart($part, $penalty * max(0, $partTries - 1), $floor);
        }

        $shift = self::weightedMean($parts) - self::weightedMean($result->parts);

        return $result
            ->withFraction(max($floor, $result->fraction + $shift))
            ->withParts($parts);
    }

    /**
     * The mean of the scored parts, weighted by each part's own maximum
     * where it declares one.
     *
     * @param list<GradePart> $parts
     */
    private static function weightedMean(array $parts): float
    {
        $total = 0.0;
        $weight = 0.0;

        foreach ($parts as $part) {
            if ($part->scoreStatus === ScoreStatus::NotScored) {
                continue;
            }

            $own = $part->maxScore !== null && $part->maxScore > 0.0 ? $part->maxScore : 1.0;
            $total += $part->fraction * $own;
            $weight += $own;
        }

        return $weight > 0.0 ? $total / $weight : 0.0;
    }

    private static function penalisedPart(GradePart $part, float $deduction, float $containerFloor): GradePart
    {
        if ($part->scoreStatus === ScoreStatus::NotScored || $deduction <= 0.0) {
            return $part;
        }

        return new GradePart(
            $part->partId,
            max($part->floorAgainst($containerFloor), $part->fraction - $deduction),
            $part->rawScore,
            $part->maxScore,
            $part->answeredStatus,
            $part->scoreStatus,
            $part->sessionStatus,
            $part->feedbackIds,
            $part->floorFraction,
        );
    }

    /**
     * A quiz that did not enable negative marking never awards a negative
     * mark, whatever a policy's own floor allows.
     *
     * This is the gate the design describes as closing three routes past it
     * at once: a matcher fraction below zero, an accepted band below zero,
     * and a composite propagating either through an aggregation bound. All
     * three are reachable the moment GradeResult clamps rather than throws,
     * which it now does.
     */
    private static function withNegativeMarkingGate(GradeResult $result, AttemptPolicySet $policy): GradeResult
    {
        // **The parts as well as the roll-up.** Gating only the roll-up left
        // a composite whose parts read minus one on a quiz that promised no
        // penalty, which is the same negative mark the gate exists to
        // refuse, one level down and about to be written as a per-part grade
        // row.
        if (!$policy->allowsNegativeMarking()) {
            return self::flooredAt($result, 0.0, true);
        }

        // The rule in force may impose a floor of its own, below the item's.
        // Null leaves each scoring policy's floor alone, which is the common
        // case: the rule decides whether a penalty applies at all and the
        // marking rule decides how large it is.
        $floor = $policy->negativeMarkingFloor();

        return $floor === null ? $result : self::flooredAt($result, $floor, false);
    }

    private static function flooredAt(GradeResult $result, float $floor, bool $declareFloor): GradeResult
    {
        $parts = [];
        $moved = false;

        foreach ($result->parts as $part) {
            if ($part->scoreStatus === ScoreStatus::NotScored || $part->fraction >= $floor) {
                $parts[] = $part;

                continue;
            }

            $moved = true;
            $parts[] = new GradePart(
                $part->partId,
                $floor,
                $part->rawScore,
                $part->maxScore,
                $part->answeredStatus,
                $part->scoreStatus,
                $part->sessionStatus,
                $part->feedbackIds,
                $declareFloor ? $floor : $part->floorFraction,
            );
        }

        $gated = $result->fraction >= $floor
            ? $result
            : $result->withFraction($floor, $declareFloor ? $floor : null);

        return $moved ? $gated->withParts($parts) : $gated;
    }
}
