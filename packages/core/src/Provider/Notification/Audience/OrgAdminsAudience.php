<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;

/**
 * The administrators of the scoped organisation, at organisation scope.
 *
 * It is resolved from org_role_assignments and org_memberships and **never**
 * from HoldersOfCapability. That reverse lookup answers site-wide from the
 * host CMS, so an escalation raised on a screen showing one organisation
 * would mail every site manager on the site, which is the failure the design
 * names in the sentence that asks for these three resolvers.
 *
 * "Administrator" is a right and not a key. The holders are everyone whose
 * registered role permits the people right inside this organisation, so a
 * third-party role registered at KernelReady is in this audience the moment
 * it says it manages people, and no literal role key appears here at all.
 *
 * It declares FiltersByVisibility and HonoursOrgScope, and the second marker
 * is the load-bearing one: without a tenant it resolves to nobody, because an
 * organisation audience that fell back to a site-wide answer would be the
 * exact broadcast the whole tenancy rule exists to prevent.
 *
 * **The tenant may arrive as a resolved scope or as an occurrence's stamp.**
 * A queued notification records no actor, so a fan-out can never hold a
 * scope; what it holds is the organisation the raising use case resolved
 * under its own scope and stamped on the event. That is enough to narrow this
 * read and is not enough to authorise anything, which is exactly the
 * distinction OrgTenant exists to make.
 */
final class OrgAdminsAudience implements AudienceResolverInterface, FiltersByVisibility, HonoursOrgScope
{
    public const KEY = 'org_admins';

    /** The right that makes somebody an administrator of an organisation's people. */
    public const ADMINISTERING_ACTION = 'people';

    /** Assignments at the organisation's own scope, as org_role_assignments spells it. */
    public const SCOPE_ORGANISATION = 'organisation';

    public function __construct(private readonly OrgRoleRegistry $roles)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.org_admins';
    }

    public function resolve(AudienceContext $context): iterable
    {
        $tenant = $context->tenant();

        if ($tenant === null || !OrgAudience::visibleUnder($context)) {
            return;
        }

        $permitted = OrgAudience::rolesPermitting($this->roles, self::ADMINISTERING_ACTION);

        if ($permitted === []) {
            return;
        }

        // Through the scoped reader, so the tenant predicate is injected
        // rather than typed here and the read is where the tenancy gate says
        // an organisation read belongs. The joined table carries the same
        // tenant on its own join condition, which is what stops a role
        // assignment from another organisation reaching this roster.
        $query = OrgScopedReader::from($tenant, 'org_memberships', 'm')->select(['m.user_id'])
            ->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [['m.organisation_id', 'r.organisation_id']])
            ->where('m.state', '=', 'active')
            ->where('r.scope_type', '=', self::SCOPE_ORGANISATION)
            ->where('r.scope_id', '=', $tenant->organisationId)
            ->whereIn('r.role_key', $permitted)
            ->orderBy('m.user_id');

        yield from OrgAudience::distinctUserIds($context, $query);
    }
}
