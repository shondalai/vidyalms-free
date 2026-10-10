<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlementBySourceRef;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresReversal;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * The inverse of enrol, and it says so.
 *
 * `reversible` is a real property with real values, and the reversible actions
 * name their inverse: unenrol reverses enrol, remove_from_cohort reverses
 * add_to_cohort, clear_tag reverses set_tag. Marking every action reversible
 * made the field unreadable, which matters because park is the default failure
 * policy for every action whose reversible is false.
 *
 * It revokes by the source ref enrol wrote rather than by entitlement id, so
 * it cannot revoke an entitlement some other path granted: a rule that
 * unenrols somebody a manager enrolled by hand is a support incident, and the
 * grammar is what makes it impossible rather than unlikely.
 */
final class UnenrolAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload,
    DeclaresReversal
{
    public const KEY = 'unenrol';

    /** What this action reverses, published rather than implied. */
    public const REVERSES = EnrolAction::KEY;

    /**
     * Never null here: this action exists to undo enrol, so a null would be
     * an action naming itself irreversible while being the reversal.
     */
    public function reverses(): string
    {
        return self::REVERSES;
    }

    public function __construct(private readonly RevokeEntitlementBySourceRef $revoke,
        private readonly \Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository $entitlements)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.action.unenrol';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['course_id'],
            'additionalProperties' => false,
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'source_rule_id' => ['type' => 'integer', 'minimum' => 1, 'title' => 'Rule that granted access'],
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
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Membership;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.unenrol';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $courseId = $params['course_id'] ?? null;

        if (isset($params['source_rule_id']) && (!is_int($params['source_rule_id']) || $params['source_rule_id'] < 1)) {
            return ['automation.unenrol.source_rule_id_required'];
        }

        return \is_int($courseId) && $courseId > 0 ? [] : ['automation.unenrol.course_id_required'];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        $entitlement = $this->entitlements->findBySourceRef(Entitlement::SOURCE_MANUAL, $this->sourceRef($context));
        return [
            'would_unenrol' => $entitlement?->status === Entitlement::STATUS_ACTIVE ? $context->actsForUserId() : null,
            'source_ref' => $this->sourceRef($context),
            'reverses' => self::REVERSES,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        if ($context->dryRun) {
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        $entitlement = $this->revoke->execute(
            $context->principal,
            Entitlement::SOURCE_MANUAL,
            $this->sourceRef($context),
            'automation',
            'automation:' . $context->ruleId . ':' . $context->runId,
        );

        if ($entitlement === null) {
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE);
        }

        return ActionOutcome::executed([], ['entitlement_id' => $entitlement->id()]);
    }

    private function sourceRef(AutomationActionContext $context): string
    {
        return sprintf('automation:%d:user:%d:course:%d',
            $context->param('source_rule_id', $context->ruleId), $context->actsForUserId() ?? 0, $context->param('course_id', 0));
    }
}
