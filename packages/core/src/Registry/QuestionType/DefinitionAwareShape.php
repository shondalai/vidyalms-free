<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The response shape of one authored item, for a type whose shape is a
 * property of the definition rather than of the type.
 *
 * `responseShape()` answers for the type, which is what the picker, the
 * policy guard and the generated catalogue ask about, and that is right for
 * almost everything: a numeric entry produces a numeric record whatever the
 * author writes. It is not right for every type, and the design says so in
 * as many words for the association: "multiple directed_pair when targets is
 * present, multiple pair when it is absent". Both are the same type and the
 * same primitive; the difference is whether the item joins two lists or one
 * list to itself, and that is authored.
 *
 * Nothing was asking. `questions.response_shape` is a real column written
 * per question, so an undirected association was stored as
 * multiple:directed_pair, which is the shape the fallback renderer keys on,
 * the shape the answer validator decodes under and the shape the QTI
 * exporter writes. The design's own `pair_up` preset authors exactly that
 * item.
 *
 * The interface is optional and additive: a type that does not implement it
 * keeps answering with `responseShape()`, and a third-party type never has
 * to know this exists. `ShapeForDefinition::of()` is the one place that
 * decides which of the two to ask, so no caller reimplements the precedence.
 */
interface DefinitionAwareShape
{
    /**
     * @param array<string, mixed> $definition
     */
    public function responseShapeFor(array $definition): ResponseShape;
}
