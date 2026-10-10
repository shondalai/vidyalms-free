<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress;

use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

final class SqlLessonProgressRepository implements LessonProgressRepository
{
    private const TABLE = 'lesson_progress';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const LESSON_STATE_PUBLISHED = 'published';
    private const MODULE_STATE_PUBLISHED = 'published';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function findByEnrolmentAndLesson(int $enrolmentId, int $lessonId): ?LessonProgress
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('enrolment_id', '=', $enrolmentId)
                ->where('lesson_id', '=', $lessonId),
        );

        return $row === null ? null : $this->map($row);
    }

    /**
     * @param ?VisibilityScope $scope how far the caller may see. Trailing and
     *                                defaulted, so the shipped two-argument
     *                                call keeps working, and narrowed the same
     *                                way the activity log reader narrows: a
     *                                scope kind this cannot honour is refused
     *                                by narrowing to nothing rather than
     *                                served site-wide. The cursor carries no
     *                                authority and never has, which is exactly
     *                                why the scope has to be an argument: a
     *                                cursor the caller composed decides which
     *                                enrolment a page starts at, and with no
     *                                scope beside it that is the whole table.
     *
     * @return array{rows: list<LessonProgress>, next: ?KeysetCursor}
     */
    public function pageByKeyset(?KeysetCursor $cursor, int $limit, ?VisibilityScope $scope = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page holds at least one row.');
        }

        $query = Query::from(self::TABLE)->orderBy('enrolment_id')->orderBy('id')->limit($limit + 1);
        $query = self::narrow($query, $scope);

        if ($cursor !== null) {
            // The lexicographic "after this key" predicate, spelled out
            // because the query grammar has no row-value comparison: either a
            // strictly later enrolment, or the same enrolment and a strictly
            // later id. Comparing on enrolment_id alone would skip every row
            // after the first of a busy enrolment.
            $enrolmentId = (int) $cursor->part(0);
            $id = (int) $cursor->part(1);

            $query = $query->whereAnyOf(
                Predicate::compare('enrolment_id', '>', $enrolmentId),
                Predicate::allOf(
                    Predicate::compare('enrolment_id', '=', $enrolmentId),
                    Predicate::compare('id', '>', $id),
                ),
            );
        }

        $rows = $this->database->selectAll($query);
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);

        $last = $rows === [] ? null : $rows[\count($rows) - 1];

        return [
            'rows' => array_map($this->map(...), $rows),
            'next' => $hasMore && $last !== null
                ? KeysetCursor::of([(int) $last['enrolment_id'], (int) $last['id']])
                : null,
        ];
    }

    /**
     * The same refuse-unknown narrowing the activity log reader has.
     *
     * A scope kind this reader does not understand narrows to `id = 0` rather
     * than being ignored, which is the fail-closed direction: a reader that
     * widens on a scope it cannot honour is a reader that serves everything
     * the first time somebody hands it an organisation scope.
     */
    private static function narrow(Query $query, ?VisibilityScope $scope): Query
    {
        if ($scope === null || $scope->isSiteWide()) {
            return $query;
        }

        return match ($scope->type) {
            VisibilityScope::TYPE_USER => $query->whereIn('user_id', $scope->ids()),
            default => $query->where('id', '=', 0),
        };
    }

    public function listByEnrolment(int $enrolmentId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('enrolment_id', '=', $enrolmentId)
                ->orderBy('lesson_id'),
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * The numerator of the completion percentage, and it has to be counted
     * over exactly the lessons LessonAvailability counts as the denominator:
     * published, and inside a published module. Counting a completed lesson
     * whose module is not published against a total that excludes it produced
     * percentages above a hundred.
     */
    public function countCompletedPublishedLessons(int $enrolmentId): int
    {
        return $this->database->count(
            Query::from(self::TABLE, 'lp')
                ->join('lessons', 'l', 'lp.lesson_id', 'l.id')
                ->join('modules', 'm', 'l.module_id', 'm.id')
                ->where('lp.enrolment_id', '=', $enrolmentId)
                ->where('lp.status', '=', LessonProgress::STATUS_COMPLETED)
                ->where('l.state', '=', self::LESSON_STATE_PUBLISHED)
                ->where('m.state', '=', self::MODULE_STATE_PUBLISHED),
        );
    }

    public function countCompletedIn(int $enrolmentId, array $lessonIds): int
    {
        if ($lessonIds === []) {
            return 0;
        }

        // No join: the id set the caller passes is already the answer to
        // "which lessons count", and joining the structural tables again
        // would let this side of the fraction disagree with the side that
        // produced the ids.
        return $this->database->count(
            Query::from(self::TABLE)
                ->where('enrolment_id', '=', $enrolmentId)
                ->where('status', '=', LessonProgress::STATUS_COMPLETED)
                ->whereIn('lesson_id', $lessonIds),
        );
    }

    public function save(LessonProgress $progress): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            self::TABLE,
            [
                'enrolment_id' => $progress->enrolmentId,
                'lesson_id' => $progress->lessonId,
                'user_id' => $progress->userId,
                'status' => $progress->status(),
                'progress_percent' => round($progress->progressPercent(), 2),
                'seconds_spent' => $progress->secondsSpent(),
                'position_seconds' => $progress->positionSeconds(),
                'first_accessed_at' => $this->formatNullable($progress->firstAccessedAt()),
                'completed_at' => $this->formatNullable($progress->completedAt()),
                'last_heartbeat_at' => $this->formatNullable($progress->lastHeartbeatAt()),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['enrolment_id', 'lesson_id'],
            [
                'status',
                'progress_percent',
                'seconds_spent',
                'position_seconds',
                'first_accessed_at',
                'completed_at',
                'last_heartbeat_at',
                'updated_at',
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LessonProgress
    {
        return LessonProgress::restore(
            (int) $row['enrolment_id'],
            (int) $row['lesson_id'],
            (int) $row['user_id'],
            (string) $row['status'],
            (float) $row['progress_percent'],
            (int) $row['seconds_spent'],
            $row['position_seconds'] === null ? null : (int) $row['position_seconds'],
            $this->parseNullable($row['first_accessed_at']),
            $this->parseNullable($row['completed_at']),
            $this->parseNullable($row['last_heartbeat_at']),
        );
    }

    private function formatNullable(?\DateTimeImmutable $value): ?string
    {
        return $value?->format(self::DATETIME_FORMAT);
    }

    private function parseNullable(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
