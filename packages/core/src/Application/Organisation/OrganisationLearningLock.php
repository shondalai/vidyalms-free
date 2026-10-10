<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Global only because the underlying learning record is unique per person/course. Never a reporting surface. */
final class OrganisationLearningLock
{
    public static function claim(DatabasePort $database, int $userId, int $courseId, string $now): void
    {
        $query = Query::from('org_learning_locks')->where('user_id', '=', $userId)->where('course_id', '=', $courseId);
        $row = $database->selectOne(clone $query);
        if ($row === null) {
            // A no-op upsert also waits for a competing first writer. A
            // repeatable-read snapshot may still hide it; then fail for retry.
            $database->upsert('org_learning_locks', ['user_id' => $userId, 'course_id' => $courseId, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now], ['user_id', 'course_id'], []);
            $row = $database->selectOne($query);
        }
        if (
            $row === null || $database->update('org_learning_locks', ['revision' => (int) $row['revision'] + 1,
            'updated_at' => $now], ['id' => (int) $row['id'], 'revision' => (int) $row['revision']]) !== 1
        ) {
            throw new ConflictException('This learner’s access changed concurrently. Reload and retry.');
        }
    }
}
