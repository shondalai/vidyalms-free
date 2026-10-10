<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Application\Automation\OpenAutomationRuns;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\EmitsOccurrences;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * A third party's cascading action, named nothing like `emit_event` and
 * stamping no cascade of its own.
 *
 * It stands for the finding the review reproduced: all three loop protections
 * were keyed to the literal string `emit_event`, so an action calling the same
 * public opener under another name cascaded with the cooldown refusal switched
 * off, and because stamping the depth and the chain was the caller's job, one
 * that simply did not stamp opened run after run at depth zero. Two of these
 * pointed at each other opened one run per turn against a ceiling of five and
 * never approached it.
 *
 * It deliberately declares no effect class in its first form, so the same
 * double also stands for the approval refusal that turned on seven English
 * words: `acme.broadcast` contains none of them.
 */
final class AcmeBroadcastAction implements AutomationActionInterface, SupportsDryRun, EmitsOccurrences
{
    public const KEY = 'acme.broadcast';

    /**
     * @param list<string> $triggerKeys the triggers this action declares it may sit behind
     */
    public function __construct(
        private readonly OpenAutomationRuns $open,
        private readonly array $triggerKeys = [],
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.acme.broadcast';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object', 'properties' => ['event_key' => ['type' => 'string']]];
    }

    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => $this->triggerKeys];
    }

    /** @return list<string> */
    public function emittedKeys(): array
    {
        return [];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return ['would_broadcast' => $context->param('event_key', '')];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $eventKey = $context->param('event_key', '');

        if (!\is_string($eventKey) || $eventKey === '' || $context->dryRun) {
            return ActionOutcome::executed([], ['broadcast' => false]);
        }

        // Exactly what a third party writes: the public opener, the occurrence
        // it happens to have, and no inheritingCascade() anywhere.
        $opened = $this->open->forOccurrence($eventKey, TriggerOccurrence::forSweep(
            $eventKey,
            $context->actsForUserId() ?? 0,
            $context->occurrenceKey,
            $context->subjectType,
            $context->subjectId,
        ));

        return ActionOutcome::executed([], ['opened_runs' => \count($opened)]);
    }
}
