<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;

/**
 * Does this draft satisfy the schema its own question type publishes.
 *
 * It runs the same DefinitionSchemaCheck the authoring path runs, over the
 * draft rather than over a stored row, so a reviewer sees the refusal before
 * accepting rather than a 422 afterwards. That is the whole value of running
 * it as a check: the answer is identical, and it arrives earlier.
 *
 * A draft naming a type nothing registers is a blocking finding rather than
 * an exception. The published miss for the question type registry is that an
 * unresolvable type degrades to a placeholder, and a review screen is exactly
 * where a person should be told which package is missing.
 */
final class CheckSchemaValidity extends DeterministicTask implements ProducesFindingsOnly
{
    public const KEY = 'check.schema_validity';

    public function __construct(private readonly QuestionTypeRegistry $types)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 10;
    }

    public function placements(): array
    {
        return [
            'workbench_question_bank',
            'workbench_question_editor',
        ];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $findings = [];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $type = \is_string($draft['type'] ?? null) ? $draft['type'] : '';
            $definition = \is_array($draft['definition'] ?? null) ? $draft['definition'] : [];
            $questionId = self::syntheticId($draft, $index);

            if ($type === '' || !$this->types->has($type)) {
                $findings[] = PublicationFinding::blocks(
                    'ai.check.schema_validity.unknown_type',
                    'ai.finding.unknown_question_type',
                    ['type' => $type],
                    $questionId,
                );

                continue;
            }

            $provider = $this->types->get($type);

            if (!$provider instanceof DefinitionContractInterface) {
                // A type publishing no schema is unvalidated by its own
                // documented default, which is a warning about the type
                // rather than a fault in the draft.
                $findings[] = PublicationFinding::warning(
                    'ai.check.schema_validity.unvalidated_type',
                    'ai.finding.type_publishes_no_schema',
                    ['type' => $type],
                    $questionId,
                );

                continue;
            }

            foreach (DefinitionSchemaCheck::errors($provider->definitionSchema(), $definition, '', true) as $error) {
                $findings[] = PublicationFinding::blocks(
                    'ai.check.schema_validity.invalid',
                    'ai.finding.definition_rejected',
                    ['type' => $type, 'error' => $error],
                    $questionId,
                );
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * The synthetic negative id convention, stated here because a finding
     * that cannot name its item is the second of the four controls that make
     * the human step real. A run over twenty drafted items producing twenty
     * findings none of which names an item is not a review.
     *
     * @param array<string, mixed> $draft
     */
    public static function syntheticId(array $draft, int $index): int
    {
        $id = $draft['draft_id'] ?? null;

        return -1 * (\is_int($id) && $id > 0 ? $id : $index + 1);
    }
}
