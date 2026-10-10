<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresCost;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * The dry run: exactly which subjects the rule would touch, and what each step
 * would do.
 *
 * **One code path serves the preview and the real run**, which is what stops
 * the preview drifting from reality: the same fan-out writes the same queue
 * rows and the same step machine reads them, with dry_run true. What makes
 * that safe is that dry_run is part of three keys rather than a column beside
 * them, so a preview run and a real run for the same rule, occurrence and
 * subject are distinct rows: a preview never occupies the re-entry guard and
 * never claims a key a real run needs.
 *
 * Without the flag in the keys, previewing a rule for subject X at occurrence
 * K writes the run row, the real trigger fires minutes later, the unique
 * refuses the insert, and with reentry defaulting to never the subject is
 * suppressed for good. The one mechanism the design made mandatory for every
 * action would have been the mechanism with the side effect.
 */
final class PreviewAutomationRule
{
    /** How many subjects a preview names before it stops naming them. */
    public const SUBJECT_BOUND = 25;

    public function __construct(
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationRunRepository $runs,
        private readonly SqlAutomationQueueRepository $queue,
        private readonly OpenAutomationRuns $open,
        private readonly RunAutomationFanout $fanout,
        private readonly RunAutomationStep $steps,
        private readonly AutomationActionRegistry $actions,
        private readonly AccessGuard $access,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Rehearses the rule against the named subjects.
     *
     * @param list<int> $subjectIds
     *
     * @return array<string, mixed> the preview record, which is the only thing
     *                              the dry run writes that a person reads
     */
    public function execute(Principal $actor, int $ruleId, array $subjectIds, string $subjectType = 'user'): array
    {
        $this->access->require($actor, Capability::MANAGE);
        $rule = $this->rules->find($ruleId);

        if ($rule === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $ruleId);
        }

        $occurrenceKey = 'preview:' . $this->clock->now()->format('YmdHis');
        $subjects = [];
        $bounded = \array_slice($subjectIds, 0, self::SUBJECT_BOUND);

        foreach ($bounded as $subjectId) {
            $occurrence = TriggerOccurrence::forSweep(
                $rule->triggerKey,
                $subjectId,
                $occurrenceKey,
                $subjectType,
                $subjectId,
            );

            $runId = $this->open->forRule($rule, $rule->triggerKey, $occurrence, true);

            if ($runId === null) {
                $subjects[] = ['subject_id' => $subjectId, 'steps' => [], 'skipped' => true];

                continue;
            }

            $run = $this->runs->find($runId);

            if ($run !== null) {
                $this->fanout->expand($run);
            }

            $rendered = [];

            foreach ($this->queue->forRun($runId) as $step) {
                // The same step machine the real run uses, on the same row,
                // with dry_run true. That is the "one code path" claim being
                // exercised rather than asserted: the preview cannot drift
                // from reality because it is not a second implementation.
                $outcome = $this->steps->execute($step);

                $rendered[] = [
                    'step_index' => $step->stepIndex,
                    'action_key' => $step->actionKey,
                    'payload' => $this->render(
                        $rule,
                        $step->stepIndex,
                        $step->actionKey,
                        $step->params,
                        $actor,
                        $runId,
                        $occurrenceKey,
                        $subjectType,
                        $subjectId,
                    ),
                    'disposition' => $outcome->disposition->value,
                    'cost' => $this->costOf($step->actionKey),
                ];
            }

            $subjects[] = ['subject_id' => $subjectId, 'steps' => $rendered, 'skipped' => false];
        }

        $this->rules->stampDryRun($ruleId);

        return [
            'rule_id' => $ruleId,
            'rule_version' => $rule->version,
            'occurrence_key' => $occurrenceKey,
            'subject_count' => \count($subjectIds),
            'named_subjects' => \count($bounded),
            'bound' => self::SUBJECT_BOUND,
            'subjects' => $subjects,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function render(
        AutomationRule $rule,
        int $stepIndex,
        string $actionKey,
        array $params,
        Principal $actor,
        int $runId,
        string $occurrenceKey,
        string $subjectType,
        int $subjectId,
    ): array {
        if (!$this->actions->has($actionKey)) {
            return ['unavailable' => $actionKey];
        }

        $action = $this->actions->get($actionKey);

        if (!$action instanceof AutomationActionInterface || !$action instanceof SupportsDryRun) {
            return ['unavailable' => $actionKey];
        }

        $context = AutomationActionContext::forStep(
            $rule->id ?? 0,
            $rule->version,
            $runId,
            $stepIndex,
            $actionKey,
            $occurrenceKey,
            $actor,
            $this->clock->now(),
            $this->clock->now()->modify('+1 hour'),
            $subjectType,
            $subjectId,
            $rule->organisationId,
            true,
            1,
            0,
            [],
            $params,
        );

        return $action->previewFor($context);
    }

    /** The estimated cost where an action declares one, and null where it does not. */
    private function costOf(string $actionKey): ?string
    {
        if (!$this->actions->has($actionKey)) {
            return null;
        }

        $action = $this->actions->get($actionKey);

        return $action instanceof DeclaresCost ? $action->costUnit() : null;
    }
}
