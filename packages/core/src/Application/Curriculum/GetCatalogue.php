<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CoursePage;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;

/**
 * The public catalogue: published courses with public visibility. Open to
 * guests, which is why no capability is required here.
 */
final class GetCatalogue
{
    public const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility $categoryVisibility = null,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $q = null,
        ?int $categoryId = null,
        int $page = 1,
        int $perPage = 20,
    ): CoursePage {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        $q = $q !== null ? trim($q) : null;

        if ($q === '') {
            $q = null;
        }

        return $this->courses->catalogue($q, $categoryId, $page, $perPage, $this->categoryVisibility?->visibleCategoryIds($principal->userId));
    }
}
