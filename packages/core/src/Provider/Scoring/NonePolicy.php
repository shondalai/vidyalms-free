<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * Scores nothing: raw 0, min 0, max 0.
 *
 * The policy an opinion scale, a media engagement control and an
 * acknowledgement use. A question whose policy is none must carry
 * scored = 0, and that pairing is what makes a survey question authorable
 * without moving the pass percentage: the totals exclude an unscored item
 * from both sums, so a scale with no right answer is neither a free mark
 * nor a lost one.
 */
final class NonePolicy implements ScoringPolicyInterface
{
    public const KEY = PolicyResolution::SCORES_NOTHING;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Not scored';
    }

    public function appliesToShapes(): array
    {
        return [];
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
        // raw 0.0, min 0.0, max 0.0, explicitly. The generic notScored()
        // leaves min and max null, and those values reach
        // attempt_grades.min_score, so the append-only history recorded an
        // absent bound where the design pins a zero. A row that cannot say
        // what the bounds were is a row a dispute cannot be answered from.
        return new RawScore(
            0.0,
            raw: 0.0,
            min: 0.0,
            max: 0.0,
            status: ScoreStatus::NotScored,
            params: ['policy' => self::KEY],
        );
    }
}
