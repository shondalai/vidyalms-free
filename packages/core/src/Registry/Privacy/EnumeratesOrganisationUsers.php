<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * The contributor can name everybody inside one organisation whose rows it
 * holds.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** {@see PrivacyContributorInterface} is implemented by shipped
 * providers and, once Pro registers one, by packages this repository does not
 * build; adding a fifth required method to it would break every one of them on
 * the day their host is upgraded, which is the failure the house pattern exists
 * to prevent. So it is probed with `instanceof`, exactly as
 * `ContextAwareEnrolmentRule` is probed over the enrolment rule contract.
 *
 * Default when absent: the caller enumerates members from `org_memberships`
 * alone. That is the honest fallback and it is also why the method exists: it
 * follows Moodle's `core_userlist_provider`, which was added specifically so
 * user data could be removed for users matching a criterion **as a set operation
 * over a context** rather than as a loop over ids somebody assembled elsewhere.
 * A contributor holding rows about a person who is no longer a member is exactly
 * the case a membership query misses.
 */
interface EnumeratesOrganisationUsers
{
    /**
     * Every user id this contributor holds a row about inside this scope.
     *
     * An iterable rather than an array, so a contributor over a large table
     * yields rather than materialising a tenant's whole membership in memory.
     *
     * @return iterable<int>
     */
    public function usersInOrganisation(OrgPrivacyContext $context): iterable;
}
