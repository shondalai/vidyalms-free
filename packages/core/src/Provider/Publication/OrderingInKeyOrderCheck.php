<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * An ordering item whose presented order equals its correct order under the
 * resolved shuffle setting.
 *
 * It pays full marks to a learner who submits without touching it, which is
 * the least defensible thing an item can do, and it is invisible to an
 * author who authored the list in the right order and never turned shuffling
 * on. ordering is one of the free six, so the check ships free.
 */
final class OrderingInKeyOrderCheck extends AbstractPublicationCheck
{
    public const KEY = 'ordering_in_key_order';

    public const TYPE = 'ordering';

    public const MESSAGE = 'vidyalms.publication.ordering_in_key_order';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return list<PublicationFinding>
     */
    public function run(PublicationContext $context): array
    {
        // With option shuffling on, the presented order is a permutation of
        // the authored one and the item is only at risk on the permutation
        // that happens to match, which is a draw rather than an authoring
        // mistake.
        if ($context->setting('shuffle_options') === true) {
            return [];
        }

        $findings = [];

        foreach ($context->questions as $question) {
            if ($question->type !== self::TYPE) {
                continue;
            }

            if ($this->presentedEqualsKey($question->definition)) {
                $findings[] = PublicationFinding::warning(
                    'ordering_in_key_order',
                    self::MESSAGE,
                    [],
                    $question->id,
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<array-key, mixed> $definition
     */
    private function presentedEqualsKey(array $definition): bool
    {
        $items = $definition['items'] ?? null;
        $correct = $definition['correct'] ?? null;

        if (!\is_array($items) || !\is_array($correct) || $items === [] || $correct === []) {
            return false;
        }

        $presented = [];

        /** @var mixed $item */
        foreach ($items as $item) {
            if (\is_array($item) && \is_string($item['key'] ?? null)) {
                $presented[] = $item['key'];

                continue;
            }

            if (\is_string($item)) {
                $presented[] = $item;
            }
        }

        $keyed = [];

        /** @var mixed $key */
        foreach ($correct as $key) {
            if (\is_string($key)) {
                $keyed[] = $key;
            }
        }

        return $presented !== [] && $presented === $keyed;
    }
}
