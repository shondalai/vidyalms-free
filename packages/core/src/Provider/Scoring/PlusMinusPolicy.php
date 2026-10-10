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
 * raw = |R and K| - |R minus K|; fraction = max(0, raw) / |K|.
 *
 * The NCSBN Select All That Apply rule, floored at zero. Published,
 * operational in a high-stakes licensure exam, and supported by the Betts,
 * Muntean, Kim and Kao comparison.
 *
 * It is the free build's original multiple-choice formula under a published
 * name, which is why it is free and why it is not the new default.
 * min(1, max(0, C - I) / |K|) and max(0, C - I) / |K| are the same function,
 * since C never exceeds |K| and the min therefore never binds. Both pay
 * 0.667 for ticking all four options of a three-key, one-distractor item, so
 * plus_minus does not close the tick-everything hole. It is kept under the
 * NCSBN name for migration fidelity, and it is the pin existing questions
 * get at migration time so no historic percentage moves.
 */
final class PlusMinusPolicy implements ScoringPolicyInterface
{
    public const KEY = 'plus_minus';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Plus and minus';
    }

    public function appliesToShapes(): array
    {
        return ScoringShapes::identifierContainers();
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
        $correct = array_values(array_unique($key->members));
        $applied = [PolicyParameters::FLOOR_FRACTION => $floor, 'keys' => \count($correct)];

        if ($correct === []) {
            return RawScore::notScored($applied + ['reason' => 'no_key']);
        }

        $selected = $response->distinctMembers();
        $hits = \count(array_intersect($selected, $correct));
        $misses = \count($selected) - $hits;
        $raw = $hits - $misses;

        return new RawScore(
            max(0, $raw) / \count($correct),
            raw: (float) $raw,
            min: (float) -\count($selected),
            max: (float) \count($correct),
            floorFraction: $floor,
            params: $applied,
        );
    }
}
