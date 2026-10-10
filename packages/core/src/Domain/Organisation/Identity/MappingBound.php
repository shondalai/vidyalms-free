<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;

/**
 * What a connection's rules are allowed to reach, resolved fresh.
 *
 * The bound is enforced twice and this is the second time. At save, the refs a
 * rule names are resolved for the authoring principal and refused if they fall
 * outside the connection's own organisation subtree. Here, at evaluation, they
 * are resolved again against the connection's own organisation, because a
 * stored row outlives the authorisation that wrote it: the administrator who
 * saved it may have left, the unit may have moved, the organisation may have
 * been archived.
 *
 * Deny by default is no defence against the attack this closes, because the
 * rule that fires is the one the attacker wrote. An administrator of one
 * organisation writing a rule whose target names another organisation and an
 * administrative role would make everyone their rule matched an administrator
 * of a tenant they have nothing to do with, and no administrator of that
 * tenant would be involved at any point.
 *
 * **No mapping may ever produce the site administration capability.** That is
 * enforced here rather than in the role registry, because the registry answers
 * what a role is and this answers what a directory push may hand somebody. A
 * directory names a group; an administrator-authored rule names a role; no
 * combination of the two makes anybody an administrator of the site.
 */
final class MappingBound
{
    /**
     * @param list<int>                    $unitIds  the organisation's own units, resolved now
     * @param array<string, list<string>>  $roles    role key to its capabilities, already filtered
     *                                               to the roles a mapping rule may name at all
     */
    private function __construct(
        public readonly int $organisationId,
        public readonly array $unitIds,
        public readonly array $roles,
    ) {
        if ($organisationId < 1) {
            throw new \InvalidArgumentException('A mapping bound needs one positive organisation.');
        }
    }

    /**
     * @param list<int>                   $unitIds
     * @param array<string, list<string>> $mappableRoles
     */
    public static function forConnection(int $organisationId, array $unitIds, array $mappableRoles): self
    {
        return new self($organisationId, array_values(array_unique($unitIds)), $mappableRoles);
    }

    /**
     * Whether a rule's target organisation is this connection's own.
     *
     * Null passes, and means the rule named no organisation and therefore
     * targets the connection's own. A rule that names one names it exactly:
     * there is no parent walk here, because the connection's authority is over
     * one organisation and a subtree of organisations is not the model that
     * shipped (units are).
     */
    public function admitsOrganisation(?int $organisationId): bool
    {
        return $organisationId === null || $organisationId === $this->organisationId;
    }

    /** Whether a rule's target unit is one of this organisation's own. */
    public function admitsUnit(?int $unitId): bool
    {
        return $unitId === null || \in_array($unitId, $this->unitIds, true);
    }

    /** Whether a rule may name this role at all. */
    public function admitsRole(string $roleKey): bool
    {
        $capabilities = $this->roles[$roleKey] ?? null;

        if ($capabilities === null) {
            return false;
        }

        return !\in_array(Capability::ADMIN, $capabilities, true);
    }
}
