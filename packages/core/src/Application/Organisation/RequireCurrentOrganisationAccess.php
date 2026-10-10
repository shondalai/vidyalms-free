<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Always called by learner mutations; historical completion is not current organisation access. */
final class RequireCurrentOrganisationAccess
{
    public static function check(DatabasePort $database, ClockPort $clock, int $userId, int $courseId): void
    {
        // Source refs are persisted by the sealed org grant writer. The cheap
        // marker lookup is compatible with core-only historical installations.
        $org = $database->selectOne(Query::from('entitlements')->select(['id', 'organisation_id'])
            ->where('user_id', '=', $userId)->where('course_id', '=', $courseId)->whereNotNull('organisation_id')->limit(1));
        if ($org === null) {
            return;
        }
        $now = $clock->now()->format('Y-m-d H:i:s');
        $effective = $database->selectOne(OrgEntitlementBoundary::query($now)->select(['e.id'])
            ->where('e.user_id', '=', $userId)->where('e.course_id', '=', $courseId)->where('e.status', '=', 'active')
            ->whereAnyOf(Predicate::isNull('e.starts_at'), Predicate::compare('e.starts_at', '<=', $now))
            ->whereAnyOf(Predicate::isNull('e.expires_at'), Predicate::compare('e.expires_at', '>', $now))->limit(1));
        if ($effective === null) {
            throw new ConflictException(
                'Organisation course access has ended. Your previous progress is preserved.',
                ['reason' => 'organisation.access_ended']
            );
        }
    }
}
