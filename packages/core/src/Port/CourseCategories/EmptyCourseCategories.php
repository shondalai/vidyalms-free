<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/** Honest fallback for a host that has no course category source. */
final class EmptyCourseCategories implements CourseCategoryPort
{
    public function page(
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
    ): CourseCategoryPage {
        return new CourseCategoryPage([], 0, $page, $perPage, null);
    }

    public function findById(int $id): ?CourseCategory
    {
        return null;
    }
}
