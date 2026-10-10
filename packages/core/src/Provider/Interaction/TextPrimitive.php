<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;

/**
 * Typed text. Inline it is a text entry; in flow it is an extended
 * response.
 *
 * Decoding is locale sensitive, which is why DecodeContext carries the
 * locale: a learner in a comma-decimal locale types "5,5" and a numeric mask
 * hardcoding a dot separator rejects a correctly written answer.
 */
final class TextPrimitive extends AbstractInteractionPrimitive
{
    public function key(): string
    {
        return InteractionPrimitiveRegistry::TEXT;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Inline];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::StringValue),
            ResponseShape::anyRecord(),
        ];
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        // A record of blanks names the blanks it is filling, so a record
        // whose fields are not this item's blanks is an answer to a different
        // question. It was accepted as answered: a numeric answer, a
        // recording and an extended response are all records, and a fill in
        // the blank item read every one of them as filled in.
        //
        // The check runs only where the item declares blanks at all, which
        // leaves an extended response, whose record fields are its own and
        // are the type's business, exactly as it was.
        $unknown = $this->unknownIdentifiers($constraints, array_keys($value->fields()));

        if ($unknown !== []) {
            return AnswerValidity::invalid($unknown);
        }

        // A typed answer has no cardinality to violate. Word and character
        // limits are authored constraints a type contributes its own error
        // kind for, which is why ErrorKind is closed but not exhaustive.
        return AnswerValidity::valid();
    }

    /**
     * The blanks of a fill in the blank item, which are what a record
     * response to one may name. A type with no blanks (an extended response,
     * a short answer) declares none and the membership check does nothing.
     */
    protected function identifierFields(array $definition = []): array
    {
        return ['blanks'];
    }

    protected function shuffledFields(array $definition = []): array
    {
        return [];
    }
}
