<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Publication;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * correct_answers_only with min_choices below 1.
 *
 * The rule pays full marks when every keyed option is selected and nothing
 * else is, so an item that permits an empty selection pays a learner who
 * answers nothing exactly what it pays a learner who answers correctly, on
 * every item whose key set happens to be empty and on every item once the
 * validator stops insisting on one. The design marks it a block for that
 * reason, and it is a block over a free marking rule on a free type, which
 * is why it ships here.
 */
final class MinChoicesCheck extends AbstractPublicationCheck
{
    public const KEY = 'min_choices';

    public const POLICY = 'correct_answers_only';

    public const MESSAGE = 'vidyalms.publication.min_choices_below_one';

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

            foreach ($this->valuesNamed($question->definition, 'min_choices') as $value) {
                if (\is_int($value) && $value < 1) {
                    $findings[] = PublicationFinding::blocks(
                        'min_choices_below_one',
                        self::MESSAGE,
                        ['min_choices' => $value],
                        $question->id,
                    );

                    break;
                }
            }
        }

        return $findings;
    }
}
