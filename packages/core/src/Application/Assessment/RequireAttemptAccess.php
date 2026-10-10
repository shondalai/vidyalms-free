<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Organisation\RequireCurrentOrganisationAccess;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Checks current access as well as ownership of a historical attempt. */
final class RequireAttemptAccess
{
    public static function check(DatabasePort $database, ?ClockPort $clock, Attempt $attempt, int $userId, int $courseId): void
    {
        $enrolment = $database->selectOne(Query::from('enrolments')
            ->where('id', '=', $attempt->enrolmentId)->where('user_id', '=', $userId)
            ->where('course_id', '=', $courseId));
        if (!$attempt->isOwnedBy($userId) || $enrolment === null
            || !in_array($enrolment['status'], ['active', 'completed'], true)) {
            throw new NotAuthorizedException('Current course access is required for this attempt.');
        }
        if ($clock !== null) {
            if (is_string($enrolment['expires_at'] ?? null) && $enrolment['expires_at'] <= $clock->now()->format('Y-m-d H:i:s')) {
                throw new NotAuthorizedException('Course access has expired.');
            }
            RequireCurrentOrganisationAccess::check($database, $clock, $userId, $courseId);
        }
    }
}
