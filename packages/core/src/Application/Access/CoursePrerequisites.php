<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class CoursePrerequisites
{
    public static function completed(DatabasePort $database, int $userId, int $courseId): bool
    {
        $required = array_map(
            static fn (array $row): int => (int) $row['prerequisite_course_id'],
            $database->selectAll(Query::from('course_prerequisites')->select(['prerequisite_course_id'])
            ->where('course_id', '=', $courseId))
        );
        if ($required === []) {
            return true;
        }
        return $database->count(Query::from('enrolments')->where('user_id', '=', $userId)
            ->whereIn('course_id', $required)->where('status', '=', 'completed')) === count($required);
    }
}
