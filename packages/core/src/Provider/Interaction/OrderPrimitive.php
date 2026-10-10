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
use Shondalai\VidyaLMS\Core\Provider\Scoring\OrderingMetric;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;

/**
 * Arranging items into a sequence, in a list or over a graphic.
 *
 * The permutation check lives here, which is the point of putting it in a
 * primitive: it runs before any policy, for every type that orders anything,
 * including a third-party one. Without it [A,A,A,A] against a key of
 * [A,B,C,D] scores 0.25 under absolute position, a quarter of the marks for
 * a response that is not an ordering at all.
 */
final class OrderPrimitive extends AbstractInteractionPrimitive
{
    public const CONSTRAINT_UNIVERSE = 'universe';

    public function key(): string
    {
        return InteractionPrimitiveRegistry::ORDER;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Media];
    }

    public function shapes(): array
    {
        return [ResponseShape::of(Cardinality::Ordered, BaseType::Identifier)];
    }

    public function projectConstraints(array $definition): array
    {
        $constraints = parent::projectConstraints($definition);
        $keys = [];

        foreach (['items', 'regions'] as $field) {
            $entries = $definition[$field] ?? null;

            if (!\is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $id = self::identifierOf($entry);

                if ($id !== null) {
                    $keys[] = $id;
                }
            }
        }

        if ($keys !== []) {
            $constraints[self::CONSTRAINT_UNIVERSE] = $keys;
        }

        return $constraints;
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $universe = $constraints[self::CONSTRAINT_UNIVERSE] ?? null;

        if (\is_array($universe) && $universe !== []) {
            /** @var list<string> $expected */
            $expected = array_values(array_filter($universe, '\is_string'));

            if (!OrderingMetric::isPermutationOf($value->members(), $expected)) {
                $controlId = \is_string($constraints[self::CONSTRAINT_CONTROL_ID] ?? null)
                    ? $constraints[self::CONSTRAINT_CONTROL_ID]
                    : $this->key();

                return AnswerValidity::invalid([
                    AnswerError::of(
                        $controlId,
                        ErrorKind::InvalidPermutation,
                        $this->say(NeutralStrings::ERROR_INVALID_PERMUTATION),
                    ),
                ]);
            }
        }

        return parent::validateAnswer($constraints, $value, $context);
    }

    protected function shuffledFields(array $definition = []): array
    {
        return ['items', 'regions'];
    }
}
