<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;

/**
 * Choosing from a list. On the flow surface that is a choice interaction; on
 * inline it is a dropdown or a clickable word; on media it is a named region
 * on an image.
 *
 * Three of QTI's nineteen predefined interactions are this primitive with a
 * different surface, and the free build's single choice, multiple choice and
 * true or false are all its flow form.
 */
final class SelectPrimitive extends AbstractInteractionPrimitive
{
    public function key(): string
    {
        return InteractionPrimitiveRegistry::SELECT;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Inline, Surface::Media];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::Identifier),
            ResponseShape::of(Cardinality::Single, BaseType::Boolean),
            ResponseShape::of(Cardinality::Multiple, BaseType::Identifier),
        ];
    }

    protected function shuffledFields(array $definition = []): array
    {
        // options on a choice list, choices on an inline gap, regions on a
        // hotspot. A type that shuffles a field none of these names simply
        // gets no sequence, which is the safe default.
        return ['options', 'choices', 'regions'];
    }

    /**
     * The same three lists, which are also the identifiers a response may
     * name. They are declared separately because shuffledFields is what the
     * item wants shuffled and this is what the item offers, and for other
     * families those two are not the same list.
     */
    protected function identifierFields(array $definition = []): array
    {
        return ['options', 'choices', 'regions'];
    }
}
