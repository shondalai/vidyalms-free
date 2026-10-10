<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\Scoring\MapResponsePolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\MappingSpec;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;

/**
 * The five mapping rules, plus the two max_raw cases, written once here
 * because this is the one implementation every mapping type shares. Getting
 * any of them wrong makes partial credit subtly wrong everywhere.
 */
final class MapResponsePolicyTest extends TestCase
{
    private MapResponsePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MapResponsePolicy();
    }

    /**
     * @param list<string> $selected
     */
    private function response(array $selected): ResponseValue
    {
        return ResponseValue::of(ResponseShape::of(Cardinality::Multiple, BaseType::Identifier), $selected);
    }

    /**
     * The specification's own worked example: {A:0, B:1, C:0.5, D:0}.
     */
    private function workedExample(): MappingSpec
    {
        return new MappingSpec(
            ['A' => 0.0, 'B' => 1.0, 'C' => 0.5, 'D' => 0.0],
            universe: ['A', 'B', 'C', 'D'],
        );
    }

    private function score(ResponseValue $response, MappingSpec $spec): float
    {
        return $this->policy->score(
            $response,
            ScoringKey::mapped($spec),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        )->fraction;
    }

    private function raw(ResponseValue $response, MappingSpec $spec): ?float
    {
        return $this->policy->score(
            $response,
            ScoringKey::mapped($spec),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        )->raw;
    }

    /** Rule 1: distinct members only. */
    public function testDistinctMembersAreCountedOnce(): void
    {
        $spec = $this->workedExample();

        self::assertSame(0.5, $this->raw($this->response(['C']), $spec));
        self::assertSame(1.5, $this->raw($this->response(['C', 'B']), $spec));
        self::assertSame(1.5, $this->raw($this->response(['B', 'B', 'C']), $spec), 'not 2.5');
    }

    /** Rule 2: sum first, then clamp, in that order. */
    public function testTheSumIsClampedAfterItIsComputedNotDuring(): void
    {
        $spec = new MappingSpec(
            ['a' => 1.0, 'b' => -1.0, 'c' => 1.0],
            lowerBound: 0.0,
            universe: ['a', 'b', 'c'],
        );

        // Summing first gives 1 + -1 + 1 = 1, then the floor does nothing.
        // Clamping each entry first would give 1 + 0 + 1 = 2.
        self::assertSame(1.0, $this->raw($this->response(['a', 'b', 'c']), $spec));
    }

    public function testTheLowerBoundFloorsANegativeSum(): void
    {
        $spec = new MappingSpec(
            ['a' => 1.0, 'b' => -1.0, 'c' => -1.0],
            lowerBound: 0.0,
            universe: ['a', 'b', 'c'],
        );

        self::assertSame(0.0, $this->raw($this->response(['b', 'c']), $spec));
    }

    /** Rule 3: default_value applies to any submitted value with no entry. */
    public function testAnUnlistedValueTakesTheDefault(): void
    {
        $spec = new MappingSpec(
            ['a' => 1.0],
            defaultValue: -0.5,
            universe: ['a', 'b', 'c'],
        );

        self::assertSame(0.5, $this->raw($this->response(['a', 'b']), $spec));
    }

    /** Rule 4: string keys compare case-insensitively unless told otherwise. */
    public function testKeysCompareCaseInsensitivelyByDefault(): void
    {
        $spec = new MappingSpec(['Alpha' => 1.0], universe: ['Alpha', 'Beta']);

        self::assertSame(1.0, $this->raw($this->response(['alpha']), $spec));
    }

    public function testACaseSensitiveMappingDoesNotFold(): void
    {
        $spec = new MappingSpec(['Alpha' => 1.0], caseSensitive: true, universe: ['Alpha', 'Beta']);

        self::assertSame(0.0, $this->raw($this->response(['alpha']), $spec));
    }

    /** Rule 5: a NULL response scores 0.0 without consulting the map. */
    public function testANullResponseScoresZeroWithoutConsultingTheMap(): void
    {
        $spec = new MappingSpec(
            [],
            defaultValue: 1.0,
            upperBound: 2.0,
            universe: ['a', 'b'],
        );

        self::assertSame(0.0, $this->score(ResponseValue::null(ResponseShape::of(Cardinality::Multiple, BaseType::Identifier)), $spec));
        self::assertSame(0.0, $this->score($this->response([]), $spec));
    }

    /**
     * max_raw is what a learner could actually collect, not the map's
     * positive total. A six-region item capped at two choices with four
     * regions mapped at 1.0 must be able to reach full marks.
     */
    public function testMaxRawRespectsTheInterfaceCap(): void
    {
        $spec = new MappingSpec(
            ['r1' => 1.0, 'r2' => 1.0, 'r3' => 1.0, 'r4' => 1.0],
            maxChoices: 2,
            universe: ['r1', 'r2', 'r3', 'r4', 'r5', 'r6'],
        );

        self::assertSame(2.0, $spec->maxRaw());
        self::assertSame(1.0, $this->score($this->response(['r1', 'r2']), $spec), 'the best permitted answer reaches full marks');
    }

    /**
     * "Credit every region except the hazardous one" has a positive total of
     * zero and would divide by it.
     */
    public function testAPositiveDefaultCountsTowardsTheAttainableMaximum(): void
    {
        $spec = new MappingSpec(
            ['hazard' => -1.0],
            defaultValue: 0.5,
            universe: ['hazard', 'a', 'b', 'c'],
        );

        self::assertSame(1.5, $spec->maxRaw());
        self::assertSame(1.0, $this->score($this->response(['a', 'b', 'c']), $spec));
    }

    public function testADegenerateMappingIsRefusedAtAuthoringTime(): void
    {
        $spec = new MappingSpec(['hazard' => -1.0], universe: ['hazard', 'a']);

        $this->expectException(ValidationException::class);
        $spec->validate();
    }

    public function testADegenerateMappingScoresNothingRatherThanDividingByZero(): void
    {
        $spec = new MappingSpec(['hazard' => -1.0], universe: ['hazard', 'a']);

        $result = $this->policy->score(
            $this->response(['a']),
            ScoringKey::mapped($spec),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
        self::assertSame('degenerate_mapping', $result->params['reason']);
    }
}
