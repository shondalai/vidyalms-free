<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

interface CourseRepository
{
    /**
     * The read counts a management list may sort on, and the aggregate each
     * one means. Adding one is a repository change, never a caller's string.
     */
    public const COUNT_SORTS = ['enrolled_count', 'active_count', 'completion_rate'];

    public function byId(int $id): ?Course;

    public function bySlug(string $slug): ?Course;

    public function slugExists(string $slug, ?int $excludeCourseId = null): bool;

    /**
     * Persists a new course and returns its id; the entity's own id is ignored.
     */
    public function add(Course $course): int;

    public function update(Course $course): void;

    /**
     * @param list<int> $ids
     *
     * @return list<int> the subset of $ids that exist
     */
    public function existingIds(array $ids): array;

    /**
     * Published, publicly visible courses only, ordered by title.
     * @param list<int>|null $visibleCategoryIds Native view restrictions; null means no host restriction.
     */
    public function catalogue(?string $titleContains, ?int $categoryId, int $page, int $perPage, ?array $visibleCategoryIds = null): CoursePage;

    /**
     * @param list<int> $prerequisiteCourseIds
     */
    public function replacePrerequisites(int $courseId, array $prerequisiteCourseIds): void;

    /**
     * @return list<int>
     */
    public function prerequisiteIds(int $courseId): array;

    public function assignInstructor(int $courseId, int $userId, string $role): void;

    /**
     * @return bool false when no such instructor row existed
     */
    public function removeInstructor(int $courseId, int $userId): bool;

    public function isInstructor(int $courseId, int $userId): bool;

    /**
     * @param list<int> $ids
     *
     * @return list<Course> in the order the ids were given, unknown ids dropped
     */
    public function byIds(array $ids): array;

    /**
     * The management list: every state, optionally narrowed to a set of
     * course ids (the per-course rule hands the caller's own courses here).
     *
     * $sortColumn is a stored column ('title', 'state', 'published_at',
     * 'updated_at'); pass null to leave the order to the id tie break.
     *
     * @param list<int>|null $restrictToIds null means every course
     *
     * @return list<CourseRecord>
     */
    public function records(
        ?array $restrictToIds,
        ?string $state,
        ?string $titleContains,
        ?string $sortColumn,
        string $direction,
        ?int $limit,
        int $offset,
        ?int $categoryId = null,
    ): array;

    /**
     * The same filtered list ordered on one of the counts that are computed
     * on read, cut to a page at the database.
     *
     * The counts are aggregates over a left join to enrolments, so the sort
     * and the page happen in one grouped query and the application never
     * sees a course outside the page. $countSort is one of the keys in
     * CourseRepository::COUNT_SORTS.
     *
     * @param list<int>|null $restrictToIds null means every course
     *
     * @return list<int> the page's course ids, in order
     */
    public function idsByCount(
        ?array $restrictToIds,
        ?string $state,
        ?string $titleContains,
        string $countSort,
        string $direction,
        int $limit,
        int $offset,
        ?int $categoryId = null,
    ): array;

    /**
     * @param list<int> $ids
     *
     * @return list<CourseRecord> in the order the ids were given, unknown ids dropped
     */
    public function recordsByIds(array $ids): array;

    /**
     * @param list<int>|null $restrictToIds
     */
    public function countRecords(?array $restrictToIds, ?string $state, ?string $titleContains, ?int $categoryId = null): int;

    /**
     * Lesson and enrolment counts for a whole page of courses, in a fixed
     * number of queries: never one query per course.
     *
     * @param list<int> $courseIds
     *
     * @return array<int, CourseCounts> keyed by course id, one entry per id given
     */
    public function countsFor(array $courseIds): array;

    /**
     * @param list<int> $courseIds
     *
     * @return array<int, list<array{user_id: int, role: string}>> keyed by course id, lead roles first
     */
    public function instructorsFor(array $courseIds): array;

    /**
     * @return list<int> the courses this user is an assigned instructor of
     */
    public function courseIdsForInstructor(int $userId): array;
}
