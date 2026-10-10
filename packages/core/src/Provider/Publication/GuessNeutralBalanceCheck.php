<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * guess_neutral on an item with fewer distractors than keys.
 *
 * The rule is max(0, C - I) / |K|, so one incorrect selection costs 1/|K| of
 * the item. With fewer distractors than keys the item cannot reach zero from
 * over-selection at all, which is the property guess_neutral was chosen for:
 * selecting every option must score exactly zero for any option balance.
 * The design says to state the cost of one incorrect selection as a fraction
 * of the item and to point at limited_selections, so both travel with the
 * warning.
 *
 * guess_neutral is a free marking rule on a free type, so the check is free.
 */
final class GuessNeutralBalanceCheck extends AbstractPublicationCheck
{
    public const KEY = 'guess_neutral_balance';

    public const POLICY = 'guess_neutral';

    public const MESSAGE = 'vidyalms.publication.guess_neutral_unbalanced';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return list<PublicationFinding>
     */
    public function run(PublicationContext $context): array
    {
        $findings = [];

        foreach ($context->questions as $question) {
            if ($question->scoringPolicy !== self::POLICY) {
                continue;
            }

            [$keys, $distractors] = $this->balanceOf($question->definition);

            if ($keys < 1 || $distractors >= $keys) {
                continue;
            }

            $findings[] = PublicationFinding::warning(
                'guess_neutral_unbalanced',
                self::MESSAGE,
                [
                    'keys' => $keys,
                    'distractors' => $distractors,
                    'cost' => round(1.0 / $keys, 4),
                ],
                $question->id,
            );
        }

        return $findings;
    }

    /**
     * @param array<array-key, mixed> $definition
     *
     * @return array{int, int} keyed options, then the rest
     */
    private function balanceOf(array $definition): array
    {
        $keys = 0;
        $distractors = 0;
        $correct = $definition['correct'] ?? null;
        $keyed = \is_array($correct) ? $correct : ($correct === null ? [] : [$correct]);

        /** @var mixed $options */
        foreach ($this->valuesNamed($definition, 'options') as $options) {
            if (!\is_array($options)) {
                continue;
            }

            /** @var mixed $option */
            foreach ($options as $option) {
                if (!\is_array($option)) {
                    continue;
                }

                $key = $option['key'] ?? null;

                if (\in_array($key, $keyed, true) || ($option['correct'] ?? false) === true) {
                    ++$keys;

                    continue;
                }

                ++$distractors;
            }
        }

        return [$keys, $distractors];
    }
}
