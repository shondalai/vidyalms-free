<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptMarking;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradePart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;

/**
 * The quiz-level rules that act on a mark after the question type produced
 * it, in the order the design composes them.
 */
final class AttemptMarkingTest extends TestCase
{
    /**
     * The design's own worked check, written as a test so the two cannot
     * drift: a ten mark question at penalty 0.2 answered correctly on the
     * third try scores (1 - 0.2 * 2) * 10 = 6.
     *
     * Written against a raw score instead, the penalty disagreed with this
     * check by a factor of the item maximum and gave 9.6.
     */
    public function testTheTryPenaltyWorksInFractionSpace(): void
    {
        $result = AttemptMarking::apply(GradeResult::correct(), $this->policy(['try_penalty' => 0.2]), 3);

        self::assertSame(0.6, round($result->fraction, 4));
        self::assertSame(6.0, $result->awardedAgainst(10.0));
    }

    public function testAPenaltyOfOneThirdGivesThreeEffectiveTries(): void
    {
        $policy = $this->policy(['try_penalty' => 1 / 3]);

        self::assertSame(10.0, AttemptMarking::apply(GradeResult::correct(), $policy, 1)->awardedAgainst(10.0));
        self::assertSame(6.67, AttemptMarking::apply(GradeResult::correct(), $policy, 2)->awardedAgainst(10.0));
        self::assertSame(3.33, AttemptMarking::apply(GradeResult::correct(), $policy, 3)->awardedAgainst(10.0));
        self::assertSame(0.0, AttemptMarking::apply(GradeResult::correct(), $policy, 4)->awardedAgainst(10.0));
    }

    public function testTheFirstTryIsNeverPenalised(): void
    {
        $result = AttemptMarking::apply(GradeResult::correct(), $this->policy(['try_penalty' => 0.5]), 1);

        self::assertSame(1.0, $result->fraction);
    }

    public function testThePenaltyAppliesPerPartRatherThanOnlyToTheRollUp(): void
    {
        $composite = GradeResult::partial(1.0)->withParts([
            new GradePart('one', 1.0),
            new GradePart('two', 1.0),
        ]);

        $result = AttemptMarking::apply($composite, $this->policy(['try_penalty' => 0.25]), 2);

        self::assertSame(0.75, round($result->fraction, 4));
        self::assertSame([0.75, 0.75], array_map(
            static fn (GradePart $part): float => round($part->fraction, 4),
            $result->parts,
        ));
    }

    /**
     * **The roll-up follows the parts rather than being penalised a second
     * time.** Penalising both independently made the two disagree whenever a
     * part hit its floor: parts [1.0, 0.0] at penalty 0.5 became [0.5, 0.0],
     * whose mean is 0.25, while the roll-up went to 0.0 (2.5 of a ten-mark
     * item reported as nothing).
     */
    public function testTheRollUpMovesByWhatThePartsActuallyMoved(): void
    {
        $composite = GradeResult::partial(0.5)->withParts([
            new GradePart('one', 1.0),
            new GradePart('two', 0.0),
        ]);

        $result = AttemptMarking::apply($composite, $this->policy(['try_penalty' => 0.5]), 2);

        self::assertSame([0.5, 0.0], array_map(
            static fn (GradePart $part): float => round($part->fraction, 4),
            $result->parts,
        ));
        self::assertSame(0.25, round($result->fraction, 4));
        self::assertSame(2.5, $result->awardedAgainst(10.0));
    }

    /**
     * The tries are counted per part, so a part the learner never touched
     * pays nothing while the one they revised does. Applying one count to
     * the whole item is the bug Moodle shipped and fixed in MDL-36955.
     */
    public function testEachPartPaysForItsOwnTries(): void
    {
        $composite = GradeResult::partial(0.5)->withParts([
            new GradePart('one', 1.0),
            new GradePart('two', 0.0),
        ]);

        $result = AttemptMarking::apply(
            $composite,
            $this->policy(['try_penalty' => 0.5]),
            2,
            ['one' => 1, 'two' => 2],
        );

        self::assertSame([1.0, 0.0], array_map(
            static fn (GradePart $part): float => round($part->fraction, 4),
            $result->parts,
        ));
        self::assertSame(0.5, round($result->fraction, 4), 'the untouched part keeps its mark');
        self::assertSame(5.0, $result->awardedAgainst(10.0));
    }

