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
 * fraction = max(0, C - I * |K| / |D|) / |K|.
 *
 * Selecting everything gives exactly zero for any option balance, which
 * neither the naive balanced formula nor plus_minus does, and that property
 * is why this is the default for new multiple-response items.
 *
 * The penalty weight is |K| / |D|, and it grows without bound as distractors
 * get scarce. With four keys and one distractor a single wrong tick costs
 * the entire item: a learner who found three of the four keys and made one
 * error scores 0, while a learner who ticked one key and stopped scores
 * 0.25. validateDefinition rejects |D| = 0 as degenerate, and a publication
 * check warns when |D| < |K|.
 */
final class GuessNeutralPolicy implements ScoringPolicyInterface
{
    public const KEY = 'guess_neutral';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Guess neutral';
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
        $distractors = $key->distractorCount();
        $applied = [
            PolicyParameters::FLOOR_FRACTION => $floor,
            'keys' => \count($correct),
            'distractors' => $distractors,
        ];

        if ($correct === []) {
            return RawScore::notScored($applied + ['reason' => 'no_key']);
        }

        // A degenerate item, refused at authoring time, still has to grade
        // rather than divide by zero on a row that predates the check.
        if ($distractors === 0) {
            return RawScore::notScored($applied + ['reason' => 'no_distractors']);
        }

        $selected = $response->distinctMembers();
        $hits = \count(array_intersect($selected, $correct));
        $misses = \count($selected) - $hits;
        $penalty = $misses * (\count($correct) / $distractors);
        $raw = $hits - $penalty;

        return new RawScore(
            max(0.0, $raw) / \count($correct),
            raw: $raw,
            min: 0.0,
            max: (float) \count($correct),
            floorFraction: $floor,
            params: $applied,
        );
    }
}
