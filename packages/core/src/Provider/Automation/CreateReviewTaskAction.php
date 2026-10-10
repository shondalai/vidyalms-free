<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\ProducesReviewItem;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * Park something for a named person to look at.
 *
 * It is the one free action that declares ProducesReviewItem, and it is
 * therefore the free build's proof that the capability is reached by a free
 * code path rather than waiting for the first AI action to exercise it. The
 * step machine reads the capability and parks the queue row after the effect,
 * which is the AFTER half of the capability that was doing two jobs: nothing
 * here asks for approval before anything, and nothing here is gated on a
 * person.
 *
 * The named person is automation_rule_actions.owner_user_id, defaulting to the
 * rule's armed_by, which is why that is a column rather than a boolean.
 */
final class CreateReviewTaskAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload,
    ProducesReviewItem
{
    public const KEY = 'create_review_task';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.action.create_review_task';
    }

    public function reviewItemKey(): string
    {
        return 'vidyalms.automation.review.task';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title'],
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 190],
                'note' => ['type' => 'string', 'maxLength' => 2000],
            ],
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
        return AutomationEffectClass::Records;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.create_review_task';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $title = $params['title'] ?? null;

        return \is_string($title) && trim($title) !== '' ? [] : ['automation.review_task.title_required'];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'title' => $context->param('title', ''),
            'note' => $context->param('note', ''),
            'subject_id' => $context->subjectId,
            'for_user_id' => $context->principal->userId,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $title = $context->param('title', '');

        if (!\is_string($title) || trim($title) === '') {
            return ActionOutcome::failed('review_task.title_missing', 'The step named no title.', false);
        }

        // The effect is the record itself: the audit row this outcome produces
        // and the parked queue row the step machine writes when it sees the
        // capability. There is nothing else to write, and inventing a second
        // task table would be a second review queue.
        return ActionOutcome::executed([], [
            'review_item' => $this->reviewItemKey(),
            'title' => trim($title),
            'note' => \is_string($context->param('note', '')) ? $context->param('note', '') : '',
            'subject_type' => $context->subjectType,
            'subject_id' => $context->subjectId,
            'for_user_id' => $context->principal->userId,
        ]);
    }
}
