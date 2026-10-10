<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
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
 * Enrol the subject on a named course, through the shipped grant.
 *
 * The rules, the authorisation and the idempotence all live in
 * GrantEntitlement, exactly as they do for a manual enrolment: this action
 * chooses no source vocabulary of its own, writes no row itself and holds no
 * second copy of the entitlement invariants.
 *
 * **The source ref is the provenance**, and it names the rule rather than the
 * run, so unenrol can find what enrol wrote even across occurrences. Loop
 * protection mechanism one needs provenance on every write an automation
 * makes, and this is where an entitlement carries it.
 */
final class EnrolAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    DeclaresReversal,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload
{
    public const KEY = 'enrol';

    /**
     * The forward half of a reversible pair declares the capability and names
     * no inverse, exactly as the cohort and tag pairs do. Unenrol names this
     * action; the two halves are not symmetrical in what they can say, and
     * both are reversible.
     */
    public function reverses(): ?string
    {
        return null;
    }

    public function __construct(
        private readonly GrantEntitlement $grants,
        private readonly EntitlementRepository $entitlements,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.action.enrol';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['course_id'],
            'additionalProperties' => false,
            'properties' => ['course_id' => ['type' => 'integer', 'minimum' => 1]],
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
        return 'vidyalms.automation.sentence.enrol';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $courseId = $params['course_id'] ?? null;

        return \is_int($courseId) && $courseId > 0 ? [] : ['automation.enrol.course_id_required'];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'would_enrol' => $context->actsForUserId(),
            'course_id' => self::courseId($context),
            'source_ref' => self::sourceRef($context),
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $courseId = self::courseId($context);
        $userId = $context->actsForUserId() ?? 0;

        if ($courseId < 1 || $userId < 1) {
            return ActionOutcome::failed('enrol.params_missing', 'The step named no course or no learner.', false);
        }

        $ref = self::sourceRef($context);
        $existing = $this->entitlements->findBySourceRef(Entitlement::SOURCE_MANUAL, $ref);

        if ($existing !== null && $existing->status === Entitlement::STATUS_ACTIVE) {
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE, ['entitlement_id' => $existing->id()]);
        }

        if ($context->dryRun) {
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        $entitlement = $this->grants->execute(
            $context->principal,
            $userId,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            $ref,
        );

        return ActionOutcome::executed([], ['entitlement_id' => $entitlement->id(), 'course_id' => $courseId]);
    }

    /**
     * The provenance grammar, composed in one place because unenrol has to
     * spell it identically to find what this wrote.
     */
    public static function sourceRef(AutomationActionContext $context): string
    {
        return sprintf(
            'automation:%d:user:%d:course:%d',
            $context->ruleId,
            $context->actsForUserId() ?? 0,
            self::courseId($context),
        );
    }

    private static function courseId(AutomationActionContext $context): int
    {
        $value = $context->param('course_id', 0);

        return \is_int($value) ? $value : 0;
    }
}
