<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/** A site-owner release is the maximum catalogue a delegated org may grant. */
final class CourseReleasePolicy
{
    public function __construct(private readonly DatabasePort $database, private readonly ClockPort $clock)
    {
    }

    /**
     * The same question answered instead of thrown, for a listing path.
     *
     * A catalogue screen asks it once per course to decide what to show, and a
     * screen that decided by catching an exception per row would be paying for
     * a stack trace to render a list. It is the same call, so the two answers
     * cannot drift.
     */
    public function allows(OrgScope $scope, int $courseId): bool
    {
        try {
            $this->require($scope, $courseId);

            return true;
        } catch (ConflictException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function require(OrgScope $scope, int $courseId): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $org = $this->database->selectOne(Query::from('organisations')->where('id', '=', $scope->organisationId));
        $row = $this->database->selectOne(Query::from('org_course_releases')
            ->where('organisation_id', '=', $scope->organisationId)->where('course_id', '=', $courseId));
        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));
        if (
            $org === null || $org['state'] !== 'active' || $row === null || $row['state'] !== 'active'
            || ($row['starts_at'] !== null && (string) $row['starts_at'] > $now)
            || ($row['ends_at'] !== null && (string) $row['ends_at'] <= $now)
            || $course === null || $course['state'] !== 'published'
        ) {
            throw new ConflictException('This course is not currently released to this organisation.');
        }

        return $row;
    }
}
