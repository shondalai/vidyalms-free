<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerError;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\ErrorKind;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;

/**
 * A value on a bounded scale: a slider, a rating, a confidence level.
 *
 * The bounds are enforced here rather than in a type, so OUT_OF_RANGE means
 * the same thing wherever a scale appears.
 */
final class ScalarPrimitive extends AbstractInteractionPrimitive
{
    public const CONSTRAINT_LOWER = 'lower';
    public const CONSTRAINT_UPPER = 'upper';

    public function key(): string
    {
        return InteractionPrimitiveRegistry::SCALAR;
    }

    public function surfaces(): array
    {
        return [Surface::Flow];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::Float),
            ResponseShape::of(Cardinality::Single, BaseType::Integer),
        ];
    }

    public function projectConstraints(array $definition): array
    {
        $constraints = parent::projectConstraints($definition);

        foreach ([self::CONSTRAINT_LOWER, self::CONSTRAINT_UPPER] as $field) {
            $value = $definition[$field] ?? null;

            if (\is_int($value) || \is_float($value)) {
                $constraints[$field] = (float) $value;
            }
        }

        return $constraints;
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $controlId = \is_string($constraints[self::CONSTRAINT_CONTROL_ID] ?? null)
            ? $constraints[self::CONSTRAINT_CONTROL_ID]
            : $this->key();
        $number = $value->asFloat();

        if ($number === null) {
            return AnswerValidity::invalid([
                AnswerError::of($controlId, ErrorKind::ValueNotNumeric, $this->say(NeutralStrings::ERROR_VALUE_NOT_NUMERIC)),
            ]);
        }

        $lower = $constraints[self::CONSTRAINT_LOWER] ?? null;
        $upper = $constraints[self::CONSTRAINT_UPPER] ?? null;

        if ((\is_float($lower) && $number < $lower) || (\is_float($upper) && $number > $upper)) {
            return AnswerValidity::invalid([
                AnswerError::of($controlId, ErrorKind::OutOfRange, $this->say(NeutralStrings::ERROR_OUT_OF_RANGE)),
            ]);
        }

        return AnswerValidity::valid();
    }

    protected function shuffledFields(array $definition = []): array
    {
        return [];
    }
}
