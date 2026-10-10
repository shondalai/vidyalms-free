<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\BatchEvaluatesItems;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\FiltersLearners;
use Shondalai\VidyaLMS\Core\Registry\Availability\PayloadValidationResult;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Availability\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * A held capability, asked of the authorization port in the course's scope.
 *
 * It is set-like and therefore allowed to return never: a learner who does
 * not hold a capability cannot acquire one by waiting, only by an
 * administrative act.
 *
 * Its bulk filter is a loop over the port, because the port answers per
 * principal. That is stated here so nobody assumes an indexed query, and the
 * batch hint lets the roster path decide whether to call it at all.
 *
 * Role leaves are excluded from the cached never set and evaluated per read,
 * because no role change reaches the core: the port exposes one forward
 * method with no event, no version stamp and no host hook, so an
 * administrator moving a user between host groups produces no signal to
 * invalidate on. Caching an exclusion nothing can invalidate is the failure
 * that is invisible until credentials are wrong. The cost is bounded because
 * the batch phase makes one call per capability the course's rules name, not
 * one per item.
 *
 * In practice a role leaf is usually authored negated, to hide staff notes
 * from learners, and the negated form is normally paired with a false
 * visibility flag so the row is absent rather than explained.
 */
final class RoleCondition implements
    AvailabilityConditionInterface,
    ConditionMetadataInterface,
    BatchEvaluatesItems,
    FiltersLearners,
    SupportsPayloadVersions,
    ValidatesPayload
{
    use EvaluatesManyByEvaluate;

    public const KEY = 'role';

    public function __construct(private readonly AuthorizationPort $authorization)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.availability.condition.role';
    }

    public function group(): string
    {
        return ConditionMetadataInterface::GROUP_AUDIENCE;
    }

    public function order(): int
    {
        return 40;
    }

    public function advisory(): bool
    {
        return false;
    }

    /**
     * Automation is declared, and it is the surface where this leaf earns
     * its keep: an action that touches instructors and not learners is a
     * capability test, and without it every such rule would be written as a
     * hardcoded audience. The capability is read through the authorization
     * port for the subject, which needs no request behind it, so a scheduler
     * can ask it.
     */
    /**
     * Redemption gating is excluded, deliberately, and this is the one
     * of the four that is arguable.
     *
     * A role reads only the principal, which a redemption does have, so
     * a members-only coupon looks buildable from it. It is excluded
     * anyway because a coupon restricted by role is a **price** rule
     * rather than an eligibility gate: the pricing fold already has
     * member pricing as a registered rule with a trace entry, and
     * expressing the same thing twice in two engines is how two answers
     * to one question appear on one order. Reverse this only with the
     * pricing rule withdrawn.
     */
    public function applicability(): array
    {
        return [
            AvailabilitySurface::ContentGating,
            AvailabilitySurface::NotificationRule,
            AvailabilitySurface::AwardCriteria,
            AvailabilitySurface::EnrolmentGating,
            AvailabilitySurface::Automation,
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function supportedPayloadVersions(): array
    {
        return [1];
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['capability'],
            'properties' => [
                'capability' => ['type' => 'string', 'enum' => Capability::all()],
            ],
            'additionalProperties' => false,
        ];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $capability = $this->capabilityOf($payload);

        if ($capability === null) {
            return AvailabilityOutcome::blocked('availability.role.unconfigured');
        }

        if ($context->principal->isGuest()) {
            $held = false;
        } else {
            $held = $this->authorization->can($context->principal->userId, $capability, $this->resourceOf($context));
        }

        if ($held !== $negated) {
            return AvailabilityOutcome::available();
        }

        return AvailabilityOutcome::never(
            $negated ? 'availability.role.forbidden' : 'availability.role.required',
            ['capability' => $capability],
        );
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason(
            $negated ? 'availability.role.forbidden' : 'availability.role.required',
            ['capability' => $this->capabilityOf($payload)],
        );
    }

    public function filterLearners(array $payload, bool $negated, array $userIds, AvailabilityContext $context): array
    {
        $capability = $this->capabilityOf($payload);

        if ($capability === null) {
            return [];
        }

        $resource = $this->resourceOf($context);
        $matched = [];

        foreach ($userIds as $userId) {
            if ($this->authorization->can($userId, $capability, $resource) !== $negated) {
                $matched[] = $userId;
            }
        }

        return $matched;
    }

    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult
    {
        $capability = $this->capabilityOf($payload);

        if ($capability === null) {
            return PayloadValidationResult::error('availability.role.unknown_capability', [
                'capability' => $payload['capability'] ?? null,
            ]);
        }

        return PayloadValidationResult::valid();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function capabilityOf(array $payload): ?string
    {
        $capability = $payload['capability'] ?? null;

        return \is_string($capability) && \in_array($capability, Capability::all(), true) ? $capability : null;
    }

    private function resourceOf(AvailabilityContext $context): ?string
    {
        $courseId = $context->courseId();

        return $courseId === null ? null : 'course:' . $courseId;
    }
}
