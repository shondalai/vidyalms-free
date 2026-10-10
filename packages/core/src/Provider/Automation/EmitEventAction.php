<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Application\Automation\AutomationLoopGuard;
use Shondalai\VidyaLMS\Core\Application\Automation\OpenAutomationRuns;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationOccurrence;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\EmitsOccurrences;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * Emit an event other rules can fire on, and the one action that is not
 * reversible.
 *
 * An emitted event that has already triggered other rules cannot be
 * un-emitted, which is why the loop section exists and why park is this
 * action's default failure policy.
 *
 * **It stamps the emitting run's depth plus one and the emitting rule id onto
 * what it emits**, and the chain it holds is passed to the runs it opens. That
 * is loop protection mechanism two, and it is the correction the three
 * mechanisms needed: a run created from an automation-emitted event has to
 * inherit the depth rather than start at the column default, or a two-rule
 * cycle presents as a fresh depth-zero run of a different rule every time
 * round and the counter never reaches the ceiling.
 *
 * A rule already on the chain is refused before the run is opened, which is
 * mechanism one, and a rule whose action set contains this action is refused
 * at save when its cooldown is zero, which is mechanism three. All three, or
 * the cycle survives.
 */
final class EmitEventAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    EmitsOccurrences,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload
{
    public const KEY = 'emit_event';

    /** Not reversible, and it says so rather than leaving the field unreadable. */
    public const REVERSIBLE = false;

    public function __construct(
        private readonly OpenAutomationRuns $open,
        private readonly AutomationLoopGuard $loops,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.action.emit_event';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['event_key'],
            'additionalProperties' => false,
            'properties' => ['event_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
        ];
    }

    /**
     * The two keys the seam declares, and not the two it was answering.
     *
     * Every shipped action answered `surfaces` and `subjects`, neither of
     * which the interface documents and neither of which anything read, so
     * the save-time refusal the seam promises had nothing to refuse
     * against.
     */
    public function applicability(): array
    {
        return ['subject_types' => ['user', 'course'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Emits;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.emit_event';
    }

    /**
     * The key is authored per step, so there is nothing to declare up front.
     *
     * The declaration that matters is the interface itself: the cooldown-zero
     * refusal used to be `str_contains($key, 'emit_event')`, so an action
     * called anything else cascaded with that protection switched off.
     *
     * @return list<string>
     */
    public function emittedKeys(): array
    {
        return [];
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $key = $params['event_key'] ?? null;

        return \is_string($key) && trim($key) !== '' ? [] : ['automation.emit_event.event_key_required'];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'event_key' => $this->eventKey($context),
            'cascade_depth' => $context->cascadeDepth + 1,
            'ceiling' => $this->loops->ceiling(),
            'chain' => $context->cascadeChain->ruleIds,
            'subject_id' => $context->subjectId,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $eventKey = $this->eventKey($context);

        if ($eventKey === '') {
            return ActionOutcome::failed('emit_event.no_key', 'The step named no event.', false);
        }

        $depth = $context->cascadeDepth + 1;

        if (!$context->isWithinCascadeCeiling($this->loops->ceiling())) {
            return ActionOutcome::suppressed(SuppressionReason::CASCADE_CEILING, ['depth' => $context->cascadeDepth]);
        }

        if ($context->dryRun) {
            // A preview never emits: an emitted event that has already
            // triggered other rules cannot be un-emitted, which is the whole
            // reason this action is not reversible.
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        // The period bucket becomes the occurrence key, which is a 64
        // character column, so the bounded form is passed rather than the
        // full reference: a 100 character event key minted a 115 character key
        // that SQLite stored and MySQL refused.
        $occurrence = TriggerOccurrence::forSweep(
            $eventKey,
            $context->actsForUserId() ?? 0,
            AutomationOccurrence::emittedBucket($context->ruleId, $context->runId, $eventKey),
            $context->subjectType,
            $context->subjectId,
        );

        // The depth and the chain are NOT stamped here any more. Stamping was
        // the caller's job and a caller can omit it, which is exactly what a
        // third-party emitting action did: every run it opened carried depth
        // zero and an empty chain, so the ceiling was never approached. The
        // engine holds the frame that is executing and stamps from it, so this
        // action passes the occurrence and nothing else.
        $opened = $this->open->forOccurrence($eventKey, $occurrence);

        return ActionOutcome::executed([], [
            'event_key' => $eventKey,
            'cascade_depth' => $depth,
            'opened_runs' => \count($opened),
            'run_ids' => $opened,
        ]);
    }

    private function eventKey(AutomationActionContext $context): string
    {
        $value = $context->param('event_key', '');

        return \is_string($value) ? trim($value) : '';
    }
}
