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
 * An acknowledgement rather than an answer: a play count, or an end-attempt
 * control.
 *
 * QTI's media and end-attempt interactions are both this primitive, and both
 * are the case the design's "a question whose policy is none must carry
 * scored = 0" rule exists for.
 */
final class SignalPrimitive extends AbstractInteractionPrimitive
{
    public function key(): string
    {
        return InteractionPrimitiveRegistry::SIGNAL;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Media];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::Integer),
            ResponseShape::of(Cardinality::Single, BaseType::Boolean),
        ];
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        return $value->isNullOrEmpty() ? AnswerValidity::empty() : AnswerValidity::valid();
    }

    protected function shuffledFields(array $definition = []): array
    {
        return [];
    }
}
