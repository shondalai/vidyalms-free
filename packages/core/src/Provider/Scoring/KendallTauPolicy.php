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
 * m = 1 - discordant_pairs / (n(n-1)/2), chance corrected against a baseline
 * of exactly 0.5 for every n.
 *
 * The default for new ordering items, because it is a true metric,
 * symmetric in the two sequences and insensitive to where in the list the
 * error occurred, and because its baseline is a constant at every n, which
 * makes the correction exact rather than sampled. All-or-nothing on an
 * eight-item ordering is effectively unmarkable.
 *
 * The baseline actually used is written to the grade row, so a regrade
 * reproduces the mark exactly.
 */
final class KendallTauPolicy implements ScoringPolicyInterface
{
    public const KEY = 'kendall_tau';

    /** Exactly half the pairs are discordant under a uniform permutation, for every n. */
    public const BASELINE = 0.5;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Kendall tau';
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
                'description' => 'Subtracts the guessing baseline. Off only where reproducing a '
                    . 'migrating teacher\'s marks is the point.',
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
            throw \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::forField(
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
        $applied = [
            PolicyParameters::FLOOR_FRACTION => $floor,
            OrderingMetric::PARAM_CHANCE_CORRECTED => $corrected,
            'baseline' => self::BASELINE,
        ];

        if ($expected === []) {
            return RawScore::notScored($applied + ['reason' => 'no_key']);
        }

        $given = $response->members();

        // The permutation check runs before the policy, in the type, and a
        // response that fails it never reaches here. Checking again costs
        // nothing and keeps the policy correct when a third-party type calls
        // it directly.
        if (!OrderingMetric::isPermutationOf($given, $expected)) {
            return new RawScore(0.0, raw: 0.0, min: 0.0, max: 1.0, floorFraction: $floor, params: $applied + [
                'reason' => 'not_a_permutation',
            ]);
        }

        $metric = OrderingMetric::kendallTau($given, $expected);

        return new RawScore(
            OrderingMetric::correct($metric, self::BASELINE, $corrected),
            raw: $metric,
            min: 0.0,
            max: 1.0,
            floorFraction: $floor,
            params: $applied,
        );
    }
}
