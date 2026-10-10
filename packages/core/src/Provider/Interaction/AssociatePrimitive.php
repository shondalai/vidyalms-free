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
 * Linking one set to another, or to itself: matching, categorization, gap
 * match, label placement and graphic association are all this primitive, on
 * one of three surfaces.
 *
 * min_associations, max_associations and the per-choice match_max are
 * enforced here, once, for every one of them.
 */
final class AssociatePrimitive extends AbstractInteractionPrimitive
{
    public const CONSTRAINT_MATCH_MAX = 'match_max';

    public function key(): string
    {
        return InteractionPrimitiveRegistry::ASSOCIATE;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Inline, Surface::Media];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Multiple, BaseType::DirectedPair),
            ResponseShape::of(Cardinality::Multiple, BaseType::Pair),
            ResponseShape::anyRecord(),
        ];
    }

    public function projectConstraints(array $definition): array
    {
        // min_associations and max_associations are lifted by the abstract's
        // published alias table, beside min_choices and min_selections, so a
        // type spelling its constraint any of the three documented ways is
        // enforced identically.
        $constraints = parent::projectConstraints($definition);

        $matchMax = [];

        foreach (['sources', 'targets', 'bank'] as $field) {
            $entries = $definition[$field] ?? null;

            if (!\is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                $id = self::identifierOf($entry);

                if ($id !== null && \is_array($entry) && \is_int($entry[self::CONSTRAINT_MATCH_MAX] ?? null)) {
                    $matchMax[$id] = $entry[self::CONSTRAINT_MATCH_MAX];
                }
            }
        }

        if ($matchMax !== []) {
            $constraints[self::CONSTRAINT_MATCH_MAX] = $matchMax;
        }

        return $constraints;
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $pairs = \is_array($value->value) ? $value->value : [];

        // A pair whose endpoints this item does not have is an answer to a
        // different question rather than a wrong match, and it is reported
        // alone: every later check reads the endpoints as if they named
        // something.
        $unknown = $this->unknownIdentifiers($constraints, $value->distinctMembers());

        if ($unknown !== []) {
            return AnswerValidity::invalid($unknown);
        }

        $cardinality = $this->checkCardinality($constraints, \count($pairs));
        $errors = $cardinality->errors;

        $limits = $constraints[self::CONSTRAINT_MATCH_MAX] ?? null;

        if (\is_array($limits) && $limits !== []) {
            $used = [];

            foreach ($pairs as $pair) {
                foreach ($this->endpointsOf($pair) as $endpoint) {
                    $used[$endpoint] = ($used[$endpoint] ?? 0) + 1;
                }
            }

            foreach ($used as $endpoint => $count) {
                $limit = $limits[$endpoint] ?? null;

                if (\is_int($limit) && $limit > 0 && $count > $limit) {
                    $errors[] = AnswerError::of(
                        (string) $endpoint,
                        ErrorKind::TooManySelected,
                        $this->say(NeutralStrings::ERROR_MATCH_MAX, ['limit' => $limit]),
                    );
                }
            }
        }

        return $errors === [] ? AnswerValidity::valid() : AnswerValidity::invalid($errors);
    }

    /**
     * The three lists a pair's endpoints come from, declared separately from
     * the shuffled ones because an item that shuffles nothing still declares
     * its sources and targets.
     */
    protected function identifierFields(array $definition = []): array
    {
        return ['sources', 'targets', 'bank'];
    }

    protected function shuffledFields(array $definition = []): array
    {
        return ['sources', 'targets', 'bank'];
    }

    /**
     * @return list<string>
     */
    private function endpointsOf(mixed $pair): array
    {
        if (!\is_array($pair)) {
            return \is_string($pair) ? [$pair] : [];
        }

        $endpoints = [];

        foreach ($pair as $member) {
            if (\is_string($member)) {
                $endpoints[] = $member;
            }
        }

        return $endpoints;
    }
}
