<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Provider\Scoring\AbsolutePositionPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\KendallTauPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\OrderingMetric;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;

/**
 * The two free ordering policies and the chance correction they share.
 */
final class OrderingPolicyTest extends TestCase
{
    /**
     * @param list<string> $order
     */
    private function response(array $order): ResponseValue
    {
        return ResponseValue::of(ResponseShape::of(Cardinality::Ordered, BaseType::Identifier), $order);
    }

    private function key(): ScoringKey
    {
        return ScoringKey::sequence(['a', 'b', 'c', 'd']);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $order
     */
    private function tau(array $order, array $params = []): float
    {
        $policy = new KendallTauPolicy();

        return $policy->score(
            $this->response($order),
            $this->key(),
            $params + $policy->defaultParams(),
            ScoringContext::defaults(),
        )->fraction;
    }

    public function testTheExactOrderScoresFullMarks(): void
    {
        self::assertSame(1.0, $this->tau(['a', 'b', 'c', 'd']));
    }

    /**
     * The cyclic shift the earlier reasoning used as the argument for making
     * Kendall tau the default. Raw it scores 0.5, which is precisely the
     * guessing baseline: the shift earns nothing above chance while reading
     * as half marks. Corrected, it scores zero.
     */
    public function testTheCyclicShiftEarnsNothingAboveChance(): void
    {
        self::assertSame(0.5, $this->tau(['d', 'a', 'b', 'c'], [OrderingMetric::PARAM_CHANCE_CORRECTED => false]));
        self::assertSame(0.0, $this->tau(['d', 'a', 'b', 'c']));
    }

    public function testAMaximallyWrongReversalScoresZero(): void
    {
        self::assertSame(0.0, $this->tau(['d', 'c', 'b', 'a']));
    }

    /**
     * A near-perfect answer keeps most of its credit: one adjacent
     * transposition on four items is 0.833 raw and 0.667 corrected.
     */
    public function testOneAdjacentTranspositionKeepsMostOfItsCredit(): void
    {
        self::assertEqualsWithDelta(
            5 / 6,
            $this->tau(['a', 'b', 'd', 'c'], [OrderingMetric::PARAM_CHANCE_CORRECTED => false]),
            0.0001,
        );
        self::assertEqualsWithDelta(2 / 3, $this->tau(['a', 'b', 'd', 'c']), 0.0001);
    }

    public function testTheBaselineActuallyUsedIsRecordedForARegrade(): void
    {
        $policy = new KendallTauPolicy();

        $result = $policy->score(
            $this->response(['a', 'b', 'c', 'd']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.5, $result->params['baseline']);
        self::assertTrue($result->params[OrderingMetric::PARAM_CHANCE_CORRECTED]);
    }

    /**
     * The hole in the free build's original ordering rule: a response that
     * is not a permutation was scored by counting matching positions.
     */
    public function testAResponseThatIsNotAPermutationScoresZeroUnderBothPolicies(): void
    {
        $absolute = new AbsolutePositionPolicy();
        $params = [OrderingMetric::PARAM_CHANCE_CORRECTED => false] + $absolute->defaultParams();

        $repeated = $this->response(['a', 'a', 'a', 'a']);

        self::assertSame(0.0, $absolute->score($repeated, $this->key(), $params, ScoringContext::defaults())->fraction);
        self::assertSame(0.0, $this->tau(['a', 'a', 'a', 'a']));

        $short = $this->response(['a']);
        self::assertSame(0.0, $absolute->score($short, $this->key(), $params, ScoringContext::defaults())->fraction);
    }

    /**
     * The legacy pin: exact position with the correction off is exactly what
     * an ordering question graded under before, so no historic percentage
     * moves.
     */
    public function testAbsolutePositionWithTheCorrectionOffReproducesTheLegacyMark(): void
    {
        $policy = new AbsolutePositionPolicy();
        $params = [OrderingMetric::PARAM_CHANCE_CORRECTED => false] + $policy->defaultParams();

        $result = $policy->score(
            $this->response(['a', 'b', 'd', 'c']),
            $this->key(),
            $params,
            ScoringContext::defaults(),
        );

        self::assertSame(0.5, $result->fraction, 'two of four positions match');
    }

    public function testAbsolutePositionsChanceBaselineIsOneOverN(): void
    {
        $policy = new AbsolutePositionPolicy();

        $result = $policy->score(
            $this->response(['a', 'b', 'd', 'c']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.25, $result->params['baseline']);
        // (0.5 - 0.25) / (1 - 0.25)
        self::assertEqualsWithDelta(1 / 3, $result->fraction, 0.0001);
    }
}
