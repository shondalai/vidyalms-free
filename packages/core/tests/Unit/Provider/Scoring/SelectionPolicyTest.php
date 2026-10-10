<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Provider\Scoring\GuessNeutralPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\PlusMinusPolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;

/**
 * The two selection policies the free build ships, and the property that
 * separates them.
 */
final class SelectionPolicyTest extends TestCase
{
    /**
     * @param list<string> $selected
     */
    private function response(array $selected): ResponseValue
    {
        return ResponseValue::of(ResponseShape::of(Cardinality::Multiple, BaseType::Identifier), $selected);
    }

    /**
     * Three keys, one distractor: the balance the design uses to show the
     * two rules are not the same function.
     */
    private function key(): ScoringKey
    {
        return ScoringKey::set(['a', 'e', 'i'], ['a', 'e', 'i', 'k']);
    }

    public function testPlusMinusIsTheFreeBuildsOriginalFormula(): void
    {
        $policy = new PlusMinusPolicy();
        $params = $policy->defaultParams();

        // max(0, C - I) / |K|, which is min(1, max(0, C - I) / |K|) with the
        // min removed, because C never exceeds |K| so the min never binds.
        $context = ScoringContext::defaults();

        self::assertSame(
            1.0,
            $policy->score($this->response(['a', 'e', 'i']), $this->key(), $params, $context)->fraction,
        );
        self::assertEqualsWithDelta(
            1 / 3,
            $policy->score($this->response(['a', 'e', 'k']), $this->key(), $params, $context)->fraction,
            0.0001,
        );
        self::assertSame(
            0.0,
            $policy->score($this->response(['k']), $this->key(), $params, $context)->fraction,
        );
    }

    /**
     * The hole the new default closes, stated as a test so nobody reopens
     * it: ticking every option pays two thirds of the marks under the old
     * rule.
     */
    public function testPlusMinusStillPaysForTickingEverything(): void
    {
        $policy = new PlusMinusPolicy();

        $result = $policy->score(
            $this->response(['a', 'e', 'i', 'k']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertEqualsWithDelta(2 / 3, $result->fraction, 0.0001);
    }

    public function testGuessNeutralPaysExactlyZeroForTickingEverything(): void
    {
        $policy = new GuessNeutralPolicy();

        $result = $policy->score(
            $this->response(['a', 'e', 'i', 'k']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
    }

    /**
     * "For any option balance" is the claim, so it is tested at three of
     * them rather than at one.
     *
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function balances(): iterable
    {
        yield 'one key, three distractors' => [['a'], ['a', 'b', 'c', 'd']];
        yield 'two keys, two distractors' => [['a', 'b'], ['a', 'b', 'c', 'd']];
        yield 'four keys, two distractors' => [['a', 'b', 'c', 'd'], ['a', 'b', 'c', 'd', 'e', 'f']];
    }

    /**
     * @param list<string> $keys
     * @param list<string> $universe
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('balances')]
    public function testGuessNeutralIsZeroForEveryOptionBalance(array $keys, array $universe): void
    {
        $policy = new GuessNeutralPolicy();

        $result = $policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Multiple, BaseType::Identifier), $universe),
            ScoringKey::set($keys, $universe),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
    }

    public function testGuessNeutralPaysFullMarksForTheExactKey(): void
    {
        $policy = new GuessNeutralPolicy();

        $result = $policy->score(
            $this->response(['a', 'e', 'i']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(1.0, $result->fraction);
    }

    /**
     * The penalty weight is |K| / |D| and it grows without bound as
     * distractors get scarce. The design says so; this pins the number an
     * author needs.
     */
    public function testGuessNeutralPenaltyWeightIsKeysOverDistractors(): void
    {
        $policy = new GuessNeutralPolicy();

        // Three keys, one distractor: one wrong tick costs the whole item.
        $result = $policy->score(
            $this->response(['a', 'e', 'k']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
        self::assertSame(1, $result->params['distractors']);
    }

    public function testDistinctMembersCountOnce(): void
    {
        $policy = new PlusMinusPolicy();

        $result = $policy->score(
            $this->response(['a', 'a', 'a']),
            $this->key(),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertEqualsWithDelta(1 / 3, $result->fraction, 0.0001);
    }

    public function testAnItemWithNoDistractorIsNotScoredRatherThanDividedByZero(): void
    {
        $policy = new GuessNeutralPolicy();

        $result = $policy->score(
            $this->response(['a']),
            ScoringKey::set(['a', 'b'], ['a', 'b']),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
        self::assertSame('no_distractors', $result->params['reason']);
    }
}
