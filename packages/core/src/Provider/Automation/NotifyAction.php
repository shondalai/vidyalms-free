<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * Send something, by delegating to the notification engine's own enqueue.
 *
 * **There is exactly one mailer path in the product**, with one consent
 * evaluation, one quiet-hours evaluation and one frequency cap. Anything
 * mail-shaped inside the automation engine would be a second one, so this
 * action composes no message, renders no template and knows no channel
 * behaviour: it calls forAutomationStep and stops.
 *
 * The dedupe interaction is the specified one and it is the whole reason this
 * class is short. Two digests now govern one retried action:
 * automation_queue.dedupe_key prevents a duplicate enqueue of the step and
 * notification_queue.dedupe_key prevents a duplicate send. The step's
 * idempotency key is claimed before this runs, in the same transaction as the
 * enqueue, so a retry finds the claim and replays rather than enqueuing again,
 * and **the occurrence key passed on is the automation's own** rather than the
 * literal "once", so the two digests agree on what a repetition is. The
 * notification digest therefore never has to catch an automation retry; if it
 * does, that is a bug in the claim and the conformance test says so.
 */
final class NotifyAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload
{
    public const KEY = 'notify';

    public function __construct(
        private readonly NotificationEnqueue $enqueue,
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationRunRepository $runs,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.action.notify';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['template_key', 'category_key'],
            'additionalProperties' => false,
            'properties' => [
                'template_key' => ['type' => 'string', 'minLength' => 1],
                'category_key' => ['type' => 'string', 'minLength' => 1],
                'channel_key' => ['type' => 'string', 'default' => 'email'],
                'class' => ['type' => 'string', 'enum' => ['optional', 'marketing'], 'default' => 'optional'],
            ],
        ];
    }

    /**
     * The two keys the seam declares, and not the two it was answering.
     *
     * Every shipped action answered `surfaces` and `subjects`, neither of
     * which the interface documents and neither of which anything read, so the
     * save-time refusal the seam promises had nothing to refuse against. This
     * action acts for a person, and declaring so is what makes a rule that
     * puts it behind a trigger yielding no person a refusal at save rather
     * than a mail addressed to a course id.
     */
    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Notifies;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.notify';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $problems = [];
        if (isset($params['class']) && !in_array($params['class'], ['optional', 'marketing'], true)) {
            $problems[] = 'automation.notify.class_requires_consent';
        }

        foreach (['template_key', 'category_key'] as $required) {
            $value = $params[$required] ?? null;

            if (!\is_string($value) || $value === '') {
                $problems[] = 'automation.notify.' . $required . '_required';
            }
        }

        return $problems;
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'would_notify' => $context->actsForUserId(),
            'template_key' => $context->param('template_key', ''),
            'category_key' => $context->param('category_key', ''),
            'channel_key' => $context->param('channel_key', 'email'),
            'occurrence_key' => $context->occurrenceKey,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        // The occurrence's recipient, and the subject only when the subject is
        // a person. It used to read subject_id, so the entitlement expiring
        // sweep, which yields a learner as the recipient and a course as the
        // subject, queued a learner's expiry notice to whoever held that
        // course id: with course 1 that was the site manager.
        $recipient = $context->actsForUserId() ?? 0;

        if ($recipient < 1) {
            return ActionOutcome::failed('notify.no_recipient', 'The step named no recipient.', false);
        }

        $template = (string) ($context->param('template_key', '') ?? '');
        $category = (string) ($context->param('category_key', '') ?? '');
        $channel = (string) ($context->param('channel_key', 'email') ?? 'email');

        if ($template === '' || $category === '') {
            return ActionOutcome::failed('notify.params_missing', 'The step named no template.', false);
        }

        $rule = $this->rules->find($context->ruleId);
        $run = $this->runs->find($context->runId);

        if ($rule === null || $run === null) {
            return ActionOutcome::failed('notify.rule_unresolved', 'The rule behind this step is gone.', false);
        }

        if ($context->dryRun) {
            // The preview writes nothing but its own record, which is what
            // makes rehearsing a rule cost the subject nothing.
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        $occurrence = TriggerOccurrence::forSweep(
            $rule->triggerKey,
            $recipient,
            $context->occurrenceKey,
            $context->subjectType,
            $context->subjectId,
        );

        $queued = $this->enqueue->forAutomationStep(
            $context->ruleId,
            $rule->triggerKey,
            $template,
            $category,
            $context->param('class') === 'marketing' ? NotificationClass::Marketing : NotificationClass::Optional,
            $channel,
            $occurrence,
        );

        if ($queued === null) {
            // The notification digest already holds this delivery, which on
            // this path means the step ran before and the claim was released
            // rather than completed. It is a suppression and not a failure.
            return ActionOutcome::suppressed('notification_already_queued');
        }

        return ActionOutcome::executed([], ['notification_queue_id' => $queued]);
    }
}
