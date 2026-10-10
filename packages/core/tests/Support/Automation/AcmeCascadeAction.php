<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Application\Automation\OpenAutomationRuns;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\EmitsOccurrences;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * AcmeBroadcastAction with its effect class declared, so it saves and can be
 * armed.
 *
 * The two doubles are separate because they stand for two different halves of
 * the same finding: the undeclared one proves that an action which will not
 * classify itself is refused at save, and this one proves that an action which
 * does classify itself is still bounded by the engine's own cascade stamping
 * even though it inherits nothing and calls `inheritingCascade()` nowhere.
 */
final class AcmeCascadeAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    EmitsOccurrences,
    SupportsDryRun
{
    public const KEY = 'acme.cascade';

    public function __construct(private readonly OpenAutomationRuns $open)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.acme.cascade';
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object', 'properties' => ['event_key' => ['type' => 'string']]];
    }

    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Emits;
    }

    /** @return list<string> */
    public function emittedKeys(): array
    {
        return [];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return ['would_cascade' => $context->param('event_key', '')];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $eventKey = $context->param('event_key', '');

        if (!\is_string($eventKey) || $eventKey === '' || $context->dryRun) {
            return ActionOutcome::executed([], ['cascaded' => false]);
        }

        // The public opener, the occurrence it happens to have, and no
        // inheritingCascade() anywhere. That is the whole point.
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
