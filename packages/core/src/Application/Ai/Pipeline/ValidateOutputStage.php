<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;

/**
 * Stage 9. Checks what came back against the task's own declared output, after
 * the fact.
 *
 * Post-hoc validation is kept even where a route claims constrained decoding,
 * because constrained-decoding frameworks translate a schema unfaithfully and
 * the narrowed schema is guidance rather than the gate. The authoritative gate
 * for a question definition is still DefinitionSchemaCheck inside AddQuestion,
 * which runs when a person accepts the draft; this stage's job is the run's own
 * three-valued answer.
 *
 * **schemaValid is three valued on purpose.** Null means not checked, which is
 * what a task declaring no output schema honestly answers; true and false are
 * the two checked answers. Collapsing "not checked" into false would report
 * every display-only task as producing invalid output.
 *
 * Two structural rules are enforced here rather than described:
 *
 * - a task declaring ProducesFindingsOnly may not also declare
 *   TargetsExtensionPoint, and its result must carry no drafts. A check that
 *   could write an artefact is a check that will eventually be wired to
 *   auto-approve, which is the outcome the constraints forbid;
 * - a draft whose kind can become an artefact must name a target, because three
 *   links of the AddQuestion chain need a provider key to resolve and a draft
 *   without one could never be accepted at all.
 */
final class ValidateOutputStage implements AiRunStageInterface
{
    public function name(): string
    {
        return 'ValidateOutput';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $result = $run->result;
        $task = $run->task;

        if ($result === null || $task === null) {
            return;
        }

        if ($task instanceof ProducesFindingsOnly) {
            if ($task instanceof TargetsExtensionPoint) {
                $run->fail(
                    'findings_only_targets_extension_point',
                    'A task declaring ProducesFindingsOnly may not also declare TargetsExtensionPoint: a check that'
                        . ' can write an artefact is a check that will eventually be wired to auto-approve.',
                );

                return;
            }

            if ($result->drafts !== []) {
                $run->fail(
                    'findings_only_produced_drafts',
                    'A task declaring ProducesFindingsOnly returned drafts, so its result is not findings and'
                        . ' nothing else.',
                );

                return;
            }
        }

        foreach ($result->drafts as $candidate) {
            if ($candidate->targetExtensionPoint === '') {
                // not_configured and not schema_invalid. Nothing about the
                // schema is invalid: the draft named no acceptor target, which
                // is a task that was mis-declared. schema_invalid is the kind
                // the item review screen reads as a decoding failure, so
                // reporting it here made a task bug indistinguishable from a
                // model that returned the wrong shape.
                $run->refuse(RefusalKind::NotConfigured, 'ai.draft.untargeted', [
                    'kind' => $candidate->draftKind,
                ]);

                return;
            }
        }

        if (!$task instanceof DeclaresTaskOutput) {
            // Absent means the result is display only and can never become a
            // draft row, so there is nothing to check and "not checked" is the
            // honest answer rather than a pass.
            return;
        }

        $schema = $task->outputSchema();
        $valid = true;

        foreach ($result->drafts as $candidate) {
            if (!$this->satisfies($candidate->payload, $schema)) {
                $valid = false;
            }
        }

        $run->schemaValid = $valid;

        if ($valid) {
            return;
        }

        $run->note(
            ImportNote::WARNING,
            'At least one draft did not satisfy the task\'s own declared output schema. It is kept and flagged'
                . ' rather than discarded, because a reviewer can see what came back and a deleted payload'
                . ' cannot be diagnosed.',
        );
    }

    /**
     * The narrow structural check this stage owns: required keys present, and
     * declared property types respected at the top level.
     *
     * It is deliberately not a JSON Schema engine. The authoritative validation
     * for anything that becomes a question is DefinitionSchemaCheck inside
     * AddQuestion, and a second full implementation here would be a second
     * answer to the same question with its own bugs.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $schema
     */
    private function satisfies(array $payload, array $schema): bool
    {
        /** @var mixed $required */
        $required = $schema['required'] ?? [];

        if (\is_array($required)) {
            foreach ($required as $key) {
                if (\is_string($key) && !\array_key_exists($key, $payload)) {
                    return false;
                }
            }
        }

        /** @var mixed $properties */
        $properties = $schema['properties'] ?? null;

        if (!\is_array($properties)) {
            return true;
        }

        foreach ($properties as $name => $definition) {
            if (!\is_string($name) || !\is_array($definition) || !\array_key_exists($name, $payload)) {
                continue;
            }

            /** @var mixed $type */
            $type = $definition['type'] ?? null;

            if (\is_string($type) && !$this->isOfType($payload[$name], $type)) {
                return false;
            }
        }

        return true;
    }

    private function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'array' => \is_array($value) && array_is_list($value),
            'object' => \is_array($value) && !array_is_list($value),
            'null' => $value === null,
            default => true,
        };
    }
}
