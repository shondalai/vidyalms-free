<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPage;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;

final class FakeCourseCategories implements CourseCategoryPort
{
    /** @var array<int, CourseCategory> */
    private array $categories = [];

    public function __construct(
        public ?string $managementUrl = 'https://example.test/categories',
    ) {
    }

    public function add(
        int $id,
        string $name,
        ?int $parentId = null,
        ?string $label = null,
        CourseCategoryState $state = CourseCategoryState::Published,
        ?string $editUrl = null,
    ): CourseCategory {
        $category = new CourseCategory(
            $id,
            $name,
            $parentId,
            $label ?? $name,
            $state,
            $editUrl ?? 'https://example.test/categories/' . $id,
        );
        $this->categories[$id] = $category;

        return $category;
    }

    public function page(
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
    ): CourseCategoryPage {
        $items = array_values($this->categories);
        $needle = trim($search ?? '');

        if ($needle !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (CourseCategory $category): bool => stripos($category->name, $needle) !== false,
            ));
        }

        $direction = strtolower($order) === 'desc' ? -1 : 1;
        usort($items, static function (CourseCategory $left, CourseCategory $right) use ($sort, $direction): int {
            $primary = $sort === 'id'
                ? $left->id <=> $right->id
                : strcasecmp($left->name, $right->name);

            return $direction * ($primary !== 0 ? $primary : $left->id <=> $right->id);
        });

        $total = count($items);
        $items = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new CourseCategoryPage($items, $total, $page, $perPage, $this->managementUrl);
    }

    public function findById(int $id): ?CourseCategory
    {
        return $this->categories[$id] ?? null;
    }
}
