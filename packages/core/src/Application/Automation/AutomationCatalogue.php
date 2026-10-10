<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresCost;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresIdempotencyDiscriminator;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresReferences;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresReversal;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\EmitsOccurrences;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\ProducesReviewItem;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsBatch;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresSubjectTypes;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * What a rule may be built out of, read from the registries and from nothing
 * else.
 *
 * The builder composes sentences rather than expressions, so the vocabulary
 * has to be a read: an admin screen holding its own list of actions is a
 * hardcoded vocabulary pretending to be a seam, and a third-party action would
 * then be unreachable from the one screen that authors rules.
 *
 * Two exclusions are rules rather than tidiness. Only a trigger that is an
 * event trigger or a sweep trigger is offered, because those are the two kinds
 * the fan-out can resolve a subject from; a direct-outbox identity that is
 * neither declares no subject, so a rule built on one could never fan out.
 * And only a condition declaring the automation surface is offered, because a
 * leaf used outside its declared applicability is a save-time refusal, and
 * offering one the save will reject is a form the author cannot submit.
 */
final class AutomationCatalogue
{
    /**
     * The capability interfaces the contract publishes, in the order it
     * publishes them. Probed with instanceof, each with its own documented
     * meaning when absent.
     *
     * @var array<string, class-string>
     */
    private const CAPABILITIES = [
        'SupportsDryRun' => SupportsDryRun::class,
        'RequiresApprovalBeforeEffect' => RequiresApprovalBeforeEffect::class,
        'ProducesReviewItem' => ProducesReviewItem::class,
        'DeclaresCost' => DeclaresCost::class,
        'SupportsBatch' => SupportsBatch::class,
        'DeclaresReferences' => DeclaresReferences::class,
        'ValidatesPayload' => ValidatesPayload::class,
        'SupportsPayloadVersions' => SupportsPayloadVersions::class,
        'DeclaresIdempotencyDiscriminator' => DeclaresIdempotencyDiscriminator::class,
        // The three R6 added when it closed the findings that the approval
        // gate, the cascade refusal and the builder sentence were each keyed
        // to something other than a declaration.
        'DeclaresEffectClass' => DeclaresEffectClass::class,
        'EmitsOccurrences' => EmitsOccurrences::class,
        'DeclaresSentence' => DeclaresSentence::class,
    ];

    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly AutomationActionRegistry $actions,
        private readonly RetryPolicyRegistry $retryPolicies,
        private readonly FailurePolicyRegistry $failurePolicies,
        private readonly ScheduleAnchorRegistry $anchors,
        private readonly AccessGuard $guard,
        private readonly int $defaultCooldownSeconds = 300,
        private readonly int $cascadeDepthCeiling = 5,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort $translator = null,
    ) {
    }

    private function text(string $value): string
    {
        return str_starts_with($value, 'vidyalms.') && $this->translator !== null
            ? $this->translator->translate($value) : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal): array
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        return [
            'triggers' => $this->triggerList(),
            'conditions' => $this->conditionList(),
            'actions' => $this->actionList(),
            'retry_policies' => $this->retryList(),
            'failure_policies' => $this->failureList(),
            'anchors' => $this->anchorList(),
            'limits' => [
                'cooldown_seconds_default' => $this->defaultCooldownSeconds,
                'cascade_depth_ceiling' => $this->cascadeDepthCeiling,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function triggerList(): array
    {
        $out = [];

        foreach ($this->triggers->keys() as $key) {
            $trigger = $this->triggers->get($key);

            if (!$trigger instanceof EventTriggerInterface && !$trigger instanceof SweepTriggerInterface) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $this->text($trigger->label()),
                'kind' => $trigger instanceof SweepTriggerInterface ? 'sweep' : 'event',
                // The trigger's real subject types, and no longer a hardcoded
                // empty list for every one of them. It was the reason the
                // builder could filter neither side of the applicability
                // check, and the reason the save-time refusal the action seam
                // promises had nothing to refuse against.
                'subject_types' => $trigger instanceof DeclaresSubjectTypes
                    ? array_values($trigger->subjectTypes())
                    : [],
                'placeholders' => array_values(array_map(
                    static fn (mixed $name): string => (string) $name,
                    $trigger->declaredPlaceholders(),
                )),
                'sentence' => $trigger instanceof DeclaresSentence ? $this->text($trigger->sentence()) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditionList(): array
    {
        $out = [];

        foreach ($this->conditions->keys() as $key) {
            $condition = $this->conditions->get($key);

            if (!\in_array(AvailabilitySurface::Automation, $condition->applicability(), true)) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $this->text($condition->label()),
                'params_schema' => $condition instanceof ConditionMetadataInterface
                    ? $condition->paramsSchema()
                    : [],
                'sentence' => $condition instanceof DeclaresSentence ? $this->text($condition->sentence()) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actionList(): array
    {
        $out = [];

        foreach ($this->actions->keys() as $key) {
            $action = $this->actions->get($key);

            if (!$action instanceof AutomationActionInterface) {
                continue;
            }

            $out[] = [
                'key' => $key,
                'label' => $this->text($action->label()),
                'params_schema' => $action->paramsSchema(),
                'reversible' => $action instanceof DeclaresReversal,
                'reverses' => $action instanceof DeclaresReversal ? $action->reverses() : null,
                'capabilities' => $this->capabilitiesOf($action),
                'payload_versions' => $action instanceof SupportsPayloadVersions
                    ? array_values($action->supportedParamsVersions())
                    : [1],
                // Published, so the builder can filter an action out of a
                // trigger it may not sit behind rather than offering a form
                // the save will reject.
                'applicability' => $action->applicability(),
                'effect_class' => $action instanceof DeclaresEffectClass
                    ? $action->effectClass()->value
                    : null,
                'sentence' => $action instanceof DeclaresSentence ? $this->text($action->sentence()) : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function capabilitiesOf(AutomationActionInterface $action): array
    {
        $declared = [];

        foreach (self::CAPABILITIES as $name => $interface) {
            if ($action instanceof $interface) {
                $declared[] = $name;
            }
        }

        return $declared;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function retryList(): array
    {
        $out = [];

        foreach ($this->retryPolicies->keys() as $key) {
            $policy = $this->retryPolicies->get($key);
            /** @var \Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface $policy */
            $plan = $policy->plan();
            $out[] = [
                'key' => $key,
                'label' => $this->text($policy->label()),
                'ladder' => array_values($plan->ladderSeconds),
                'max_attempts' => $plan->maxAttempts,
                'jitter' => $plan->jitter,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failureList(): array
    {
        $out = [];

        foreach ($this->failurePolicies->keys() as $key) {
            $policy = $this->failurePolicies->get($key);
            /** @var \Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface $policy */
            $disposition = $policy->disposition();
            $out[] = [
                'key' => $key,
                'label' => $this->text($policy->label()),
                'behaviour' => $disposition->value,
                'cancels_retries' => $disposition->cancelsRetries(),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anchorList(): array
    {
        $out = [];

        foreach ($this->anchors->keys() as $key) {
            $anchor = $this->anchors->get($key);
            $out[] = [
                'key' => $key,
                'label' => $this->text($anchor->label()),
                // The anchor's own schema, and no longer an empty literal for
                // every one of them. An author composing a delay had to read
                // the provider's source to learn that `absolute` takes `at`,
                // which is exactly what a params schema exists to prevent.
                'params_schema' => $anchor instanceof DeclaresAnchorParams ? $anchor->paramsSchema() : [],
            ];
        }

        return $out;
    }
}
