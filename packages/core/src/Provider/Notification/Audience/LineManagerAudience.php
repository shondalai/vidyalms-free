<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;

/**
 * The learner's own manager inside the scoped organisation.
 *
 * Docebo's documented gap is the argument for making this first class: its
 * learning-plan notifications have no manager option, so sites work around it
 * by enrolling escalation targets as instructors to receive the instructor
 * notification, which gives an escalation target a teaching role over
 * somebody's coursework.
 *
 * The recipient is org_memberships.manager_user_id on the **learner's own
 * active membership in this organisation**, which is why that column is a
 * real column and free: this resolver reads it directly on every send.
 *
 * **Two guards, not one.** The write path validates manager_user_id against
 * an active membership in the same organisation, and this read repeats the
 * check, because a validated write and a live read disagree the moment the
 * named manager leaves. Without the second guard a departed manager keeps
 * receiving this organisation's compliance reminders, which carry its
 * learners' names and their due dates, after their own access has ended.
 */
final class LineManagerAudience implements AudienceResolverInterface, FiltersByVisibility, HonoursOrgScope
{
    public const KEY = 'line_manager';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.line_manager';
    }

    public function resolve(AudienceContext $context): iterable
    {
        $scope = $context->orgScope;
        $learner = $context->learnerUserId;

        if ($scope === null || $learner === null || $learner < 1 || !OrgAudience::visibleUnder($context)) {
            return;
        }

        $membership = Query::from('org_memberships', 'm')->select(['m.manager_user_id'])
            ->where('m.organisation_id', '=', $scope->organisationId)
            ->where('m.user_id', '=', $learner)
            ->where('m.state', '=', 'active')
            ->whereNotNull('m.manager_user_id');
        $scope->constrainUnit($membership, 'm.org_unit_id');
        $row = $context->database->selectOne($membership);

        if ($row === null) {
            return;
        }

        $managerId = (int) $row['manager_user_id'];

        if ($managerId < 1 || $managerId === $learner) {
            return;
        }

        // The second guard. A manager whose own membership in this
        // organisation is no longer active is not a recipient of it, whatever
        // the learner's row still says.
        $active = $context->database->selectOne(
            Query::from('org_memberships')->select(['id'])
                ->where('organisation_id', '=', $scope->organisationId)
                ->where('user_id', '=', $managerId)
                ->where('state', '=', 'active')
        );

        if ($active === null) {
            return;
        }

        yield $managerId;
    }
}
