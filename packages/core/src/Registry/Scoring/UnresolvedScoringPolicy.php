<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;

/**
 * The null object of the scoring registry.
 *
 * Every registry defines its miss, not only the type registry. A scoring
 * policy key is written into a real questions.scoring_policy column, so the
 * miss arrives through a free-typed question authored with a Pro policy long
 * after Pro is gone. Marking that answer wrong would be a silent mark
 * change; this scores raw 0 with score status notscored and an explanatory
 * row naming the missing key, which every total and every report treats as
 * "not marked" rather than "got it wrong".
 */
final class UnresolvedScoringPolicy implements ScoringPolicyInterface
{
    public function __construct(private readonly string $missingKey)
    {
    }

    public function key(): string
    {
        return $this->missingKey === '' ? 'unresolved' : $this->missingKey;
    }

    public function label(): string
    {
        return 'Unavailable scoring policy';
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
        // Nothing to validate: the provider that owns these parameters is
        // not installed, and refusing the definition would make the row
        // unauthorable rather than unmarkable.
    }

    public function score(ResponseValue $response, ScoringKey $key, array $params, ScoringContext $context): RawScore
    {
        return RawScore::notScored([
            'policy' => $this->missingKey,
            'reason' => 'unresolved_scoring_policy',
        ]);
    }

    public function missingKey(): string
    {
        return $this->missingKey;
    }
}
