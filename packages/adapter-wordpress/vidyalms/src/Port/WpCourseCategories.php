<?php

declare(strict_types=1);

/**
 * VidyaLMS: course categories over the native WordPress taxonomy.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPage;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;
use Shondalai\VidyaLMS\WordPress\Content\CourseCpt;

defined('ABSPATH') || exit;

/**
 * The LMS stores a category as a term id and leaves category authorship to
 * WordPress. This adapter is therefore read-only: changes stay on WordPress's
 * taxonomy screen, while the core gets one bounded, deterministic directory.
 *
 * Paging is performed by MySQL, not after get_terms() has materialised the
 * taxonomy. Only the rows on the requested page have their ancestor paths
 * hydrated. Ancestors are loaded a level at a time and cached, so twenty
 * sibling rows do not turn into twenty copies of the same parent query.
 */
final class WpCourseCategories implements CourseCategoryPort
{
    public function __construct(private readonly \wpdb $database)
    {
    }

    public function page(
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
    ): CourseCategoryPage {
        $search = trim((string) $search);
        $direction = strtolower($order) === 'desc' ? 'DESC' : 'ASC';
        $sortColumn = strtolower($sort) === 'id' ? 't.term_id' : 't.name';
        $where = 'tt.taxonomy = %s';
        $whereArguments = array(CourseCpt::CATEGORY_TAXONOMY);

        if ($search !== '') {
            // The term is data, including '%' and '_'. WordPress's helper
            // escapes those two LIKE metacharacters; the surrounding
            // wildcards are the only syntax introduced here.
            $where .= ' AND t.name LIKE %s';
            $whereArguments[] = '%' . $this->database->esc_like($search) . '%';
        }

        $countSql = $this->prepare(
            'SELECT COUNT(*)'
            . ' FROM ' . $this->database->terms . ' t'
            . ' INNER JOIN ' . $this->database->term_taxonomy . ' tt ON tt.term_id = t.term_id'
            . ' WHERE ' . $where,
            $whereArguments,
        );
        $total = (int) $this->database->get_var($countSql);

        $orderBy = $sortColumn . ' ' . $direction;

        if ($sortColumn !== 't.term_id') {
            $orderBy .= ', t.term_id ' . $direction;
        }

        $offset = max(0, ($page - 1) * $perPage);
        $rowsSql = $this->prepare(
            'SELECT t.term_id, t.name, tt.parent'
            . ' FROM ' . $this->database->terms . ' t'
            . ' INNER JOIN ' . $this->database->term_taxonomy . ' tt ON tt.term_id = t.term_id'
            . ' WHERE ' . $where
            . ' ORDER BY ' . $orderBy
            . ' LIMIT %d OFFSET %d',
            array_merge($whereArguments, array($perPage, $offset)),
        );

        /** @var array<int, array{term_id?: mixed, name?: mixed, parent?: mixed}> $rows */
        $rows = $this->database->get_results($rowsSql, ARRAY_A);
        $ancestors = $this->ancestorMap($rows);
        $items = array();

        foreach ($rows as $row) {
            $id = (int) ($row['term_id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $parentId = (int) ($row['parent'] ?? 0);
            $items[] = $this->category(
                $id,
                $name,
                $parentId > 0 ? $parentId : null,
                $ancestors,
            );
        }

        return new CourseCategoryPage(
            $items,
            $total,
            $page,
            $perPage,
            $this->managementUrl(),
        );
    }

    public function findById(int $id): ?CourseCategory
    {
        if ($id < 1) {
            return null;
        }

        $term = get_term($id, CourseCpt::CATEGORY_TAXONOMY);

        if (!$term instanceof \WP_Term) {
            return null;
        }

        $row = array(
            'term_id' => $term->term_id,
            'name' => $term->name,
            'parent' => $term->parent,
        );
        $ancestors = $this->ancestorMap(array($row));
        $parentId = (int) $term->parent;

        return $this->category(
            (int) $term->term_id,
            (string) $term->name,
            $parentId > 0 ? $parentId : null,
            $ancestors,
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    private function prepare(string $sql, array $arguments): string
    {
        $prepared = $this->database->prepare($sql, ...$arguments);

        if (!\is_string($prepared)) {
            throw new \RuntimeException('WordPress could not prepare the course-category query.');
        }

        return $prepared;
    }

    /**
     * Loads only ancestors of the returned rows. The map deliberately excludes
     * the page rows themselves unless one is also another row's ancestor.
     *
     * @param list<array{term_id?: mixed, name?: mixed, parent?: mixed}> $rows
     *
     * @return array<int, \WP_Term>
     */
    private function ancestorMap(array $rows): array
    {
        $requested = array();
        $wanted = array();

        foreach ($rows as $row) {
            $id = (int) ($row['term_id'] ?? 0);
            $parentId = (int) ($row['parent'] ?? 0);

            if ($parentId > 0 && $parentId !== $id) {
                $wanted[$parentId] = $parentId;
            }
        }

        $terms = array();

        while ($wanted !== array()) {
            $ids = array_values($wanted);
            $wanted = array();

            foreach ($ids as $id) {
                $requested[$id] = true;
            }

            $loaded = get_terms(array(
                'taxonomy' => CourseCpt::CATEGORY_TAXONOMY,
                'hide_empty' => false,
                'include' => $ids,
                'number' => \count($ids),
            ));

            if (is_wp_error($loaded)) {
                break;
            }

            foreach ($loaded as $term) {
                if (!$term instanceof \WP_Term) {
                    continue;
                }

                $id = (int) $term->term_id;

                if ($id < 1 || isset($terms[$id])) {
                    continue;
                }

                $terms[$id] = $term;
                $parentId = (int) $term->parent;

                if ($parentId > 0 && !isset($requested[$parentId]) && !isset($terms[$parentId])) {
                    $wanted[$parentId] = $parentId;
                }
            }
        }

        return $terms;
    }

    /**
     * @param array<int, \WP_Term> $ancestors
     */
    private function category(
        int $id,
        string $name,
        ?int $parentId,
        array $ancestors,
    ): CourseCategory {
        return new CourseCategory(
            $id,
            $name,
            $parentId,
            $this->label($id, $name, $parentId, $ancestors),
            CourseCategoryState::from('published'),
            $this->editUrl($id),
        );
    }

    /**
     * @param array<int, \WP_Term> $ancestors
     */
    private function label(int $id, string $name, ?int $parentId, array $ancestors): string
    {
        $parts = array();
        $seen = array($id => true);
        $current = $parentId ?? 0;

        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $term = $ancestors[$current] ?? null;

            if (!$term instanceof \WP_Term) {
                break;
            }

            $part = trim((string) $term->name);

            if ($part !== '') {
                $parts[] = $part;
            }

            $current = (int) $term->parent;
        }

        $parts = array_reverse($parts);
        $name = trim($name);

        if ($name !== '') {
            $parts[] = $name;
        }

        return implode(' / ', $parts);
    }

    private function editUrl(int $id): ?string
    {
        if (!$this->mayManage()) {
            return null;
        }

        $url = get_edit_term_link($id, CourseCpt::CATEGORY_TAXONOMY, CourseCpt::POST_TYPE);

        return \is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function managementUrl(): ?string
    {
        if (!$this->mayManage()) {
            return null;
        }

        return admin_url(
            'edit-tags.php?taxonomy=' . CourseCpt::CATEGORY_TAXONOMY
            . '&post_type=' . CourseCpt::POST_TYPE,
        );
    }

    private function mayManage(): bool
    {
        // The taxonomy maps both manage_terms and edit_terms to this native
        // capability. Assign-only instructors must not be sent to a 403 page.
        return current_user_can('vidyalms_manage');
    }
}
