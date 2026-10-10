<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

/** Product/variation mappings configured in the VidyaLMS component. */
final class CourseMapping
{
    /** @var array<int, array<int, list<int>>> */
    private array $products = [];

    public function __construct(mixed $rows)
    {
        foreach ((array) $rows as $row) {
            $row = (array) $row;
            $product = (int) ($row['product_id'] ?? 0);
            $variation = max(0, (int) ($row['variation_id'] ?? 0));
            if ($product < 1) {
                continue;
            }
            $courses = self::ids($row['courses'] ?? []);
            $this->products[$product][$variation] = self::ids([
                ...($this->products[$product][$variation] ?? []), ...$courses,
            ]);
        }
    }

    /** @return list<int> */
    public function courses(int $product, int $variation = 0): array
    {
        // A variation-specific mapping replaces the product default.
        return $this->products[$product][$variation] ?? $this->products[$product][0] ?? [];
    }

    /** @return list<int> */
    public function courseIds(): array
    {
        $ids = [];
        foreach ($this->products as $variations) {
            foreach ($variations as $courses) {
                $ids = [...$ids, ...$courses];
            }
        }
        return self::ids($ids);
    }

    /** A saved mapping cannot make a free course behave like a paid course. */
    public function onlyPaid(array $paidCourses): self
    {
        $mapping = clone $this;
        foreach ($mapping->products as &$variations) {
            foreach ($variations as &$courses) {
                $courses = array_values(array_intersect($courses, $paidCourses));
            }
            unset($courses);
        }
        unset($variations);
        return $mapping;
    }

    /** @return list<array{product_id: int, variation_id: int}> */
    public function productsFor(int $course): array
    {
        $result = [];
        foreach ($this->products as $product => $variations) {
            foreach ($variations as $variation => $courses) {
                if (in_array($course, $courses, true)) {
                    $result[] = ['product_id' => $product, 'variation_id' => $variation];
                }
            }
        }
        return $result;
    }

    public function containsMappedItems(array $items): bool
    {
        foreach ($items as $item) {
            $item = (array) $item;
            if ($this->courses((int) ($item['product_id'] ?? 0), (int) ($item['variation_id'] ?? 0)) !== []) {
                return true;
            }
        }
        return false;
    }

    /** @return list<int> */
    public static function ids(mixed $value): array
    {
        $values = is_string($value) ? preg_split('/[\s,]+/', trim($value)) : (array) $value;
        $ids = [];
        foreach ($values ?: [] as $id) {
            if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
