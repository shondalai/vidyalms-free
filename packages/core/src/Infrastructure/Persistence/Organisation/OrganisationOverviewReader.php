<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The six numbers an organisation's front page is: how many people, how many
 * courses reached them, how much learning that produced, and where the seats
 * stand.
 *
 * It exists because the same answer is now asked for twice and must not be
 * computed twice. `GET /manage/organisations/{id}/summary` is a shipped route on
 * the published contract and in the generated SDK, and amendment C7 rules that
 * it stays permanently as an alias while the report itself is re-homed as the
 * registered `org-overview` definition. Two copies of these queries would be two
 * answers to one question, and the one that drifts is always the one nobody is
 * looking at.
 *
 * **Every count is bounded by the entitlement and by the unit reach**, which is
 * the isolation rule this product states once and enforces everywhere: an
 * organisation report may touch only rows whose entitlement carries that
 * organisation's `organisation_id`, and a unit-scoped reader sees their subtree.
 * The enrolment count is therefore a count of distinct enrolments **reachable
 * through this organisation's entitlements**, never a count of enrolments on
 * courses the organisation happens to have released: a learner who bought the
 * same course themselves is not this organisation's learner on it.
 */
final class OrganisationOverviewReader
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(OrgScope $scope): array
    {
        $orgId = $scope->organisationId;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $learningQuery = Query::from('entitlements', 'e')->select([])
            ->aggregate(Aggregate::countDistinctOf('n.id', 'enrolments'))
            ->join('enrolments', 'n', 'e.user_id', 'n.user_id', [['e.course_id', 'n.course_id']])
            ->join('org_memberships', 'm', 'e.org_membership_id', 'm.id', [['e.organisation_id', 'm.organisation_id']])
            ->where('e.organisation_id', '=', $orgId);
        $scope->constrainUnit($learningQuery, 'm.org_unit_id');
        $learning = $this->database->selectOne($learningQuery);

        $members = Query::from('org_memberships', 'm')
            ->where('m.organisation_id', '=', $orgId)
            ->where('m.state', '=', 'active');
        $scope->constrainUnit($members, 'm.org_unit_id');

        $pending = Query::from('seat_assignments', 'a')
            ->join('org_memberships', 'm', 'a.membership_id', 'm.id', [['a.organisation_id', 'm.organisation_id']])
            ->where('a.organisation_id', '=', $orgId)
            ->where('a.state', '=', 'pending');
        $scope->constrainUnit($pending, 'm.org_unit_id');

        return [
            'organisation_id' => $orgId,
            'active_members' => $this->database->count($members),
            'released_courses' => $this->database->count(
                Query::from('org_course_releases')
                    ->where('organisation_id', '=', $orgId)
                    ->where('state', '=', 'active')
                    ->whereAnyOf(
                        Predicate::isNull('starts_at'),
                        Predicate::compare('starts_at', '<=', $now),
                    )
                    ->whereAnyOf(
                        Predicate::isNull('ends_at'),
                        Predicate::compare('ends_at', '>', $now),
                    ),
            ),
            'enrolments' => (int) ($learning['enrolments'] ?? 0),
            'seat_pools' => $this->database->count(Query::from('seat_pools')->where('organisation_id', '=', $orgId)),
            'pending_seats' => $this->database->count($pending),
            'unit_id' => $scope->unitId,
        ];
    }

    /**
     * Which of the facts count people, so the pipeline knows what small-cell
     * suppression acts on.
     *
     * A count of released courses and a count of seat pools are counts of
     * things, and hiding them would be suppression pretending to be
     * confidentiality. A count of active members and a count of pending seats
     * are counts of people.
     *
     * @return list<string>
     */
    public static function countFactKeys(): array
    {
        return ['active_members', 'enrolments', 'pending_seats'];
    }
}
