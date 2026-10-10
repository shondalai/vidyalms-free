<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * One thing a fired rule decided: this organisation, this unit, this role.
 *
 * It is a decision and not a write. Nothing here touches a membership; the
 * caller applies grants, and applies them under the removal rule, which is why
 * the decision is a value the caller can inspect, store and show before it
 * acts on it.
 */
final class MappingGrant
{
    private function __construct(
        public readonly int $organisationId,
        public readonly ?int $orgUnitId,
        public readonly string $roleKey,
        /** Which rule in the connection's list produced it, zero based, for the explainer. */
        public readonly int $ruleIndex,
        /** The asserted key that matched, so the explainer can name the group by name. */
        public readonly ?string $matchedKey,
    ) {
    }

    public static function of(
        int $organisationId,
        ?int $orgUnitId,
        string $roleKey,
        int $ruleIndex,
        ?string $matchedKey,
    ): self {
        return new self($organisationId, $orgUnitId, $roleKey, $ruleIndex, $matchedKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'organisation_id' => $this->organisationId,
            'org_unit_id' => $this->orgUnitId,
            'role_key' => $this->roleKey,
            'rule' => $this->ruleIndex,
            'matched' => $this->matchedKey,
        ];
    }
}
