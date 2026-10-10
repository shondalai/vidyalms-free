<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;

/**
 * One automation rule, at one version.
 *
 * Every field the design says cannot be retrofitted is present from version
 * one: re-entry, the two run caps, the cooldown, the run order, the scope, the
 * arming triple and the state. Retrofitting any of them changes every stored
 * rule payload, and the two caps in particular are read by the fan-out before
 * it enqueues rather than by any provider, which is why they are the rule's
 * own columns and not provider settings.
 *
 * The steps are a separate child table rather than a JSON array, for the
 * reason notification_rule_channels is one: "which rules use the webhook
 * action" and "which rules break if the Pro package is removed" are asked from
 * outside the rule, and a delimited column answers them with a LIKE scan.
 */
final class AutomationRule
{
    /**
     * @param list<AutomationStepDefinition> $steps
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $ruleKey,
        public readonly string $title,
        public readonly string $triggerKey,
        public readonly ?AvailabilityTree $conditions,
        public readonly array $steps,
        public readonly ReentryMode $reentry = ReentryMode::Never,
        public readonly ?int $timesPerSubject = null,
        public readonly ?int $totalCompletions = null,
        public readonly int $cooldownSeconds = 300,
        public readonly int $runOrder = 100,
        public readonly int $version = 1,
        public readonly bool $active = false,
        public readonly AutomationRuleState $state = AutomationRuleState::Disabled,
        public readonly string $subjectBinding = 'learner',
        public readonly string $scopeType = 'site',
        public readonly int $scopeId = 0,
        public readonly ?int $courseId = null,
        public readonly ?int $cohortId = null,
        public readonly ?int $organisationId = null,
        public readonly string $scheduleKind = 'immediate',
        public readonly ?string $anchorKey = null,
        public readonly ?int $armedBy = null,
        public readonly ?\DateTimeImmutable $armedAt = null,
        public readonly ?string $armedDigest = null,
        public readonly ?int $maxAiRunsPerOccurrence = null,
        public readonly ?int $maxAiRunsPerArming = null,
        public readonly ?int $createdBy = null,
        public readonly ?\DateTimeImmutable $lastDryRunAt = null,
        public readonly int $queuedCount = 0,
        public readonly int $executedCount = 0,
        public readonly int $suppressedCount = 0,
        public readonly int $failedCount = 0,
    ) {
    }

    /**
     * The identity every step of every run executes under.
     *
     * Never a literal system user: the audit gap the idempotency literature
     * names is a step gated under one business identity and completed under
     * another, and recording the authoriser on the action row is what closes
     * it. A rule that has never been armed has no authoriser, which is why
     * this is nullable and why an unarmed rule cannot fan out.
     */
    public function authorisingUserId(): ?int
    {
        return $this->armedBy;
    }

    /**
     * @return list<string>
     */
    public function actionKeys(): array
    {
        return array_values(array_map(
            static fn (AutomationStepDefinition $step): string => $step->actionKey,
            $this->steps,
        ));
    }

    public function hasAction(string $actionKey): bool
    {
        return \in_array($actionKey, $this->actionKeys(), true);
    }

    public function step(int $index): ?AutomationStepDefinition
    {
        foreach ($this->steps as $step) {
            if ($step->stepIndex === $index) {
                return $step;
            }
        }

        return null;
    }
}
