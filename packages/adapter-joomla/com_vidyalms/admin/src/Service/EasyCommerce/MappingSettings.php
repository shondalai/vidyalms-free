<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Domain\Platform\ConfigurationRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;

/** Component-owned configuration; one snapshot per request, no stale cross-request cache. */
final class MappingSettings
{
    public const NAMESPACE = 'commerce.easycommerce.mappings';
    private ?array $snapshot = null;
    private ?CourseMapping $runtime = null;

    public function __construct(private readonly ConfigurationRepository $repository, private readonly MappingCatalogue $catalogue)
    {
    }

    public function reset(): void
    {
        $this->snapshot = null;
        $this->runtime = null;
    }

    public function snapshot(): array
    {
        if ($this->snapshot === null) {
            $stored = $this->repository->read();
            if ($stored === null) {
                $this->repository->initialize(['mappings' => []]);
                $stored = $this->repository->read();
            }
            if ($stored === null) {
                throw new \RuntimeException('Mapping settings could not be initialized.');
            }
            $this->snapshot = ['revision' => $stored['revision'], 'mappings' => $stored['values']['mappings'] ?? []];
        }
        return $this->snapshot;
    }

    public function mapping(): CourseMapping
    {
        if ($this->runtime === null) {
            $mapping = new CourseMapping($this->snapshot()['mappings']);
            $courses = $this->catalogue->find('courses', $mapping->courseIds());
            $paid = array_keys(array_filter($courses, static fn (array $row): bool => $row['price_mode'] === 'paid'));
            $this->runtime = $mapping->onlyPaid($paid);
        }
        return $this->runtime;
    }

    /** No enrolments are edited here. Purchase snapshots remain owned by the sync worker. */
    public function save(int $revision, int $product, int $variation, array $courses, bool $remove = false, bool $create = false): void
    {
        $current = $this->snapshot();
        if ($revision !== $current['revision']) {
            throw new ConflictException('COM_VIDYALMS_SHOP_MAPPING_CONFLICT');
        }
        $mappings = $current['mappings'];
        $index = null;
        foreach ($mappings as $key => $row) {
            if ($row['product_id'] === $product && $row['variation_id'] === $variation) {
                $index = $key;
                break;
            }
        }
        if ($remove) {
            if ($index === null) {
                throw new ConflictException('COM_VIDYALMS_SHOP_MAPPING_CONFLICT');
            }
            unset($mappings[$index]);
        } else {
            if (($create && $index !== null) || (!$create && $index === null)) {
                throw new ConflictException('COM_VIDYALMS_SHOP_MAPPING_DUPLICATE');
            }
            $productRow = $this->catalogue->find('products', [$product])[$product] ?? null;
            $variant = $variation > 0 ? ($this->catalogue->find('variations', [$variation])[$variation] ?? null) : null;
            if ($productRow === null || ($variation > 0 && ($variant['product_id'] ?? 0) !== $product)) {
                throw new \InvalidArgumentException('COM_VIDYALMS_SHOP_MAPPING_PRODUCT_INVALID');
            }
            if (count($courses) < 1 || count($courses) > 50 || count(CourseMapping::ids($courses)) !== count($courses)) {
                throw new \InvalidArgumentException('COM_VIDYALMS_SHOP_MAPPING_COURSES_INVALID');
            }
            $selected = $this->catalogue->find('courses', $courses);
            foreach ($courses as $course) {
                if (!is_int($course) || !isset($selected[$course]) || $selected[$course]['price_mode'] !== 'paid'
                    || $selected[$course]['state'] === 'trashed') {
                    throw new \InvalidArgumentException('COM_VIDYALMS_SHOP_MAPPING_COURSES_INVALID');
                }
            }
            if ($index === null && count($mappings) >= 500) {
                throw new \InvalidArgumentException('COM_VIDYALMS_SHOP_MAPPING_LIMIT');
            }
            $row = ['product_id' => $product, 'variation_id' => $variation, 'courses' => CourseMapping::ids($courses)];
            if ($index === null) {
                $mappings[] = $row;
            } else {
                $mappings[$index] = $row;
            }
        }
        $mappings = array_values($mappings);
        $this->repository->replace($revision, ['mappings' => $mappings]);
        $this->snapshot = ['revision' => $revision + 1, 'mappings' => $mappings];
        $this->runtime = null;
    }

    /** Batched labels, including unavailable items so administrators can repair/remove them. */
    public function display(): array
    {
        $snapshot = $this->snapshot();
        $rows = $snapshot['mappings'];
        $products = $this->catalogue->find('products', array_column($rows, 'product_id'));
        $variations = $this->catalogue->find('variations', array_column($rows, 'variation_id'));
        $courses = $this->catalogue->find('courses', (new CourseMapping($rows))->courseIds());
        $snapshot['mappings'] = array_map(static fn (array $row): array => $row + [
            'product' => $products[$row['product_id']] ?? null,
            'variation' => $variations[$row['variation_id']] ?? null,
            'course_details' => array_map(static fn (int $id): array => $courses[$id] ?? ['id' => $id, 'title' => null, 'price_mode' => null, 'state' => null], $row['courses']),
        ], $rows);
        return $snapshot;
    }
}
