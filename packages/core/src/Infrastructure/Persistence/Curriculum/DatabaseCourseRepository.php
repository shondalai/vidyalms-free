<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseCounts;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CoursePage;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRecord;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class DatabaseCourseRepository implements CourseRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function byId(int $id): ?Course
    {
        $row = $this->database->selectOne(Query::from('courses')->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function bySlug(string $slug): ?Course
    {
        $row = $this->database->selectOne(Query::from('courses')->where('slug', '=', $slug));

        return $row === null ? null : $this->map($row);
    }

    public function slugExists(string $slug, ?int $excludeCourseId = null): bool
    {
        $query = Query::from('courses')->where('slug', '=', $slug);

        if ($excludeCourseId !== null) {
            $query->where('id', '!=', $excludeCourseId);
        }

        return $this->database->count($query) > 0;
    }

    public function add(Course $course): int
    {
        $now = $this->now();

        return $this->database->insert('courses', $this->toRow($course) + [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Course $course): void
    {
        $this->database->update(
            'courses',
            $this->toRow($course) + ['updated_at' => $this->now()],
            ['id' => $course->id],
        );
    }

    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('courses')->select(['id'])->whereIn('id', $ids),
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** @param list<int>|null $visibleCategoryIds */
    public function catalogue(?string $titleContains, ?int $categoryId, int $page, int $perPage, ?array $visibleCategoryIds = null): CoursePage
    {
        $query = Query::from('courses')
            ->where('state', '=', CourseState::Published->value)
            ->where('visibility', '=', CourseVisibility::Public->value);

        if ($titleContains !== null) {
            $query->whereContains('title', $titleContains);
        }

        if ($categoryId !== null) {
            $query->where('category_id', '=', $categoryId);
        }

        if ($visibleCategoryIds !== null) {
            $query->whereAnyOf(Predicate::isNull('category_id'), Predicate::in('category_id', $visibleCategoryIds));
        }

        $total = $this->database->count($query);

        $query->orderBy('title')
            ->orderBy('id')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage);

        $items = array_map($this->map(...), $this->database->selectAll($query));

        return new CoursePage($items, $total, $page, $perPage);
    }

    public function replacePrerequisites(int $courseId, array $prerequisiteCourseIds): void
    {
        $now = $this->now();

        $this->database->transactional(function () use ($courseId, $prerequisiteCourseIds, $now): void {
            $this->database->delete('course_prerequisites', ['course_id' => $courseId]);

            foreach ($prerequisiteCourseIds as $prerequisiteCourseId) {
                $this->database->insert('course_prerequisites', [
                    'course_id' => $courseId,
                    'prerequisite_course_id' => $prerequisiteCourseId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function prerequisiteIds(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('course_prerequisites')
                ->select(['prerequisite_course_id'])
                ->where('course_id', '=', $courseId)
                ->orderBy('prerequisite_course_id'),
        );

        return array_map(static fn (array $row): int => (int) $row['prerequisite_course_id'], $rows);
    }

    public function assignInstructor(int $courseId, int $userId, string $role): void
    {
        $now = $this->now();

        $this->database->insert('course_instructors', [
            'course_id' => $courseId,
            'user_id' => $userId,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function removeInstructor(int $courseId, int $userId): bool
    {
        return $this->database->delete('course_instructors', [
            'course_id' => $courseId,
            'user_id' => $userId,
        ]) > 0;
    }

    public function isInstructor(int $courseId, int $userId): bool
    {
        return $this->database->count(
            Query::from('course_instructors')
                ->where('course_id', '=', $courseId)
                ->where('user_id', '=', $userId),
        ) > 0;
    }

    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->database->selectAll(Query::from('courses')->whereIn('id', $ids));
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $this->map($row);
        }

        $courses = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $courses[] = $byId[$id];
            }
        }

        return $courses;
    }

    public function records(
        ?array $restrictToIds,
        ?string $state,
        ?string $titleContains,
        ?string $sortColumn,
        string $direction,
        ?int $limit,
        int $offset,
        ?int $categoryId = null,
    ): array {
        if ($restrictToIds !== null && $restrictToIds === []) {
            return [];
        }

        $query = $this->recordQuery($restrictToIds, $state, $titleContains, $categoryId);

        if ($sortColumn !== null) {
            $query->orderBy($sortColumn, $direction);
        }

        $query->orderBy('id', $direction)->limit($limit)->offset($offset);

        return array_map(
            fn (array $row): CourseRecord => new CourseRecord(
                $this->map($row),
                $this->timestamp($row['created_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null),
            ),
            $this->database->selectAll($query),
        );
    }

    public function countRecords(?array $restrictToIds, ?string $state, ?string $titleContains, ?int $categoryId = null): int
    {
        if ($restrictToIds !== null && $restrictToIds === []) {
            return 0;
        }

        return $this->database->count($this->recordQuery($restrictToIds, $state, $titleContains, $categoryId));
    }

    public function idsByCount(
        ?array $restrictToIds,
        ?string $state,
        ?string $titleContains,
        string $countSort,
        string $direction,
        int $limit,
        int $offset,
        ?int $categoryId = null,
    ): array {
        if ($restrictToIds !== null && $restrictToIds === []) {
            return [];
        }

        $query = $this->aliasedRecordQuery($restrictToIds, $state, $titleContains, $categoryId)
            ->select(['c.id'])
            ->leftJoin('enrolments', 'e', 'c.id', 'e.course_id')
            ->aggregate($this->countAggregate($countSort))
            ->groupBy('c.id')
            ->orderBy($countSort, $direction)
            ->orderBy('c.id', $direction)
            ->limit($limit)
            ->offset($offset);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->selectAll($query),
        );
    }

    public function recordsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];

        foreach ($this->database->selectAll(Query::from('courses')->whereIn('id', $ids)) as $row) {
            $byId[(int) $row['id']] = new CourseRecord(
                $this->map($row),
                $this->timestamp($row['created_at'] ?? null),
                $this->timestamp($row['updated_at'] ?? null),
            );
        }

        $records = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $records[] = $byId[$id];
            }
        }

        return $records;
    }

    public function countsFor(array $courseIds): array
    {
        $counts = [];

        foreach ($courseIds as $courseId) {
            $counts[$courseId] = [
                'lessons' => 0,
                'archived_lessons' => 0,
                'enrolled' => 0,
                'active' => 0,
                'completed' => 0,
                'suspended' => 0,
            ];
        }

        if ($courseIds === []) {
            return [];
        }

        // Two grouped queries for a whole page, never one query per course
        // and never a row per enrolment: a course with ten thousand learners
        // costs the same as one with ten.
        foreach (
            $this->database->selectAll(
                Query::from('lessons')
                    ->select(['course_id'])
                    ->aggregate(
                        // Draft lessons count, archived ones do not: a draft
                        // is work in progress on a course that still contains
                        // it, an archived lesson has left it. Counting rows
                        // the product says are gone is how an author archives
                        // a whole module and sees the only number the course
                        // list shows stay exactly where it was.
                        Aggregate::countWhere(
                            [Predicate::compare('state', '!=', Lesson::STATE_ARCHIVED)],
                            'lesson_count',
                        ),
                        Aggregate::countWhere(
                            [Predicate::compare('state', '=', Lesson::STATE_ARCHIVED)],
                            'archived_lesson_count',
                        ),
                    )
                    ->whereIn('course_id', $courseIds)
                    ->groupBy('course_id'),
            ) as $row
        ) {
            $courseId = (int) $row['course_id'];

            if (isset($counts[$courseId])) {
                $counts[$courseId]['lessons'] = (int) $row['lesson_count'];
                $counts[$courseId]['archived_lessons'] = (int) $row['archived_lesson_count'];
            }
        }

        foreach (
            $this->database->selectAll(
                Query::from('enrolments')
                    ->select(['course_id'])
                    ->aggregate(
                        Aggregate::countWhere(
                            [Predicate::compare('status', '!=', Enrolment::STATUS_CANCELLED)],
                            'enrolled',
                        ),
                        Aggregate::countWhere([Predicate::compare('status', '=', Enrolment::STATUS_ACTIVE)], 'active'),
                        Aggregate::countWhere(
                            [Predicate::compare('status', '=', Enrolment::STATUS_COMPLETED)],
                            'completed',
                        ),
                        Aggregate::countWhere(
                            [Predicate::compare('status', '=', Enrolment::STATUS_SUSPENDED)],
                            'suspended',
                        ),
                    )
                    ->whereIn('course_id', $courseIds)
                    ->groupBy('course_id'),
            ) as $row
        ) {
            $courseId = (int) $row['course_id'];

            if (!isset($counts[$courseId])) {
                continue;
            }

            $counts[$courseId]['enrolled'] = (int) $row['enrolled'];
            $counts[$courseId]['active'] = (int) $row['active'];
            $counts[$courseId]['completed'] = (int) $row['completed'];
            $counts[$courseId]['suspended'] = (int) $row['suspended'];
        }

        return array_map(
            static fn (array $row): CourseCounts => new CourseCounts(
                $row['lessons'],
                $row['archived_lessons'],
                $row['enrolled'],
                $row['active'],
                $row['completed'],
                $row['suspended'],
            ),
            $counts,
        );
    }

    public function instructorsFor(array $courseIds): array
    {
        $instructors = [];

        foreach ($courseIds as $courseId) {
            $instructors[$courseId] = [];
        }

        if ($courseIds === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('course_instructors')
                ->select(['course_id', 'user_id', 'role'])
                ->whereIn('course_id', $courseIds)
                ->orderBy('id'),
        );

        foreach ($rows as $row) {
            $courseId = (int) $row['course_id'];

            if (!isset($instructors[$courseId])) {
                continue;
            }

            $instructors[$courseId][] = [
                'user_id' => (int) $row['user_id'],
                'role' => (string) $row['role'],
            ];
        }

        foreach ($instructors as $courseId => $rows) {
            usort($rows, static function (array $left, array $right): int {
                $leadFirst = ($right['role'] === 'lead' ? 1 : 0) <=> ($left['role'] === 'lead' ? 1 : 0);

                return $leadFirst !== 0 ? $leadFirst : $left['user_id'] <=> $right['user_id'];
            });

            $instructors[$courseId] = $rows;
        }

        return $instructors;
    }

    public function courseIdsForInstructor(int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from('course_instructors')
                ->select(['course_id'])
                ->where('user_id', '=', $userId)
                ->orderBy('course_id'),
        );

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['course_id'],
            $rows,
        )));
    }

    /**
     * @param list<int>|null $restrictToIds
     */
    private function recordQuery(?array $restrictToIds, ?string $state, ?string $titleContains, ?int $categoryId = null): Query
    {
        return $this->narrow(Query::from('courses'), '', $restrictToIds, $state, $titleContains, $categoryId);
    }

    /**
     * The same filter over an aliased courses table, which a join needs.
     *
     * @param list<int>|null $restrictToIds
     */
    private function aliasedRecordQuery(?array $restrictToIds, ?string $state, ?string $titleContains, ?int $categoryId = null): Query
    {
        return $this->narrow(Query::from('courses', 'c'), 'c.', $restrictToIds, $state, $titleContains, $categoryId);
    }

    /**
     * @param list<int>|null $restrictToIds
     */
    private function narrow(
        Query $query,
        string $prefix,
        ?array $restrictToIds,
        ?string $state,
        ?string $titleContains,
        ?int $categoryId = null,
    ): Query {
        if ($restrictToIds !== null) {
            $query->whereIn($prefix . 'id', $restrictToIds);
        }

        if ($state !== CourseState::Trashed->value) {
            $query->where($prefix . 'state', '!=', CourseState::Trashed->value);
        }

        if ($state !== null) {
            $query->where($prefix . 'state', '=', $state);
        }

        if ($categoryId !== null) {
            $query->where($prefix . 'category_id', '=', $categoryId);
        }

        if ($titleContains !== null && trim($titleContains) !== '') {
            $query->whereContains($prefix . 'title', $titleContains);
        }

        return $query;
    }

    /**
     * The aggregate behind each sortable read count. A cancelled enrolment
     * is not an enrolment, so every one of them excludes it, and a course
     * nobody is enrolled in has no completion rate at all: the rate is null
     * rather than zero, which is what CourseCounts::completionRate() says
     * and what the list has always shown.
     */
    private function countAggregate(string $countSort): Aggregate
    {
        $notCancelled = Predicate::compare('e.status', '!=', Enrolment::STATUS_CANCELLED);

        return match ($countSort) {
            'enrolled_count' => Aggregate::countWhere([$notCancelled], 'enrolled_count'),
            'active_count' => Aggregate::countWhere(
                [Predicate::compare('e.status', '=', Enrolment::STATUS_ACTIVE)],
                'active_count',
            ),
            'completion_rate' => Aggregate::rate(
                [$notCancelled],
                [Predicate::compare('e.status', '=', Enrolment::STATUS_COMPLETED)],
                'completion_rate',
            ),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a sortable course count.', $countSort)),
        };
    }

    private function timestamp(mixed $value): \DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Course
    {
        return new Course(
            id: (int) $row['id'],
            title: (string) $row['title'],
            slug: Slug::fromString((string) $row['slug']),
            summary: $row['summary'] === null ? null : (string) $row['summary'],
            description: $row['description'] === null ? null : (string) $row['description'],
            image: $row['image'] === null ? null : (string) $row['image'],
            categoryId: $row['category_id'] === null ? null : (int) $row['category_id'],
            level: $row['level'] === null ? null : (string) $row['level'],
            language: $row['language'] === null ? null : (string) $row['language'],
            visibility: CourseVisibility::from((string) $row['visibility']),
            state: CourseState::from((string) $row['state']),
            priceMode: (string) $row['price_mode'],
            completionRule: CompletionRule::from((string) $row['completion_rule']),
            completionThreshold: $row['completion_threshold'] === null ? null : (int) $row['completion_threshold'],
            featured: (int) $row['featured'] === 1,
            createdBy: (int) $row['created_by'],
            publishedAt: $row['published_at'] === null
                ? null
                : new \DateTimeImmutable((string) $row['published_at'], new \DateTimeZone('UTC')),
            enrolmentRule: \is_string($row['enrolment_rule'] ?? null) && $row['enrolment_rule'] !== ''
                ? (string) $row['enrolment_rule']
                : 'open',
            progression: \is_string($row['progression'] ?? null) && $row['progression'] !== ''
                ? (string) $row['progression']
                : 'free',
            timezone: $row['timezone'] === null ? null : (string) $row['timezone'],
            startsAt: $row['starts_at'] === null
                ? null
                : new \DateTimeImmutable((string) $row['starts_at'], new \DateTimeZone('UTC')),
            latePolicyKey: \is_string($row['late_policy_key'] ?? null) && $row['late_policy_key'] !== ''
                ? (string) $row['late_policy_key']
                : 'none',
            latePolicyParams: $this->latePolicyParams($row['late_policy_params'] ?? null),
            latePolicyParamsVersion: $this->latePolicyParamsVersion($row),
            commerceSource: \is_string($row['commerce_source'] ?? null) && $row['commerce_source'] !== ''
                ? (string) $row['commerce_source']
                : 'cms-shop',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Course $course): array
    {
        return [
            'title' => $course->title,
            'slug' => $course->slug->value,
            'summary' => $course->summary,
            'description' => $course->description,
            'image' => $course->image,
            'category_id' => $course->categoryId,
            'level' => $course->level,
            'language' => $course->language,
            'visibility' => $course->visibility->value,
            'state' => $course->state->value,
            'price_mode' => $course->priceMode,
            'completion_rule' => $course->completionRule->value,
            'completion_threshold' => $course->completionThreshold,
            'featured' => $course->featured ? 1 : 0,
            'created_by' => $course->createdBy,
            'published_at' => $course->publishedAt?->format('Y-m-d H:i:s'),
            'enrolment_rule' => $course->enrolmentRule,
            'progression' => $course->progression,
            'timezone' => $course->timezone,
            'starts_at' => $course->startsAt?->format('Y-m-d H:i:s'),
            'late_policy_key' => $course->latePolicyKey,
            'late_policy_params' => $course->latePolicyParams === null
                ? null
                : json_encode(
                    StoredShape::wrap($course->latePolicyParams, $course->latePolicyParamsVersion),
                    JSON_THROW_ON_ERROR,
                ),
            'late_policy_params_version' => $course->latePolicyParamsVersion,
            'commerce_source' => $course->commerceSource,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latePolicyParams(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return StoredShape::bodyOf($decoded);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function latePolicyParamsVersion(array $row): int
    {
        $raw = $row['late_policy_params'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $bodyVersion = \is_array($decoded) ? StoredShape::versionOf($decoded) : 0;

            if ($bodyVersion > 0) {
                return $bodyVersion;
            }
        }

        $value = $row['late_policy_params_version'] ?? null;

        return \is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
