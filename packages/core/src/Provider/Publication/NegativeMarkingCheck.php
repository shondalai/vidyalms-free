<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * A definition that can award a negative mark on a quiz whose resolved
 * policy does not enable negative marking.
 *
 * **Free, because the content it reads is free.** The floor was previously a
 * parameter only the three mapping policies carried, so most of the
 * catalogue had no floor at all and three routes ran straight past the
 * quiz-level gate: a short answer matcher may carry a fraction down to minus
 * one, an accepted numeric band may too, and a weighted composite propagates
 * both through an aggregation bound with no default, so a ten-mark question
 * could award minus ten marks on a quiz whose instructions promised no
 * penalty. That became reachable the moment GradeResult widened to clamp
 * instead of throw, which it now does, and it is reachable in a build with
 * no paid package installed. Two rules close it, and this is the second:
 * every policy clamps its own output, and a definition that could go
 * negative is refused here unless the quiz says negative marking is on.
 *
 * It blocks, because the alternative is a learner losing marks a quiz told
 * them they could not lose.
 */
final class NegativeMarkingCheck extends AbstractPublicationCheck
{
    public const KEY = 'negative_marking';

    public const MESSAGE = 'vidyalms.publication.negative_without_policy';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return list<PublicationFinding>
     */
    public function run(PublicationContext $context): array
    {
        // A quiz that enables negative marking has said so in its own
        // instructions, so a negative fraction there is the author's
        // intention rather than an accident.
        if ($context->allowsNegativeMarking()) {
            return [];
        }

        $findings = [];

        foreach ($context->questions as $question) {
            $reason = $this->negativeReason($question);

            if ($reason !== null) {
                $findings[] = PublicationFinding::blocks(
                    'negative_without_policy',
                    self::MESSAGE,
                    ['reason' => $reason],
                    $question->id,
                );
            }
        }

        return $findings;
    }

    /**
     * Which of the three shapes the design names this definition carries, or
     * null when it carries none.
     */
    private function negativeReason(Question $question): ?string
    {
        $definition = $question->definition;

        if ($this->hasNegative($definition, 'fraction')) {
            return 'fraction';
        }

        if ($this->hasNegativeMappedValue($definition)) {
            return 'mapped_value';
        }

        if ($this->hasNegative($definition, 'lower_bound')) {
            return 'lower_bound';
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function hasNegative(array $node, string $name): bool
    {
        /** @var mixed $value */
        foreach ($this->valuesNamed($node, $name) as $value) {
            if ((\is_int($value) || \is_float($value)) && $value < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A negative mapped value with no lower bound beside it.
     *
     * The bound is what makes a negative mapped value safe: with one, the
     * policy's own clamp holds the item above it, and without one the
     * mapping sum runs unbounded into the quiz total.
     *
     * @param array<array-key, mixed> $node
     */
    private function hasNegativeMappedValue(array $node): bool
    {
        /** @var mixed $mapping */
        foreach ($this->valuesNamed($node, 'mapping') as $mapping) {
            if (\is_array($mapping) && $this->mappingGoesNegative($mapping)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $mapping
     */
    private function mappingGoesNegative(array $mapping): bool
    {
        $bound = $mapping['lower_bound'] ?? null;
        $bounded = \is_int($bound) || \is_float($bound);

        if ($bounded) {
            return false;
        }

        /** @var mixed $entries */
        $entries = $mapping['entries'] ?? $mapping;

        if (!\is_array($entries)) {
            return false;
        }

        /** @var mixed $entry */
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $value = $entry['value'] ?? null;

            if ((\is_int($value) || \is_float($value)) && $value < 0) {
                return true;
            }
        }

        return false;
    }
}
