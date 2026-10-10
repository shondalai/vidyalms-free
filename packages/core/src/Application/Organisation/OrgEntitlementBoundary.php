<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Current relationship authority is independent of delayed cleanup and the enrolment's historical status. */
final class OrgEntitlementBoundary
{
    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function retain(DatabasePort $database, array $rows, string $now, bool $preserveInactive = false): array
    {
        $orgIds = [];
        foreach ($rows as $row) {
            if (($row['organisation_id'] ?? null) !== null) {
                $orgIds[] = (int) $row['id'];
            }
        }
        $allowed = [];
        foreach (array_chunk($orgIds, 100) as $ids) {
            foreach ($database->selectAll(self::query($now)->select(['e.id'])->whereIn('e.id', $ids)) as $row) {
                $allowed[(int) $row['id']] = true;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            if (($row['organisation_id'] ?? null) === null || isset($allowed[(int) $row['id']])) {
                $result[] = $row;
            } elseif ($preserveInactive) {
                // Availability rules need to distinguish lapsed/withdrawn
                // history from never enrolled; do not erase the fact.
                $row['status'] = 'revoked';
                $result[] = $row;
            }
        }

        return $result;
    }

    public static function query(string $now): Query
    {
        return Query::from('entitlements', 'e')->select(['e.*'])
            ->leftJoin('organisations', 'o', 'e.organisation_id', 'o.id')
            ->leftJoin(
                'org_memberships',
                'm',
                'e.org_membership_id',
                'm.id',
                [['e.organisation_id', 'm.organisation_id'], ['e.user_id', 'm.user_id']]
            )
            ->leftJoin('org_course_releases', 'r', 'e.organisation_id', 'r.organisation_id', [['e.course_id', 'r.course_id']])
            ->whereAnyOf(Predicate::isNull('e.organisation_id'), Predicate::allOf(
                Predicate::compare('o.state', '=', 'active'),
                Predicate::compare('m.state', '=', 'active'),
                Predicate::compare('r.state', '=', 'active')
            ))
            ->whereAnyOf(
                Predicate::isNull('e.organisation_id'),
                Predicate::isNull('r.starts_at'),
                Predicate::compare('r.starts_at', '<=', $now)
            )
            ->whereAnyOf(
                Predicate::isNull('e.organisation_id'),
                Predicate::isNull('r.ends_at'),
                Predicate::compare('r.ends_at', '>', $now)
            );
    }
}
