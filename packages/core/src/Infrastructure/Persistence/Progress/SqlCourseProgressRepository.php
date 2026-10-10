<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress;

use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgressRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SqlCourseProgressRepository implements CourseProgressRepository
{
    private const TABLE = 'course_progress';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function findByEnrolment(int $enrolmentId): ?CourseProgress
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('enrolment_id', '=', $enrolmentId),
        );

        if ($row === null) {
            return null;
        }

        return new CourseProgress(
            (int) $row['enrolment_id'],
            (int) $row['lessons_total'],
            (int) $row['lessons_completed'],
            (float) $row['percent'],
            $row['quiz_average'] === null ? null : (float) $row['quiz_average'],
            $row['final_quiz_passed'] === null ? null : (bool) $row['final_quiz_passed'],
            new \DateTimeImmutable((string) $row['computed_at'], new \DateTimeZone('UTC')),
        );
    }

    public function save(CourseProgress $progress): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            self::TABLE,
            [
                'enrolment_id' => $progress->enrolmentId,
                'lessons_total' => $progress->lessonsTotal,
                'lessons_completed' => $progress->lessonsCompleted,
                'percent' => round($progress->percent, 2),
                'quiz_average' => $progress->quizAverage === null ? null : round($progress->quizAverage, 2),
                'final_quiz_passed' => $progress->finalQuizPassed === null ? null : (int) $progress->finalQuizPassed,
                'computed_at' => $progress->computedAt->format(self::DATETIME_FORMAT),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['enrolment_id'],
            [
                'lessons_total',
                'lessons_completed',
                'percent',
                'quiz_average',
                'final_quiz_passed',
                'computed_at',
                'updated_at',
            ],
        );
    }
}
