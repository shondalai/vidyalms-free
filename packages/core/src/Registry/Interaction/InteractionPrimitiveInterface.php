<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One of the eight interaction primitives the whole catalogue is built from.
 *
 * Eight primitives crossed with three surfaces generate every predefined QTI
 * interaction, verified interaction by interaction. The surface modifier is
 * the reduction every surveyed product misses: Moodle, Canvas, Blackboard
 * and D2L each ship the six graphic interactions as six separate types with
 * six separate implementations of decoding, constraint checking and hit
 * testing.
 *
 * This is where min_choices, max_choices, min_associations, max_associations
 * and per-choice match_max are enforced: once per primitive, for every type
 * that uses it, including a third-party type whose author never read the
 * specification.
 *
 * presentationSequence lives here rather than on the type because shuffle is
 * a quiz policy that has to reach the item: core resolves the policy, calls
 * the primitive, honours the per-choice fixed flag there once for every type
 * that uses that primitive, and writes the result to
 * attempt_questions.choice_sequence. A third-party type inherits seeded
 * shuffle that respects fixed without writing a line of it.
 */
interface InteractionPrimitiveInterface extends ProviderInterface
{
    /**
     * @return list<Surface>
     */
    public function surfaces(): array;

    /**
     * @return list<ResponseShape>
     */
    public function shapes(): array;

    public function decodeResponse(mixed $wire, ResponseShape $shape, DecodeContext $context): ResponseValue;

    /**
     * @param array<string, mixed> $constraints from projectConstraints()
     */
    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity;

    /**
     * Lifts the constraint fields out of a type's own definition into the
     * vocabulary this primitive enforces, so the type states its constraints
     * in its own field names and the primitive never learns them.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function projectConstraints(array $definition): array;

    /**
     * @param array<string, mixed> $definition
     */
    public function presentationSequence(array $definition, Seed $seed, ShufflePolicy $shuffle): ChoiceSequence;
}
