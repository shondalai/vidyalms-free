<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The item-writing rubric, restricted to the guidelines a machine can decide.
 *
 * The published rubric has nineteen guidelines and most of them are
 * judgements about content: whether the stem tests an important idea, whether
 * the distractors are plausible to somebody who does not know the answer.
 * Those are not automatable and this check does not pretend to automate them.
 * What it decides are the structural ones, where the evidence is in the text
 * and the rule has no opinion in it: an all-of-the-above option, a negative
 * stem with no emphasis, an option that is far longer than its siblings, two
 * options that say the same thing, and an absolute qualifier.
 *
 * **Every finding is a warning and none of them blocks.** A structural cue is
 * evidence that an item may be weak, not proof that it is: a longest-correct
 * option is a real signal and is also how a well-written item about a
 * multi-part concept looks. A check that blocked on a heuristic would train
 * authors to work around it, which is worse than a check they read.
 */
final class CheckItemWritingFlaws extends DeterministicTask implements ProducesFindingsOnly
{
    public const KEY = 'check.item_writing_flaws';

    /** Options whose presence is a cue on its own. */
    private const CUE_OPTIONS = [
        'all of the above',
        'none of the above',
        'both a and b',
        'all of these',
        'none of these',
    ];

    /** Words a distractor is rarely true with, and a correct option rarely false with. */
    private const ABSOLUTES = ['always', 'never', 'every', 'none', 'all', 'must'];

    /** Negations that need emphasis in a stem, because a missed one inverts the item. */
    private const NEGATIONS = ['not', 'except', 'least', 'incorrect', 'false'];

    /** How much longer than the mean an option may be before it is a length cue. */
    private const LENGTH_CUE_RATIO = 1.6;

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 20;
    }

    public function placements(): array
    {
        return ['workbench_question_bank', 'workbench_question_editor'];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $findings = [];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $definition = \is_array($draft['definition'] ?? null) ? $draft['definition'] : [];
            $stem = \is_string($definition['prompt'] ?? null) ? $definition['prompt'] : '';
            $options = $this->optionLabels($definition);

            foreach ($this->stemFindings($stem, $questionId) as $finding) {
                $findings[] = $finding;
            }

            foreach ($this->optionFindings($options, $questionId) as $finding) {
                $findings[] = $finding;
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * @return list<PublicationFinding>
     */
    private function stemFindings(string $stem, int $questionId): array
    {
        $findings = [];
        $normalised = TaskInputs::normalise($stem);

        if ($normalised === '') {
            $findings[] = PublicationFinding::warning(
                'ai.check.item_writing_flaws.empty_stem',
                'ai.finding.stem_is_empty',
                [],
                $questionId,
            );

            return $findings;
        }

        foreach (self::NEGATIONS as $negation) {
            if (!\in_array($negation, explode(' ', $normalised), true)) {
                continue;
            }

            // The emphasis has to survive normalisation to count, so this
            // reads the raw stem: an author who wrote NOT or **not** has done
            // what the guideline asks and should not be told off for it.
            if (preg_match('/\b(' . strtoupper($negation) . ')\b/', $stem) === 1) {
                continue;
            }

            $findings[] = PublicationFinding::warning(
                'ai.check.item_writing_flaws.unemphasised_negation',
                'ai.finding.negative_stem_without_emphasis',
                ['word' => $negation],
                $questionId,
            );
        }

        return $findings;
    }

    /**
     * @param list<string> $options
     *
     * @return list<PublicationFinding>
     */
    private function optionFindings(array $options, int $questionId): array
    {
        if ($options === []) {
            return [];
        }

        $findings = [];
        $seen = [];
        $lengths = [];

        foreach ($options as $option) {
            $normalised = TaskInputs::normalise($option);
            $lengths[] = mb_strlen($normalised);

            if (\in_array($normalised, self::CUE_OPTIONS, true)) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.item_writing_flaws.complex_option',
                    'ai.finding.all_or_none_of_the_above',
                    ['option' => $normalised],
                    $questionId,
                );
            }

            if (isset($seen[$normalised])) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.item_writing_flaws.duplicate_option',
                    'ai.finding.two_options_say_the_same_thing',
                    ['option' => $normalised],
                    $questionId,
                );
            }

            $seen[$normalised] = true;

            foreach (self::ABSOLUTES as $absolute) {
                if (\in_array($absolute, explode(' ', $normalised), true)) {
                    $findings[] = PublicationFinding::warning(
                        'ai.check.item_writing_flaws.absolute_qualifier',
                        'ai.finding.option_uses_an_absolute',
                        ['option' => $normalised, 'word' => $absolute],
                        $questionId,
                    );

                    break;
                }
            }
        }

        $mean = array_sum($lengths) / \count($lengths);

        if ($mean > 0.0) {
            foreach ($options as $position => $option) {
                if ($lengths[$position] > $mean * self::LENGTH_CUE_RATIO) {
                    $findings[] = PublicationFinding::warning(
                        'ai.check.item_writing_flaws.length_cue',
                        'ai.finding.one_option_is_much_longer',
                        ['position' => $position + 1],
                        $questionId,
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private function optionLabels(array $definition): array
    {
        $options = $definition['options'] ?? null;

        if (!\is_array($options)) {
            return [];
        }

        $labels = [];

        foreach ($options as $option) {
            if (\is_string($option)) {
                $labels[] = $option;

                continue;
            }

            if (\is_array($option) && \is_string($option['label'] ?? null)) {
                $labels[] = $option['label'];
            }
        }

        return $labels;
    }
}
