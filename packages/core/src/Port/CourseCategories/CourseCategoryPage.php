<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/** A server-cut page from the host's native category store. */
final class CourseCategoryPage
{
    /**
     * @param list<CourseCategory> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        /** The host's category-management destination, when this caller may use it. */
        public readonly ?string $managementUrl,
    ) {
    }
}
