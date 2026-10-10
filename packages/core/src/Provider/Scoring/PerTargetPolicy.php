<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TargetKey;

/**
 * Defined once, here, and referenced rather than restated by every type that
 * uses it, because four per-type restatements had already drifted into three
 * different denominators.
 *
 *   fraction = |{ t in K : R(t) equals K(t) }| / |K|
 *
 * where K is the key's target set. No penalty. Correct only where
 * over-assignment is structurally impossible: fixed gaps, one value per gap,
 * one-to-one matching, drop-down cells.
 *
 * The denominator is |K| and never the target count. Where it was the target
 * count, an unkeyed target made full marks unreachable: an image-labelling
 * item with five zones, three keyed and two decoys, capped a perfect answer
 * at 0.6. validateDefinition refuses an unkeyed target unless the author
 * marks it explicitly unscored, in which case it leaves the denominator too.
 *
 * Equal-weight fractions are computed once at the end as an exact rational,
 * never a rounded per-item value summed.
 */
final class PerTargetPolicy implements ScoringPolicyInterface
{
    public const KEY = 'per_target';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'One mark per target';
    }

    public function appliesToShapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Multiple, BaseType::DirectedPair),
            ResponseShape::of(Cardinality::Multiple, BaseType::Pair),
            ResponseShape::anyRecord(),
        ];
    }

    public function paramsSchema(): array
    {
        return PolicyParameters::schema([]);
    }

    public function defaultParams(): array
    {
        return [PolicyParameters::FLOOR_FRACTION => 0.0];
    }

    public function validateParams(array $params): void
    {
        PolicyParameters::rejectUnknown($params, [], self::KEY);
    }

    public function score(ResponseValue $response, ScoringKey $key, array $params, ScoringContext $context): RawScore
    {
        $floor = PolicyParameters::floor($params);
        $targets = $key->scoredTargets();
        $applied = [PolicyParameters::FLOOR_FRACTION => $floor, 'targets' => \count($targets)];

        if ($targets === []) {
            return RawScore::notScored($applied + ['reason' => 'no_keyed_targets']);
        }

        $given = $response->fields();
        $matched = 0;

        foreach ($targets as $name => $target) {
            if ($target->matches($given[$name] ?? null)) {
                ++$matched;
            }
        }

        return new RawScore(
            $matched / \count($targets),
            raw: (float) $matched,
            min: 0.0,
            max: (float) \count($targets),
            floorFraction: $floor,
            params: $applied,
        );
    }

    /**
     * Kept beside the formula so the two cannot drift: the equality rule for
     * one target is TargetKey's, and a superset of a multi-valued target's
     * key scores zero for that target.
     */
    public static function matches(TargetKey $target, mixed $response): bool
    {
        return $target->matches($response);
    }
}
