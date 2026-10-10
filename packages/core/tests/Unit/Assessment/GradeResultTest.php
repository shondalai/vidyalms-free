<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;

/**
 * The widening, tested against the reasons it exists.
 */
final class GradeResultTest extends TestCase
{
    /**
     * The old class threw on any fraction outside 0 to 1, which makes the
     * mapping sum, the plus/minus raw and the certainty range all
     * unrepresentable. It clamps now, and it keeps the raw number beside the
     * clamped fraction so a grader and a regrade can both see it.
     */
    public function testAnOutOfRangeFractionIsClampedRatherThanThrown(): void
    {
        $high = new GradeResult(1.4, rawScore: 7.0, maxScore: 5.0);
        $low = new GradeResult(-0.6, rawScore: -3.0);

        self::assertSame(1.0, $high->fraction);
        self::assertSame(7.0, $high->rawScore);
        self::assertSame(0.0, $low->fraction);
        self::assertSame(-3.0, $low->rawScore);
    }

    public function testTheFloorIsZeroUnlessThePolicyLowersIt(): void
    {
        self::assertSame(0.0, (new GradeResult(-2.0))->fraction);
        self::assertSame(-2.0, (new GradeResult(-2.0, floorFraction: -2.0))->fraction);
    }

    public function testAFloorAboveOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GradeResult(0.5, floorFraction: 1.5);
    }

    public function testRequiresManualGradingIsDerivedFromTheSessionStatus(): void
    {
        self::assertFalse(GradeResult::correct()->requiresManualGrading());
        self::assertTrue(GradeResult::manual()->requiresManualGrading());
        self::assertTrue(
            (new GradeResult(0.0, sessionStatus: SessionStatus::PendingExternalScoring))->requiresManualGrading(),
        );
    }

    public function testAPendingHumanResultIsNotScoredRatherThanScoredAtZero(): void
    {
        $manual = GradeResult::manual();

        self::assertSame(ScoreStatus::NotScored, $manual->scoreStatus);
        self::assertSame(GradeState::PendingHuman, $manual->state());
        self::assertSame(0.0, $manual->awardedAgainst(10.0), 'nothing is awarded while it waits');
    }

    public function testAnUnscoredResultAwardsNothingAgainstAnyMaximum(): void
    {
        self::assertSame(0.0, GradeResult::notScored()->awardedAgainst(10.0));
        self::assertSame(GradeState::NotScored, GradeResult::notScored()->state());
    }

    public function testTheStateIsDerivedAndNotSupplied(): void
    {
        self::assertSame(GradeState::Correct, GradeResult::correct()->state());
        self::assertSame(GradeState::Partial, GradeResult::partial(0.5)->state());
        self::assertSame(GradeState::Incorrect, GradeResult::incorrect()->state());
    }

    public function testAnUnansweredResultKeepsItsAnsweredStatus(): void
    {
        $result = GradeResult::unanswered();

        self::assertSame(AnsweredStatus::Presented, $result->answeredStatus);
        self::assertSame(0.0, $result->fraction);
    }

    public function testTheWithersKeepEveryOtherField(): void
    {
        $result = (new GradeResult(
            0.5,
            'well done',
            rawScore: 2.0,
            minScore: 0.0,
            maxScore: 4.0,
            feedbackIds: ['fb1'],
        ))->withPolicy('map_response', ['floor_fraction' => 0.0])->withAnsweredStatus(AnsweredStatus::Attempted);

        self::assertSame(0.5, $result->fraction);
        self::assertSame('well done', $result->feedback);
        self::assertSame(2.0, $result->rawScore);
        self::assertSame(['fb1'], $result->feedbackIds);
        self::assertSame('map_response', $result->policyKey);
        self::assertSame(AnsweredStatus::Attempted, $result->answeredStatus);
    }
}