    /**
     * A part is floored at its own marking rule rather than at the
     * container's: a part whose rule floors at 0 was penalised down to minus
     * 1.5 because the roll-up carried a certainty part's floor of minus 2.
     */
    public function testAPartIsFlooredAtItsOwnRuleRatherThanTheContainers(): void
    {
        $composite = (new GradeResult(0.0, floorFraction: -2.0))->withParts([
            new GradePart('own_floor', 0.0, floorFraction: 0.0),
        ]);

        $result = AttemptMarking::apply($composite, $this->policy([
            'try_penalty' => 1.5,
            AttemptPolicySet::KEY_NEGATIVE_MARKING => true,
        ]), 2);

        self::assertSame(0.0, round($result->parts[0]->fraction, 4));
    }

    public function testAQuizWithoutNegativeMarkingNeverAwardsANegativeMark(): void
    {
        // A policy with its own floor below zero: a short answer matcher, a
        // numeric band or a composite aggregation bound can all produce one.
        $penalised = new GradeResult(-0.5, floorFraction: -1.0);

        $result = AttemptMarking::apply($penalised, $this->policy([]), 1);

        self::assertSame(0.0, $result->fraction);
        self::assertSame(0.0, $result->awardedAgainst(10.0));
    }

    /**
     * **And the gate reaches the parts too.** Gating only the roll-up left a
     * composite whose parts read minus one on a quiz that promised no
     * penalty: the same negative mark the gate exists to refuse, one level
     * down and about to be written as a per-part grade row.
     */
    public function testTheNegativeMarkingGateFloorsThePartsAsWellAsTheRollUp(): void
    {
        $composite = (new GradeResult(-0.5, floorFraction: -1.0))->withParts([
            new GradePart('one', -1.0),
            new GradePart('two', 0.0),
        ]);

        $result = AttemptMarking::apply($composite, $this->policy([]), 1);

        self::assertSame(0.0, $result->fraction);
        self::assertSame([0.0, 0.0], array_map(
            static fn (GradePart $part): float => round($part->fraction, 4),
            $result->parts,
        ));
    }

    public function testAQuizWithNegativeMarkingKeepsThePolicysOwnFloor(): void
    {
        $penalised = new GradeResult(-0.5, floorFraction: -1.0);

        $result = AttemptMarking::apply($penalised, $this->policy([
            AttemptPolicySet::KEY_NEGATIVE_MARKING => true,
        ]), 1);

        self::assertSame(-0.5, $result->fraction);
        self::assertSame(-5.0, $result->awardedAgainst(10.0));
    }

    public function testARuleMayImposeAFloorOfItsOwn(): void
    {
        $penalised = new GradeResult(-0.9, floorFraction: -1.0);

        $result = AttemptMarking::apply($penalised, $this->policy([
            AttemptPolicySet::KEY_NEGATIVE_MARKING => true,
            AttemptPolicySet::KEY_NEGATIVE_MARKING_FLOOR => -0.25,
        ]), 1);

        self::assertSame(-0.25, $result->fraction);
    }

    public function testAnUnscoredResultIsNeverTouched(): void
    {
        $pending = GradeResult::manual('Waiting for a human.');

        $result = AttemptMarking::apply($pending, $this->policy(['try_penalty' => 0.5]), 4);

        self::assertSame(ScoreStatus::NotScored, $result->scoreStatus);
        self::assertSame(0.0, $result->fraction);
    }

    public function testTheQuizTotalIsFlooredAtZeroUnlessTheQuizAllowsANegativeOne(): void
    {
        self::assertSame(0.0, AttemptMarking::quizTotal(-4.0, $this->policy([])));
        self::assertSame(-4.0, AttemptMarking::quizTotal(-4.0, $this->policy([
            AttemptPolicySet::KEY_ALLOW_QUIZ_NEGATIVE => true,
        ])));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function policy(array $settings): AttemptPolicySet
    {
        return new AttemptPolicySet('deferred_feedback', $settings);
    }
}
