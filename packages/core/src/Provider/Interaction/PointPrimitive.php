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
 * Free points on a graphic, with the origin at top left.
 *
 * The learner projection of a point-marking item carries no geometry at all,
 * which is the one place the correctness invariant is stricter for graphics
 * than for text: a visible region may travel with its shape and coordinates,
 * because the learner can see it; a hidden target may not, because the
 * coordinates are the answer.
 */
final class PointPrimitive extends AbstractInteractionPrimitive
{
    public function key(): string
    {
        return InteractionPrimitiveRegistry::POINT;
    }

    public function surfaces(): array
    {
        return [Surface::Media];
    }

    public function shapes(): array
    {
        return [ResponseShape::of(Cardinality::Multiple, BaseType::Point)];
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $points = \is_array($value->value) ? $value->value : [];

        return $this->checkCardinality($constraints, \count($points));
    }

    protected function shuffledFields(array $definition = []): array
    {
        return [];
    }
}
