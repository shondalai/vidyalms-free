<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Aggregate surfaces must not reveal results hidden on the quiz itself. */
final class LearnerResultVisibility
{
    public static function hidesCourseResults(DatabasePort $database, int $courseId): bool
    {
        foreach (
            $database->selectAll(Query::from('quizzes')->select(['show_results', 'review_visibility'])
            ->where('course_id', '=', $courseId)) as $quiz
        ) {
            if ($quiz['show_results'] === 'never') {
                return true;
            }
            // A summary has no attempt/phase context. Custom review restrictions
            // are disclosed only through the attempt's own review endpoint.
            $review = $quiz['review_visibility'];
            $review = is_string($review) ? json_decode($review, true) : $review;
            if (!empty($review)) {
                return true;
            }
        }
        return false;
    }
}
