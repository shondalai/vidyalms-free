<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/** One native CMS category normalized for the course authoring surface. */
final class CourseCategory
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?int $parentId,
        /** The complete human-readable ancestry, e.g. "Business / Leadership". */
        public readonly string $label,
        public readonly CourseCategoryState $state,
        /** The host's edit destination for this row, when it has one. */
        public readonly ?string $editUrl,
    ) {
    }
}
