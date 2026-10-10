<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;

/**
 * One authored step of one version of one rule.
 *
 * step_index is real because ordering is the semantics and not a display
 * preference. The two policy keys are real strings so the save-time validation
 * that refuses an inert retry-plus-continue combination can run in SQL as well
 * as in code. requires_approval_before_effect is a real column because the
 * save-time refusal that stops an action publishing has to be a query over
 * rules rather than a walk of every params document.
 *
 * The delay is an anchor key and a payload rather than a duration column,
 * because the six delay kinds every product ships map onto the shipped
 * ScheduleAnchor registry: a seventh is then a package rather than a
 * migration, and the same anchor vocabulary already serves availability
 * conditions, cohort schedules and notification schedules.
 */
final class AutomationStepDefinition
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $delay
     */
    public function __construct(
        public readonly int $stepIndex,
        public readonly string $actionKey,
        public readonly array $params = [],
        public readonly int $paramsVersion = 1,
        public readonly ?AvailabilityTree $guardConditions = null,
        public readonly ?string $delayAnchorKey = null,
        public readonly array $delay = [],
        public readonly string $retryPolicyKey = 'external_default',
        public readonly string $failurePolicyKey = 'park',
        public readonly bool $requiresApprovalBeforeEffect = false,
        public readonly bool $producesReviewItem = false,
        public readonly ?string $armedPromptRef = null,
        public readonly ?int $ownerUserId = null,
        /**
         * The version of the delay payload, which belongs to the anchor
         * provider and not to the core.
         *
         * It was written as the literal 1 in two places with no member to
         * carry it, so the one provider-shaped document StoredShape exists for
         * was the one document it did not version: an anchor package shipping
         * a second payload shape had nothing to key a migration against.
         * Trailing and defaulted, so an author who names no version gets the
         * first one.
         */
        public readonly int $delayVersion = 1,
    ) {
    }
}
