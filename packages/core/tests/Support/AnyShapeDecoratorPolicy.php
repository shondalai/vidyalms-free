<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * A policy that declares itself a decorator over shape "any", which is what
 * certainty weighting, the two rubric policies and an outcome lookup all are.
 *
 * It stands in for a paid or third-party decorator registered after boot, so
 * a test can assert the thing the design says must be true: such a policy is
 * applicable wherever its base policy is supported, without any shipped
 * catalogue entry being edited to list it.
 */
final class AnyShapeDecoratorPolicy implements ScoringPolicyInterface
{
    public const KEY = 'x_acme_certainty';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Certainty weighted';
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
        return RawScore::zero(['policy' => self::KEY]);
    }
}
