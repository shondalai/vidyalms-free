<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

/**
 * The capability vocabulary of the core. Joomla maps these to ACL actions in
 * access.xml; WordPress maps them to capabilities on roles. Checks happen
 * inside use cases, not only in controllers, so the API and the React apps
 * cannot bypass them.
 */
final class Capability
{
    /** Full administration of the component. */
    public const ADMIN = 'vidyalms.admin';

    /** Manage courses, settings and people without full admin. */
    public const MANAGE = 'vidyalms.manage';

    /** Author and edit courses. In the free build, site administrators hold this. */
    public const TEACH = 'vidyalms.teach';

    /** Grade attempts and submissions, leave feedback. */
    public const GRADE = 'vidyalms.grade';

    /** Enrol other people and revoke their entitlements. */
    public const ENROL_OTHERS = 'vidyalms.enrol';

    /** View reports beyond one's own progress. */
    public const VIEW_REPORTS = 'vidyalms.reports';

    /**
     * Entry to the delegated organisation surface, and nothing more.
     *
     * What it grants: permission to knock on an organisation route. It is the
     * one capability an organisation administrator holds, and the free
     * organisations console is the code path that reaches it.
     *
     * At what scope: none. It is asked with no resource, so a host resolves it
     * against the component asset, which means it says only that this person
     * may be a delegated administrator of *some* organisation. Which
     * organisation is never a capability answer: it is the org_role_assignments
     * predicate the core evaluates immediately afterwards, from its own rows.
     *
     * **No site-wide use case may ever guard on it alone.** Because a null
     * resource resolves to the component asset, a site-wide read guarded on
     * this alone would hand every organisation administrator on the site the
     * whole site, which is the precise failure the tenancy predicate exists to
     * prevent. It is why the four shipped site-wide use cases refuse an
     * organisation administrator with no core edit at all: they ask for
     * something else, and an org admin holds nothing else. Granting one second
     * capability on the component asset would undo that silently.
     *
     * The rule is enforced rather than stated. tooling/ci/gate.php refuses a
     * require() or requireAny() carrying this capability with no organisation
     * resource, and refuses this constant being named anywhere but here and in
     * OrgScopeResolver, which is the one guard site and evaluates the tenant
     * predicate on the next line.
     */
    public const MANAGE_GROUPS = 'vidyalms.manage_groups';

    private function __construct()
    {
    }

    /**
     * The capabilities the delegated organisation surface is entered under.
     *
     * Named here rather than by each org role, because the conformance check
     * that keeps the capability out of site-wide guards works by refusing the
     * constant anywhere but this file and the scope resolver. A role provider
     * asks this list instead, which keeps both true: a role declares what it
     * operates under, and the constant stays where the rule can see it.
     *
     * It is one capability and the length of that list is the invariant.
     *
     * @return list<string>
     */
    public static function organisationSurface(): array
    {
        return [self::MANAGE_GROUPS];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ADMIN,
            self::MANAGE,
            self::TEACH,
            self::GRADE,
            self::ENROL_OTHERS,
            self::VIEW_REPORTS,
            self::MANAGE_GROUPS,
        ];
    }

}
