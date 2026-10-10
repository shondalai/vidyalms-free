<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRole;

/**
 * This role says which of its rights a unit-scoped assignment may exercise.
 *
 * A capability interface probed with instanceof rather than a method on
 * OrgRoleInterface, following DeclaresOwnership beside it: unit reach is a
 * property some roles have and most do not, and a required method would make
 * every third-party role answer a question about a rule it has nothing to do
 * with. It is also the reason the interface can gain this seam at all after
 * its first provider has shipped.
 *
 * It replaced a literal. OrgScopeResolver::UNIT_SCOPED_ACTIONS is the reach of
 * a role that declares nothing, and it was the reach of every role, so a right
 * a package introduced could be named in the console vocabulary, be assigned,
 * and guard a route, and could never reach a unit. That is the one wall left
 * in an area whose whole point is that there are none.
 *
 * The declaration is per role and narrows nothing site-wide. A role naming a
 * right here says only that **its own** unit-scoped assignments may exercise
 * it; a different role permitting the same right is unaffected, because a
 * per-site reach that widened or shrank behind a role's back is a refusal no
 * console could explain.
 *
 * Absent: the role reaches a unit with exactly the four rights the free build
 * ships, which is what every role did before this existed.
 */
interface ReachesUnits
{
    /**
     * The subset of this role's actions() a unit-scoped assignment exercises.
     *
     * Rights outside actions() are ignored rather than refused: reach is a
     * narrowing of what the role already carries and can never be a way to
     * acquire a right the role does not have.
     *
     * @return list<string>
     */
    public function unitScopedActions(): array;
}
