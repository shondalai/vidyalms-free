<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * An option whose text refers to its own position inside a shuffled choice
 * set with "fixed" unset.
 *
 * "All of the above" and "None of the following" mean nothing once the set
 * is shuffled, and the item then reads as nonsense to whichever learners
 * happened to draw that permutation. Per-choice "fixed" is a free-build
 * feature and is exactly the fix, so the warning points at it.
 *
 * A warning rather than a block: an author may legitimately write "above"
 * in an option about something else, and refusing the save would be a
 * false positive an author cannot argue with.
 */
final class PositionalOptionCheck extends AbstractPublicationCheck
{
    public const KEY = 'positional_option';

    public const MESSAGE = 'vidyalms.publication.positional_option_shuffled';

    /** The two words the design names, matched case-insensitively. */
    private const WORDS = ['above', 'following'];

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return list<PublicationFinding>
     */
    public function run(PublicationContext $context): array
    {
        // Shuffle is a quiz-level policy, so an item is only at risk on a
        // quiz that shuffles its options.
        if ($context->setting('shuffle_options') !== true) {
            return [];
        }

        $findings = [];

        foreach ($context->questions as $question) {
            $label = $this->positionalOption($question->definition);

            if ($label !== null) {
                $findings[] = PublicationFinding::warning(
                    'positional_option_shuffled',
                    self::MESSAGE,
                    ['option' => $label],
                    $question->id,
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function positionalOption(array $definition): ?string
    {
        /** @var mixed $options */
        foreach ($this->valuesNamed($definition, 'options') as $options) {
            if (!\is_array($options)) {
                continue;
            }

            /** @var mixed $option */
            foreach ($options as $option) {
                if (!\is_array($option) || ($option['fixed'] ?? false) === true) {
                    continue;
                }

                $label = $option['label'] ?? null;

                if (\is_string($label) && $this->isPositional($label)) {
                    return $label;
                }
            }
        }

        return null;
    }

    private function isPositional(string $label): bool
    {
        foreach (self::WORDS as $word) {
            if (mb_stripos($label, $word) !== false) {
                return true;
            }
        }

        return false;
    }
}
