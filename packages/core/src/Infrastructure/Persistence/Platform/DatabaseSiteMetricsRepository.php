<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Platform\SiteMetricsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;

final class DatabaseSiteMetricsRepository implements SiteMetricsRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    public function courseCountsByState(): array
    {
        $counts = [];

        foreach (CourseState::cases() as $state) {
            $counts[$state->value] = $this->database->count(
                Query::from('courses')->where('state', '=', $state->value),
            );
        }

        return $counts;
    }

    public function enrolmentCountsByStatus(): array
    {
        $counts = [];

        foreach (Enrolment::STATUSES as $status) {
            $counts[$status] = $this->database->count(
                Query::from('enrolments')->where('status', '=', $status),
            );
        }

        return $counts;
    }

    public function completionsSince(\DateTimeImmutable $from): int
    {
        return $this->database->count(
            Query::from('enrolments')
                ->where('status', '=', Enrolment::STATUS_COMPLETED)
                ->where('completed_at', '>=', $from->format(self::DATETIME_FORMAT)),
        );
    }

    public function expiringEntitlements(\DateTimeImmutable $from, \DateTimeImmutable $until): int
    {
        return $this->database->count(
            Query::from('entitlements', 't')
                ->select(['t.id'])
                ->join('enrolments', 'e', 'e.entitlement_id', 't.id')
                ->where('t.status', '=', Entitlement::STATUS_ACTIVE)
                ->where('e.status', '=', Enrolment::STATUS_ACTIVE)
                ->whereNotNull('t.expires_at')
                ->where('t.expires_at', '>', $from->format(self::DATETIME_FORMAT))
                ->where('t.expires_at', '<=', $until->format(self::DATETIME_FORMAT)),
        );
    }

    public function lessonCountsByType(): array
    {
        // GROUP BY names the distinct types in one query; the per-type count
        // is one more each, and a site has a handful of types, not a handful
        // of thousands. The Query port carries no aggregate expression,
        // which is what rules out a single grouped count.
        $types = $this->database->selectAll(
            Query::from('lessons')->select(['type'])->groupBy('type')->orderBy('type'),
        );

        $counts = [];

        foreach ($types as $row) {
            $type = (string) $row['type'];
            $counts[$type] = $this->database->count(Query::from('lessons')->where('type', '=', $type));
        }

        return $counts;
    }

    public function topCoursesByActiveEnrolments(int $limit): array
    {
        $rows = $this->database->selectAll(
            Query::from('enrolments', 'e')
                ->select(['e.course_id'])
                ->aggregate(
                    Aggregate::countWhere([Predicate::compare('e.status', '=', Enrolment::STATUS_ACTIVE)], 'active_count'),
                    Aggregate::countWhere([Predicate::compare('e.status', '=', Enrolment::STATUS_COMPLETED)], 'completed_count'),
                    Aggregate::count('enrolled_count'),
                )
                ->join('courses', 'c', 'e.course_id', 'c.id')
                ->where('e.status', '!=', Enrolment::STATUS_CANCELLED)
                ->where('c.state', '=', CourseState::Published->value)
                ->groupBy('e.course_id')
                ->having('active_count', '>', 0)
                ->orderBy('active_count', Query::SORT_DESC)
                ->orderBy('e.course_id')
                ->limit($limit),
        );
        $top = [];
        foreach ($rows as $row) {
            $courseId = (int) $row['course_id'];
            $top[] = [
                'course_id' => $courseId,
                'active_count' => (int) $row['active_count'],
                'enrolled_count' => (int) $row['enrolled_count'],
                'completed_count' => (int) $row['completed_count'],
                'lesson_count' => $this->database->count(Query::from('lessons')->where('course_id', '=', $courseId)),
            ];
        }
        return $top;
    }

    public function learningActivity(\DateTimeImmutable $now, int $days): array
    {
        if (!\in_array($days, [7, 30], true)) {
            throw new \InvalidArgumentException('The overview supports 7 or 30 days.');
        }
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $start = $now->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $previousStart = $start->modify('-' . $days . ' days');
        $previousEnd = $now->modify('-' . $days . ' days');
        $counts = [];

        // Two range-filtered aggregate reads, regardless of the site's size.
        // Day boundaries are bound values; no timestamp expressions or
        // unbounded enrolment rows need to cross the database port.
        foreach (['enrolments' => 'enrolled_at', 'completions' => 'completed_at'] as $metric => $column) {
            $aggregates = [];
            for ($index = 0; $index < $days * 2; ++$index) {
                $from = $previousStart->modify('+' . $index . ' days');
                $until = $from->modify('+1 day');
                $cutoff = $index < $days ? $previousEnd : $now;
                $aggregates[] = Aggregate::countWhere([
                    Predicate::compare($column, '>=', $from->format(self::DATETIME_FORMAT)),
                    Predicate::compare($column, '<', $until->format(self::DATETIME_FORMAT)),
                    Predicate::compare($column, '<=', $cutoff->format(self::DATETIME_FORMAT)),
                ], 'day_' . $index);
            }
            $query = Query::from('enrolments')->select([])->aggregate(...$aggregates)
                ->where($column, '>=', $previousStart->format(self::DATETIME_FORMAT))
                ->where($column, '<=', $now->format(self::DATETIME_FORMAT));
            $counts[$metric] = $this->database->selectOne($query) ?? [];
        }
        $daily = [];
        for ($index = 0; $index < $days; ++$index) {
            $daily[] = [
                'date' => $start->modify('+' . $index . ' days')->format('Y-m-d'),
                'enrolments' => (int) ($counts['enrolments']['day_' . ($index + $days)] ?? 0),
                'completions' => (int) ($counts['completions']['day_' . ($index + $days)] ?? 0),
                'previous_enrolments' => (int) ($counts['enrolments']['day_' . $index] ?? 0),
                'previous_completions' => (int) ($counts['completions']['day_' . $index] ?? 0),
            ];
        }

        $recent = [];
        foreach (['enrolled' => 'enrolled_at', 'completed' => 'completed_at'] as $kind => $column) {
            $rows = $this->database->selectAll(Query::from('enrolments', 'e')
                ->select(['e.id', 'e.course_id', 'c.title AS course_title', 'e.' . $column . ' AS occurred_at'])
                ->join('courses', 'c', 'e.course_id', 'c.id')
                ->where('e.' . $column, '>=', $start->format(self::DATETIME_FORMAT))
                ->where('e.' . $column, '<=', $now->format(self::DATETIME_FORMAT))
                ->orderBy('e.' . $column, Query::SORT_DESC)->orderBy('e.id', Query::SORT_DESC)->limit(6));
            foreach ($rows as $row) {
                $recent[] = [
                    'kind' => $kind,
                    'course_id' => (int) $row['course_id'],
                    'course_title' => (string) $row['course_title'],
                    'occurred_at' => (string) $row['occurred_at'],
                ];
            }
        }
        usort($recent, static fn (array $a, array $b): int => strcmp($b['occurred_at'], $a['occurred_at']));
        return ['period_days' => $days, 'daily' => $daily, 'recent' => array_slice($recent, 0, 6)];
    }
}
