<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * The first of QTI's three response processing templates, and the free
 * build's existing single-choice and true-false behaviour under its
 * published name.
 *
 * fraction = 1.0 when the decoded response equals the key exactly under the
 * equality rule for its shape (set equality for multiple, sequence equality
 * for ordered, value equality for single, field-wise for record), else 0.0.
 * It always sets a value, including when the response is NULL, which is why
 * an unanswered single-choice question is a scored zero rather than an
 * absent mark.
 *
 * It refuses float shapes: the specification warns this template must not be
 * used for numerical equality of floats, and an author who reaches for it on
 * a numeric item wants a tolerance band instead.
 *
 * QTI template URI: .../rptemplates/match_correct.xml
 */
final class MatchCorrectPolicy implements ScoringPolicyInterface
{
    public const KEY = 'match_correct';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'All or nothing';
    }

    public function appliesToShapes(): array
    {
        return ScoringShapes::exactComparable();
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
        $correct = $this->equals($response, $key);

        return new RawScore(
            $correct ? 1.0 : 0.0,
            raw: $correct ? 1.0 : 0.0,
            min: 0.0,
            max: 1.0,
            floorFraction: $floor,
            params: [PolicyParameters::FLOOR_FRACTION => $floor],
        );
    }

    private function equals(ResponseValue $response, ScoringKey $key): bool
    {
        if ($response->shape->cardinality === Cardinality::Ordered) {
            return $response->members() === $key->members;
        }

        if ($response->shape->cardinality === Cardinality::Multiple) {
            $given = $response->distinctMembers();
            $wanted = array_values(array_unique($key->members));
            sort($given);
            sort($wanted);

            return $given === $wanted;
        }

        if ($response->shape->cardinality === Cardinality::Record) {
            return $response->fields() === (\is_array($key->value) ? $key->value : []);
        }

        if ($response->isNullOrEmpty()) {
            return false;
        }

        return $response->value === $key->value;
    }
}
