<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRole;

/**
 * This role owns the organisation, so at least one active holder must remain.
 *
 * A capability interface probed with instanceof rather than a method on
 * OrgRoleInterface, following HoldersOfCapability beside AuthorizationPort:
 * the ownership invariant is a property some roles have and most do not, and a
 * required method would make every third-party role answer a question about a
 * rule it has nothing to do with.
 *
 * It is what replaced the literal string "org_owner" in the last-owner
 * invariant, in the erasure refusal and in the owner count the store runs. A
 * package registering its own ownership role therefore inherits the invariant
 * instead of finding the core counting a key it has never heard of.
 *
 * Absent: the role is an ordinary one. Nothing counts its holders and nothing
 * refuses to close the last of them.
 */
interface DeclaresOwnership
{
}
