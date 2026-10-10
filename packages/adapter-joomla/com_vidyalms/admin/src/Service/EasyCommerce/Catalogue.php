<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class Catalogue implements MappingCatalogue
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function find(string $kind, array $ids): array
    {
        $rows = [];
        foreach (array_chunk(CourseMapping::ids($ids), 200) as $chunk) {
            $query = $this->query($kind)->whereIn($this->db->quoteName('id'), $chunk);
            foreach ($this->db->setQuery($query)->loadAssocList() as $row) {
                $rows[(int) $row['id']] = $this->normalize($row);
            }
        }
        return $rows;
    }

    public function search(string $kind, string $search, int $page, int $productId = 0): array
    {
        $query = $this->query($kind);
        if ($kind === 'courses') {
            $query->where("price_mode = 'paid'")->where("state <> 'trashed'");
        }
        if ($kind === 'variations') {
            $query->where($this->db->quoteName('product_id') . ' = :product')->bind(':product', $productId, ParameterType::INTEGER);
        }
        if ($search !== '') {
            $needle = '%' . $this->db->escape($search, true) . '%';
            $field = $kind === 'variations' ? 'sku' : 'title';
            $query->where($this->db->quoteName($field) . ' LIKE :search')->bind(':search', $needle);
        }
        $query->order($this->db->quoteName($kind === 'variations' ? 'id' : 'title') . ' ASC')->order($this->db->quoteName('id') . ' ASC');
        $rows = $this->db->setQuery($query, ($page - 1) * 20, 21)->loadAssocList();
        return ['items' => array_map($this->normalize(...), array_slice($rows, 0, 20)), 'more' => count($rows) > 20];
    }

    private function query(string $kind): \Joomla\Database\QueryInterface
    {
        [$table, $columns] = match ($kind) {
            'products' => ['#__easycommerce_products', ['id', 'title', 'sku', 'type', 'status']],
            'variations' => ['#__easycommerce_product_variations', ['id', 'product_id', 'sku', 'status']],
            'courses' => ['#__vidyalms_courses', ['id', 'title', 'price_mode', 'state']],
            default => throw new \InvalidArgumentException('Unknown catalogue.'),
        };
        return $this->db->createQuery()->select($this->db->quoteName($columns))->from($this->db->quoteName($table));
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        if (isset($row['product_id'])) {
            $row['product_id'] = (int) $row['product_id'];
        }
        return $row;
    }
}
