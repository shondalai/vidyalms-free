<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A marking rule, separate from the interaction that produced the response.
 *
 * QuestionTypeInterface::grade() used to weld the two together, so adding
 * right-minus-wrong to matching meant editing matching and a third-party
 * type would have had to reimplement every policy inside itself. QTI's own
 * answer is the model: a response declaration says what the answer is worth
 * and a separately named response processing template says how that becomes
 * a score.
 *
 * Policies bind to response shapes, never to type keys. That is what makes
 * three implementations cover most of the catalogue, and what makes the
 * mapping semantics everybody must get exactly right (distinct members
 * counted once, sum then clamp, default_value for unlisted values, NULL
 * before the formula) one implementation with one set of tests rather than a
 * subtle inconsistency spread across a catalogue.
 */
interface ScoringPolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The shapes this policy may be offered on. An empty list means any
     * shape: a decorator over a base policy is applicable wherever its base
     * is, and requiring every catalogue entry to list it is precisely the
     * fork this design forbids.
     *
     * @return list<ResponseShape>
     */
    public function appliesToShapes(): array;

    /**
     * A JSON Schema for policy.params, with additionalProperties false, so
     * the parameters are validated rather than being a free-form object.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * Always contains floor_fraction, defaulting to 0. The name is
     * deliberately not lower_bound, which is a raw score clamp owned by the
     * mapping object; reusing one word for two quantities is how the two got
     * confused.
     *
     * @return array<string, mixed>
     */
    public function defaultParams(): array;

    /**
     * Rejects a parameter that has a different home, naming the correct
     * location: mapping owns default_value, lower_bound and upper_bound;
     * scalar owns tolerance; numeric owns accepted[].tolerance. The same
     * authored item scoring two different marks depending on which of two
     * locations the engine reads is not a documentation problem.
     *
     * @param array<string, mixed> $params
     *
     * @throws \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException
     */
    public function validateParams(array $params): void;

    /**
     * @param array<string, mixed> $params
     */
    public function score(ResponseValue $response, ScoringKey $key, array $params, ScoringContext $context): RawScore;
}
