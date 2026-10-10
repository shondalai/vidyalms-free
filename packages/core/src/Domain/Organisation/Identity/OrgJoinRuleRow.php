<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * One organisation's own join rule row, as a read model.
 *
 * A row and never a boolean on a domain. Auto-join is a grant of access: it
 * needs an owner, an effective date, the role it grants, a state and a trail
 * of who turned it on, and every join it causes writes an event naming it. A
 * configuration flag that admits anyone on a domain gives none of that, and
 * the day somebody asks how a stranger got in there is nothing to read.
 *
 * The free build registers exactly one rule, invitation only, and this row is
 * what makes that a **selection** rather than a compile-time constant. Before
 * the row existed the registry accepted registrations that no site could ever
 * choose, which is a seam that looks open and is closed.
 */
final class OrgJoinRuleRow
{
    /**
     * @param array<string, mixed> $params the provider's own body, envelope stripped
     */
    private function __construct(
        public readonly int $id,
        public readonly int $organisationId,
        public readonly string $ruleKey,
        public readonly array $params,
        public readonly int $paramsVersion,
        public readonly string $roleKey,
        public readonly string $state,
        public readonly ?string $effectiveFrom,
        public readonly ?string $effectiveTo,
    ) {
    }

    /**
     * @param array<string, mixed> $storedParams the stored JSON, envelope and all
     */
    public static function fromRow(
        int $id,
        int $organisationId,
        string $ruleKey,
        array $storedParams,
        string $roleKey,
        string $state,
        ?string $effectiveFrom,
        ?string $effectiveTo,
    ): self {
        return new self(
            $id,
            $organisationId,
            $ruleKey,
            StoredShape::bodyOf($storedParams),
            StoredShape::versionOf($storedParams),
            $roleKey,
            $state,
            $effectiveFrom,
            $effectiveTo,
        );
    }

    /**
     * Whether this rule is in force at this instant.
     *
     * Both bounds are inclusive of the open side and exclusive of the closed
     * one, which is how every other window in this product reads: a rule
     * effective from midnight is in force at midnight, and one effective to
     * midnight is not.
     */
    public function isInForce(\DateTimeImmutable $now): bool
    {
        if ($this->state !== 'active') {
            return false;
        }

        $stamp = $now->format('Y-m-d H:i:s');

        if ($this->effectiveFrom !== null && $stamp < $this->effectiveFrom) {
            return false;
        }

        return $this->effectiveTo === null || $stamp < $this->effectiveTo;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $resolved): array
    {
        return [
            'id' => $this->id,
            'organisation_id' => $this->organisationId,
            'rule_key' => $this->ruleKey,
            'params' => $this->params,
            'params_version' => $this->paramsVersion,
            'role_key' => $this->roleKey,
            'state' => $this->state,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            // Fail closed and say so. A rule whose provider is not installed
            // admits nobody, and the screen lists it as unresolved rather than
            // as working.
            'resolved' => $resolved,
        ];
    }
}
