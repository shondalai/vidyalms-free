<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskInput;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * Template-based item generation, which is the oldest and most reliable form
 * of automatic item generation and needs no model whatsoever.
 *
 * An author writes one item model with named slots and supplies the values
 * each slot may take; this expands the cross product into single-choice
 * drafts. Every generated item is as good as the template, which is exactly
 * the property the published research likes about this family and exactly why
 * it belongs in the free build.
 *
 * The expansion is capped, because a template with four slots of five values
 * is six hundred and twenty five items and an author who wanted that should
 * have to say so.
 */
final class QuestionTemplateAig extends DeterministicTask implements
    DeclaresTaskInput,
    DeclaresTaskOutput,
    TargetsExtensionPoint
{
    public const KEY = 'question.template_aig';

    public const DRAFT_KIND = 'question';

    /** The type every generated draft targets, and the only free type with distractors. */
    public const TARGET_TYPE = 'single_choice';

    public const OPTION_STEM = 'stem_template';
    public const OPTION_SLOTS = 'slots';
    public const OPTION_CORRECT = 'correct_template';
    public const OPTION_DISTRACTORS = 'distractor_templates';
    public const OPTION_LIMIT = 'limit';

    public const DEFAULT_LIMIT = 25;
    public const MAX_LIMIT = 200;

    public function key(): string
    {
        return self::KEY;
    }

    public function group(): string
    {
        return self::GROUP_ASSESSMENT;
    }

    public function order(): int
    {
        return 10;
    }

    public function placements(): array
    {
        return ['workbench_question_bank'];
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [self::OPTION_STEM, self::OPTION_CORRECT],
            'properties' => [
                self::OPTION_STEM => ['type' => 'string', 'minLength' => 1],
                self::OPTION_CORRECT => ['type' => 'string', 'minLength' => 1],
                self::OPTION_SLOTS => ['type' => 'object'],
                self::OPTION_DISTRACTORS => ['type' => 'array', 'items' => ['type' => 'string']],
                self::OPTION_LIMIT => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
            ],
        ];
    }

    /**
     * The draft payload, which is a single-choice definition and nothing
     * else. It binds no scored field: the points a question carries are set
     * by the person who accepts it, through the ordinary authoring path.
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['prompt', 'options', 'correct'],
            'properties' => [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'correct' => ['type' => 'string', 'minLength' => 1],
                'options' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'label'],
                        'properties' => [
                            'key' => ['type' => 'string', 'minLength' => 1],
                            'label' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function draftKind(): string
    {
        return self::DRAFT_KIND;
    }

    public function targetExtensionPoint(): string
    {
        return 'question_type';
    }

    public function targetProviderKey(): string
    {
        return self::TARGET_TYPE;
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $stem = TaskInputs::text($request, self::OPTION_STEM);
        $correct = TaskInputs::text($request, self::OPTION_CORRECT);
        $distractors = TaskInputs::strings($request, self::OPTION_DISTRACTORS);
        $limit = min(self::MAX_LIMIT, max(1, TaskInputs::whole($request, self::OPTION_LIMIT, self::DEFAULT_LIMIT)));

        if ($stem === '' || $correct === '') {
            return AiTaskResult::create([], [], ['ai.warning.template_is_incomplete']);
        }

        $drafts = [];
        $warnings = [];
        $combinations = $this->combinations($this->slots($request));

        if (\count($combinations) > $limit) {
            $warnings[] = 'ai.warning.template_expansion_capped';
            $combinations = \array_slice($combinations, 0, $limit);
        }

        foreach ($combinations as $ordinal => $values) {
            $options = [['key' => 'a', 'label' => $this->fill($correct, $values)]];
            foreach (array_values($distractors) as $index => $distractor) {
                $options[] = ['key' => 'distractor_' . ($index + 1), 'label' => $this->fill($distractor, $values)];
            }

            if (\count($options) < 2) {
                $warnings[] = 'ai.warning.template_has_no_distractors';

                break;
            }

            $drafts[] = AiDraftCandidate::create(
                self::DRAFT_KIND,
                $this->targetExtensionPoint(),
                [
                    'prompt' => $this->fill($stem, $values),
                    'options' => $options,
                    'correct' => 'a',
                ],
                self::TARGET_TYPE,
                [],
                $ordinal,
            );
        }

        return AiTaskResult::create($drafts, [], $warnings);
    }

    /**
     * @return array<string, list<string>>
     */
    private function slots(AiTaskRequest $request): array
    {
        $slots = $request->option(self::OPTION_SLOTS);

        if (!\is_array($slots)) {
            return [];
        }

        $result = [];

        foreach ($slots as $name => $values) {
            if (!\is_array($values)) {
                continue;
            }

            $strings = [];

            foreach ($values as $value) {
                if (\is_string($value) && $value !== '') {
                    $strings[] = $value;
                }
            }

            if ($strings !== []) {
                $result[(string) $name] = $strings;
            }
        }

        return $result;
    }

    /**
     * The cross product, with one empty assignment when there are no slots at
     * all: a template with no variables is one item and not zero.
     *
     * @param array<string, list<string>> $slots
     *
     * @return list<array<string, string>>
     */
    private function combinations(array $slots): array
    {
        $combinations = [[]];

        foreach ($slots as $name => $values) {
            $next = [];

            foreach ($combinations as $assignment) {
                foreach ($values as $value) {
                    $next[] = $assignment + [$name => $value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param array<string, string> $values
     */
    private function fill(string $template, array $values): string
    {
        foreach ($values as $name => $value) {
            $template = str_replace('{' . $name . '}', $value, $template);
        }

        return $template;
    }
}
