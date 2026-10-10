<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The provider's own account of its definition: the schema, which keys the
 * learner may see, which keys carry correctness, and the shape a response
 * takes.
 *
 * This is what moves the catalogue from a shipped file to the providers
 * themselves. Making one core file own every type's schema, projection
 * allowlist and secret-key list would mean adding a type edits core, the
 * free build ships every Pro secret-key list, and a third-party type gets no
 * coverage from the answer-hiding test, which is the strongest safety
 * property in the design and the one that most needs to apply to code nobody
 * has reviewed.
 *
 * The structural answer-hiding test walks the registry and drives itself
 * from this interface, so a third-party type is covered by construction.
 */
interface DefinitionContractInterface
{
    /**
     * A JSON Schema document for the authored definition, with
     * additionalProperties false.
     *
     * @return array<string, mixed>
     */
    public function definitionSchema(): array;

    /**
     * Every top-level definition key presentForLearner() may copy through.
     * The answer-hiding test asserts the presented shape uses no other key.
     *
     * @return list<string>
     */
    public function presentableKeys(): array;

    /**
     * Every definition key that carries correctness, at any depth, named by
     * its own leaf name. The answer-hiding test asserts none of them appears
     * anywhere in the encoded projection.
     *
     * @return list<string>
     */
    public function secretKeys(): array;

    public function responseShape(): ResponseShape;

    /**
     * A definition the schema accepts, used by the structural test and by
     * the catalogue generator so neither has to hand-write one per type.
     * It must carry a value under every secret key the type declares, or the
     * hiding assertion proves nothing.
     *
     * @return array<string, mixed>
     */
    public function exampleDefinition(): array;
}
