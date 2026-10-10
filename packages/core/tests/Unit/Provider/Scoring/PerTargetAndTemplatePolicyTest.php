<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseField;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\Scoring\ExternalPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\MatchCorrectPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\NonePolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\PerTargetPolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TargetKey;

final class PerTargetAndTemplatePolicyTest extends TestCase
{
    private function recordShape(): ResponseShape
    {
        return ResponseShape::record([new ResponseField('gap', Cardinality::Single, BaseType::StringValue)]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function record(array $fields): ResponseValue
    {
        return ResponseValue::of($this->recordShape(), $fields);
    }

    /**
     * The denominator is |K| and never the target count. An item with five
     * targets and three keyed capped a perfect answer at 0.6.
     */
    public function testTheDenominatorIsTheKeyedTargetCount(): void
    {
        $policy = new PerTargetPolicy();

        $key = ScoringKey::targets([
            'z1' => TargetKey::exactly(['label_a']),
            'z2' => TargetKey::exactly(['label_b']),
            'z3' => TargetKey::exactly(['label_c']),
            'decoy_1' => new TargetKey([]),
            'decoy_2' => new TargetKey([]),
        ]);

        $result = $policy->score(
            $this->record(['z1' => 'label_a', 'z2' => 'label_b', 'z3' => 'label_c']),
            $key,
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(1.0, $result->fraction);
        self::assertSame(3, $result->params['targets']);
    }

    /**
     * Exact set equality means a superset of a multi-valued target's key
     * scores zero for that target, which is what stops the mark-every-word
     * response being read as containment.
     */
    public function testASupersetOfAMultiValuedTargetScoresZeroForThatTarget(): void
    {
        $policy = new PerTargetPolicy();

        $key = ScoringKey::targets([
            'g1' => TargetKey::exactly(['alpha', 'beta']),
            'g2' => TargetKey::exactly(['gamma']),
        ]);

        $result = $policy->score(
            $this->record(['g1' => ['alpha', 'beta', 'delta'], 'g2' => 'gamma']),
            $key,
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.5, $result->fraction);
    }

    public function testAnyOfAcceptsOneOfTheAcceptedSpellings(): void
    {
        $policy = new PerTargetPolicy();

        $key = ScoringKey::targets([
            'one' => TargetKey::anyOf(['alpha', 'a']),
            'two' => TargetKey::anyOf(['beta']),
        ]);

        $result = $policy->score(
            $this->record(['one' => 'A', 'two' => 'gamma']),
            $key,
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.5, $result->fraction);
    }

    public function testNoKeyedTargetIsNotScoredRatherThanZero(): void
    {
        $policy = new PerTargetPolicy();

        $result = $policy->score(
            $this->record(['g1' => 'anything']),
            ScoringKey::targets(['g1' => new TargetKey([])]),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(ScoreStatus::NotScored, $result->status);
    }

    public function testMatchCorrectComparesSetsForMultipleAndSequencesForOrdered(): void
    {
        $policy = new MatchCorrectPolicy();
        $params = $policy->defaultParams();

        $multiple = ResponseValue::of(
            ResponseShape::of(Cardinality::Multiple, BaseType::Identifier),
            ['b', 'a'],
        );
        self::assertSame(1.0, $policy->score($multiple, ScoringKey::set(['a', 'b']), $params, ScoringContext::defaults())->fraction);

        $ordered = ResponseValue::of(
            ResponseShape::of(Cardinality::Ordered, BaseType::Identifier),
            ['b', 'a'],
        );
        self::assertSame(0.0, $policy->score($ordered, ScoringKey::sequence(['a', 'b']), $params, ScoringContext::defaults())->fraction);
    }

    public function testMatchCorrectAlwaysSetsAValueIncludingForANullResponse(): void
    {
        $policy = new MatchCorrectPolicy();
        $shape = ResponseShape::of(Cardinality::Single, BaseType::Identifier);

        $result = $policy->score(
            ResponseValue::null($shape),
            ScoringKey::value('a'),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
        self::assertSame(ScoreStatus::Scored, $result->status, 'a scored zero, not an absent mark');
    }

    /**
     * The specification warns this template must not be used for numerical
     * equality of floats, so it is not offered on a float shape at all.
     */
    public function testMatchCorrectIsNotOfferedOnAFloatShape(): void
    {
        $policy = new MatchCorrectPolicy();
        $float = ResponseShape::of(Cardinality::Single, BaseType::Float);

        foreach ($policy->appliesToShapes() as $shape) {
            self::assertFalse($shape->matches($float));
        }
    }

    public function testExternalSetsPendingExternalScoringAndNoFraction(): void
    {
        $policy = new ExternalPolicy();

        $result = $policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::StringValue), 'an essay'),
            ScoringKey::value(null),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertTrue($result->sessionStatus->awaitsHuman());
        self::assertSame(ScoreStatus::NotScored, $result->status);
        self::assertNull($result->raw);
    }

    public function testNoneScoresNothingAtAll(): void
    {
        $policy = new NonePolicy();

        $result = $policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::Identifier), 'agree'),
            ScoringKey::value(null),
            $policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(0.0, $result->fraction);
        self::assertSame(ScoreStatus::NotScored, $result->status);
    }

    /**
     * A parameter has exactly one home, and the message names the right one.
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignParameters(): iterable
    {
        yield 'default_value' => ['default_value'];
        yield 'lower_bound' => ['lower_bound'];
        yield 'upper_bound' => ['upper_bound'];
        yield 'tolerance' => ['tolerance'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foreignParameters')]
    public function testAParameterThatBelongsSomewhereElseIsRefused(string $name): void
    {
        $policy = new MatchCorrectPolicy();

        $this->expectException(ValidationException::class);
        $policy->validateParams([$name => 1.0]);
    }

    public function testEveryFreePolicyCarriesAFloorFractionDefaultingToZero(): void
    {
        foreach (\Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies::all() as $policy) {
            self::assertArrayHasKey(PolicyParameters::FLOOR_FRACTION, $policy->defaultParams(), $policy->key());
            self::assertSame(0.0, $policy->defaultParams()[PolicyParameters::FLOOR_FRACTION], $policy->key());
        }
    }
}
