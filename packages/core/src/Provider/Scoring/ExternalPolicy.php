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
 * No fraction. Sets session status pending_external_scoring, which is what
 * puts the item in the human grading queue.
 *
 * It is deterministic, so the places that ask whether a human is needed get
 * a stable, cheap answer. Sharing one provider path is what lets the
 * extended response, upload, drawing and recording types stop hardcoding a
 * boolean inside grade() each.
 *
 * The score status is notscored rather than scored-at-zero, because an
 * automatic zero standing in for a pending human grade is exactly what made
 * an attempt with an ungraded essay read as fully graded.
 */
final class ExternalPolicy implements ScoringPolicyInterface
{
    public const KEY = 'external';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Marked by a human';
    }

    public function appliesToShapes(): array
    {
        // A decorator over any shape: a human can mark any response.
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
        return RawScore::pendingHuman([PolicyParameters::FLOOR_FRACTION => PolicyParameters::floor($params)]);
    }
}
