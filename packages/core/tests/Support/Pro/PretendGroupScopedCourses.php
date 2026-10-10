<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CoursePage;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;

/**
 * The shape of what Pro actually needs: the free repository, narrowed. It
 * delegates everything, which is the point: a replacement decorates rather
 * than reimplements.
 *
 * @internal
 */
final class PretendGroupScopedCourses implements CourseRepository
{
    public static int $catalogueCalls = 0;

    public function __construct(private readonly CourseRepository $inner)
    {
        self::$catalogueCalls = 0;
    }

    public function byId(int $id): ?Course
    {
        return $this->inner->byId($id);
    }

    public function bySlug(string $slug): ?Course
    {
        return $this->inner->bySlug($slug);
    }

    public function slugExists(string $slug, ?int $excludeCourseId = null): bool
    {
        return $this->inner->slugExists($slug, $excludeCourseId);
    }

    public function add(Course $course): int
    {
        return $this->inner->add($course);
    }

    public function update(Course $course): void
    {
        $this->inner->update($course);
    }

    public function existingIds(array $ids): array
    {
        return $this->inner->existingIds($ids);
    }

    /** @param list<int>|null $visibleCategoryIds */
    public function catalogue(
        ?string $titleContains,
        ?int $categoryId,
        int $page,
        int $perPage,
        ?array $visibleCategoryIds = null,
    ): CoursePage {
        self::$catalogueCalls++;

        return $this->inner->catalogue($titleContains, $categoryId, $page, $perPage, $visibleCategoryIds);
    }

    public function replacePrerequisites(int $courseId, array $prerequisiteCourseIds): void
    {
        $this->inner->replacePrerequisites($courseId, $prerequisiteCourseIds);
    }

    public function prerequisiteIds(int $courseId): array
    {
        return $this->inner->prerequisiteIds($courseId);
    }

    public function assignInstructor(int $courseId, int $userId, string $role): void
    {
        $this->inner->assignInstructor($courseId, $userId, $role);
    }

    public function removeInstructor(int $courseId, int $userId): bool
    {
        return $this->inner->removeInstructor($courseId, $userId);
    }

    public function isInstructor(int $courseId, int $userId): bool
    {
        return $this->inner->isInstructor($courseId, $userId);
    }

    public function byIds(array $ids): array
    {
        return $this->inner->byIds($ids);
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
        return $this->inner->records($restrictToIds, $state, $titleContains, $sortColumn, $direction, $limit, $offset, $categoryId);
    }

    public function countRecords(?array $restrictToIds, ?string $state, ?string $titleContains, ?int $categoryId = null): int
    {
        return $this->inner->countRecords($restrictToIds, $state, $titleContains, $categoryId);
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
        return $this->inner->idsByCount(
            $restrictToIds,
            $state,
            $titleContains,
            $countSort,
            $direction,
            $limit,
            $offset,
            $categoryId,
        );
    }

    public function recordsByIds(array $ids): array
    {
        return $this->inner->recordsByIds($ids);
    }

    public function countsFor(array $courseIds): array
    {
        return $this->inner->countsFor($courseIds);
    }

    public function instructorsFor(array $courseIds): array
    {
        return $this->inner->instructorsFor($courseIds);
    }

    public function courseIdsForInstructor(int $userId): array
    {
        return $this->inner->courseIdsForInstructor($userId);
    }
}
