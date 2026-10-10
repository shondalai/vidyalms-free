<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * m = exact position matches over n, with a chance baseline of 1/n.
 *
 * Offered, never the default, and free because the free-build ordering
 * migration pins existing questions to it with the correction off. That pin
 * is what keeps historic percentages exactly where they were while new items
 * get the chance-corrected default.
 */
final class AbsolutePositionPolicy implements ScoringPolicyInterface
{
    public const KEY = 'absolute_position';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Exact position';
    }

    public function appliesToShapes(): array
    {
        return ScoringShapes::orderedContainers();
    }

    public function paramsSchema(): array
    {
        return PolicyParameters::schema([
            OrderingMetric::PARAM_CHANCE_CORRECTED => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Subtracts the 1/n guessing baseline. Pinned off by the free-build '
                    . 'migration so no historic percentage moves.',
            ],
        ]);
    }

    public function defaultParams(): array
    {
        return [
            PolicyParameters::FLOOR_FRACTION => 0.0,
            OrderingMetric::PARAM_CHANCE_CORRECTED => true,
        ];
    }

    public function validateParams(array $params): void
    {
        PolicyParameters::rejectUnknown($params, [OrderingMetric::PARAM_CHANCE_CORRECTED], self::KEY);

        if (
            \array_key_exists(OrderingMetric::PARAM_CHANCE_CORRECTED, $params)
            && !\is_bool($params[OrderingMetric::PARAM_CHANCE_CORRECTED])
        ) {
            throw ValidationException::forField(
                'policy.params.chance_corrected',
                'The chance correction switch is a boolean.',
            );
        }
    }

    public function score(ResponseValue $response, ScoringKey $key, array $params, ScoringContext $context): RawScore
    {
        $floor = PolicyParameters::floor($params);
        $corrected = ($params[OrderingMetric::PARAM_CHANCE_CORRECTED] ?? true) === true;
        $expected = $key->members;
        $n = \count($expected);
        $baseline = $n > 0 ? 1 / $n : 0.0;
        $applied = [
            PolicyParameters::FLOOR_FRACTION => $floor,
            OrderingMetric::PARAM_CHANCE_CORRECTED => $corrected,
            'baseline' => $baseline,
        ];

        if ($n === 0) {
            return RawScore::notScored($applied + ['reason' => 'no_key']);
        }

        $given = $response->members();

        if (!OrderingMetric::isPermutationOf($given, $expected)) {
            return new RawScore(0.0, raw: 0.0, min: 0.0, max: 1.0, floorFraction: $floor, params: $applied + [
                'reason' => 'not_a_permutation',
            ]);
        }

        $metric = OrderingMetric::absolutePosition($given, $expected);

        return new RawScore(
            OrderingMetric::correct($metric, $baseline, $corrected),
            raw: $metric,
            min: 0.0,
            max: 1.0,
            floorFraction: $floor,
            params: $applied,
        );
    }
}
