<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;

/**
 * The people administering one unit of the scoped organisation.
 *
 * This is the audience a unit-scoped assignment makes reachable, and it is
 * why the free build lets an org_admin be assigned at unit scope at all: a
 * role that can be held at a unit and an audience that cannot resolve one
 * would be half a feature.
 *
 * The unit narrowing is the OrgScope's own, applied through
 * OrgScope::constrainUnit(), which joins the indexed closure table rather
 * than walking the tree. A scope with no unit reaches every unit-scoped
 * administrator in the organisation, which is the right answer for an
 * organisation-wide escalation: those people administer part of it.
 *
 * An organisation-scoped assignment is deliberately **not** in this audience.
 * Escalating to unit managers and escalating to organisation administrators
 * are two different messages, and folding one into the other would make the
 * two rules indistinguishable at the point somebody configures them.
 */
final class OrgUnitManagersAudience implements AudienceResolverInterface, FiltersByVisibility, HonoursOrgScope
{
    public const KEY = 'org_unit_managers';

    /** Assignments at a unit's scope, as org_role_assignments spells it. */
    public const SCOPE_UNIT = 'unit';

    public function __construct(private readonly OrgRoleRegistry $roles)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.org_unit_managers';
    }

    public function resolve(AudienceContext $context): iterable
    {
        $scope = $context->orgScope;

        if ($scope === null || !OrgAudience::visibleUnder($context)) {
            return;
        }

        $permitted = OrgAudience::rolesPermitting($this->roles, OrgAdminsAudience::ADMINISTERING_ACTION);

        if ($permitted === []) {
            return;
        }

        $query = Query::from('org_memberships', 'm')->select(['m.user_id'])
            ->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [['m.organisation_id', 'r.organisation_id']])
            ->where('m.organisation_id', '=', $scope->organisationId)
            ->where('m.state', '=', 'active')
            ->where('r.scope_type', '=', self::SCOPE_UNIT)
            ->whereIn('r.role_key', $permitted)
            ->orderBy('m.user_id');
        $scope->constrainUnit($query, 'r.scope_id');

        yield from OrgAudience::distinctUserIds($context, $query);
    }
}
