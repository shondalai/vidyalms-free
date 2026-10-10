<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/** Native category publication and view-access restrictions. */
interface CourseCategoryVisibility
{
    /** @return list<int> Published category IDs visible to this host user (0 for guests). */
    public function visibleCategoryIds(int $userId): array;
}
