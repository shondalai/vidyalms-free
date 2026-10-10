<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * The second QTI template: per-value weights.
 *
 *   raw      = clamp( sum over DISTINCT members v of R of
 *                     ( map[v] if present else default_value ),
 *                     lower_bound, upper_bound )
 *   fraction = clamp( raw / max_raw, floor_fraction, 1.0 )
 *
 * Five rules must be implemented exactly or partial credit is subtly wrong
 * everywhere, and this is the one implementation with the one set of tests
 * that keeps them consistent across every type that maps:
 *
 *  1. Distinct members only. With {A:0, B:1, C:0.5, D:0}, {C} maps to 0.5,
 *     {C,B} to 1.5, and {B,B,C} also to 1.5, not 2.5.
 *  2. Sum first, then clamp, in that order.
 *  3. default_value applies to any submitted value with no entry, which is
 *     how an unlisted distractor is penalised.
 *  4. String keys compare case-insensitively unless the entry sets
 *     case_sensitive.
 *  5. A NULL response scores 0.0 without consulting the map at all.
 *
 * max_raw is the maximum a learner could actually collect on this item, not
 * the map's positive total; MappingSpec owns that arithmetic and its
 * reasoning.
 */
final class MapResponsePolicy implements ScoringPolicyInterface
{
    public const KEY = 'map_response';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Weighted per option';
    }

    public function appliesToShapes(): array
    {
        return ScoringShapes::mappable();
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
        $spec = $key->mappingSpec;

        if ($spec === null) {
            return RawScore::notScored(['policy' => self::KEY, 'reason' => 'no_mapping']);
        }

        $applied = [
            PolicyParameters::FLOOR_FRACTION => $floor,
            'default_value' => $spec->defaultValue,
            'lower_bound' => $spec->lowerBound,
            'upper_bound' => $spec->upperBound,
            'max_choices' => $spec->maxChoices,
        ];

        // Rule 5: NULL yields 0.0 without consulting the map.
        if ($response->isNullOrEmpty()) {
            return new RawScore(0.0, raw: 0.0, min: 0.0, max: $spec->maxRaw(), floorFraction: $floor, params: $applied);
        }

        $raw = 0.0;

        // Rule 1: distinct members only.
        foreach ($response->distinctMembers() as $member) {
            // Rules 3 and 4 both live in MappingSpec::valueOf().
            $raw += $spec->valueOf($member);
        }

        // Rule 2: sum first, then clamp.
        $raw = $spec->clampRaw($raw);
        $maxRaw = $spec->maxRaw();

        if ($maxRaw <= 0.0) {
            return RawScore::notScored($applied + ['reason' => 'degenerate_mapping']);
        }

        return new RawScore(
            $raw / $maxRaw,
            raw: $raw,
            min: $spec->lowerBound,
            max: $maxRaw,
            floorFraction: $floor,
            params: $applied,
        );
    }
}
