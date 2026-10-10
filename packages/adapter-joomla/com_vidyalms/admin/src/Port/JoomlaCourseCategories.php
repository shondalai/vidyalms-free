<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPage;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;

/**
 * Joomla's native category tree as the course-category picker contract.
 *
 * Filtering, ordering and paging stay in #__categories. Only the ancestors
 * needed to label the returned page are then read, one set per tree level;
 * the port never materialises the site's whole category tree.
 *
 * @since 1.0.0
 */
final class JoomlaCourseCategories implements CourseCategoryPort, \Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility
{
    private const EXTENSION = 'com_vidyalms';
    private const ROOT_ID = 1;

    public function visibleCategoryIds(int $userId): array
    {
        $user = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId);
        $levels = array_map('intval', $user->getAuthorisedViewLevels());
        if ($levels === []) {
            return [];
        }
        // A hidden parent hides its descendants as well. Both category state
        // and Joomla's view-access levels apply to the entire ancestry.
        $query = $this->db->createQuery()->select($this->db->quoteName(['id', 'parent_id', 'published', 'access']))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote(self::EXTENSION))
            ->order($this->db->quoteName('lft'));
        $visible = [self::ROOT_ID => true];
        $ids = [];
        foreach ($this->db->setQuery($query)->loadAssocList() ?? [] as $row) {
            $id = (int) $row['id'];
            if ((int) $row['published'] === 1 && in_array((int) $row['access'], $levels, true)
                && isset($visible[(int) $row['parent_id']])) {
                $visible[$id] = true;
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param \Closure(string, string): bool $authorise Joomla action and asset checker for the current caller
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly \Closure $authorise,
    ) {
    }

    public function page(
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
    ): CourseCategoryPage {
        $term = $search === null || trim($search) === '' ? null : trim($search);
        $sortColumn = match ($sort) {
            'name' => $this->db->quoteName('c.title'),
            'id' => $this->db->quoteName('c.id'),
            default => throw new \InvalidArgumentException('Unknown course category sort.'),
        };
        $direction = match (strtolower($order)) {
            'asc' => 'ASC',
            'desc' => 'DESC',
            default => throw new \InvalidArgumentException('Course category order must be asc or desc.'),
        };

        if ($page < 1 || $perPage < 1) {
            throw new \InvalidArgumentException('Course category page and page size must be positive.');
        }

        $count = $this->categoryQuery($term)
            ->clear('select')
            ->select('COUNT(*)');
        $total = (int) $this->db->setQuery($count)->loadResult();

        $query = $this->categoryQuery($term)
            ->select($this->db->quoteName(['c.id', 'c.title', 'c.parent_id', 'c.published']))
            ->order($sortColumn . ' ' . $direction);

        if ($sort !== 'id') {
            $query->order($this->db->quoteName('c.id') . ' ' . $direction);
        }

        $rows = $this->db->setQuery($query, ($page - 1) * $perPage, $perPage)->loadAssocList() ?? [];

        return new CourseCategoryPage(
            $this->categories($rows),
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

        $idValue = $id;
        $query = $this->categoryQuery(null)
            ->select($this->db->quoteName(['c.id', 'c.title', 'c.parent_id', 'c.published']))
            ->where($this->db->quoteName('c.id') . ' = :id')
            ->bind(':id', $idValue, ParameterType::INTEGER);
        $row = $this->db->setQuery($query, 0, 1)->loadAssoc();

        if (!\is_array($row)) {
            return null;
        }

        return $this->categories([$row])[0] ?? null;
    }

    private function categoryQuery(?string $term): QueryInterface
    {
        $extension = self::EXTENSION;
        $query = $this->db->createQuery()
            ->from($this->db->quoteName('#__categories', 'c'))
            ->where($this->db->quoteName('c.extension') . ' = :extension')
            ->bind(':extension', $extension, ParameterType::STRING);

        if ($term !== null) {
            // The term is data, never a LIKE pattern. Joomla's category title
            // collation decides case folding, as it does in com_categories.
            $pattern = '%' . addcslashes($term, '\\%_') . '%';
            $query->where($this->db->quoteName('c.title') . " LIKE :search ESCAPE '\\\\'")
                ->bind(':search', $pattern, ParameterType::STRING);
        }

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<CourseCategory>
     */
    private function categories(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $known */
        $known = [];

        foreach ($rows as $row) {
            $known[(int) $row['id']] = $row;
        }

        // One query per ancestor level, never one query per category. Already
        // known ids are not revisited, which also bounds a malformed cycle.
        $pending = $this->unknownParents($known, $known);

        while ($pending !== []) {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName(['id', 'title', 'parent_id', 'published']))
                ->from($this->db->quoteName('#__categories'))
                ->where($this->db->quoteName('extension') . ' = :extension')
                ->whereIn($this->db->quoteName('id'), $pending, ParameterType::INTEGER);
            $extension = self::EXTENSION;
            $query->bind(':extension', $extension, ParameterType::STRING);
            $ancestors = $this->db->setQuery($query)->loadAssocList() ?? [];
            $added = [];

            foreach ($ancestors as $ancestor) {
                $ancestorId = (int) $ancestor['id'];

                if (!isset($known[$ancestorId])) {
                    $known[$ancestorId] = $ancestor;
                    $added[$ancestorId] = $ancestor;
                }
            }

            if ($added === []) {
                break;
            }

            $pending = $this->unknownParents($added, $known);
        }

        return array_map(
            fn (array $row): CourseCategory => $this->category($row, $known),
            $rows,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $known
     *
     * @return list<int>
     */
    private function unknownParents(array $rows, array $known): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ($parentId > self::ROOT_ID && !isset($known[$parentId])) {
                $ids[$parentId] = $parentId;
            }
        }

        ksort($ids);

        return array_values($ids);
    }

    /**
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $known
     */
    private function category(array $row, array $known): CourseCategory
    {
        $id = (int) $row['id'];
        $parentId = (int) ($row['parent_id'] ?? 0);
        $parts = [];
        $cursor = $row;
        $seen = [];

        while (true) {
            $cursorId = (int) ($cursor['id'] ?? 0);

            if ($cursorId < 1 || isset($seen[$cursorId])) {
                break;
            }

            $seen[$cursorId] = true;
            $name = trim((string) ($cursor['title'] ?? ''));

            if ($name !== '') {
                array_unshift($parts, $name);
            }

            $ancestorId = (int) ($cursor['parent_id'] ?? 0);

            if ($ancestorId <= self::ROOT_ID || !isset($known[$ancestorId])) {
                break;
            }

            $cursor = $known[$ancestorId];
        }

        return new CourseCategory(
            $id,
            (string) $row['title'],
            $parentId === self::ROOT_ID ? null : ($parentId > 0 ? $parentId : null),
            implode(' / ', $parts),
            $this->state((int) $row['published']),
            $this->editUrl($id),
        );
    }

    private function state(int $state): CourseCategoryState
    {
        return match ($state) {
            1 => CourseCategoryState::Published,
            0 => CourseCategoryState::Unpublished,
            2 => CourseCategoryState::Archived,
            -2 => CourseCategoryState::Trashed,
            default => CourseCategoryState::Unpublished,
        };
    }

    private function managementUrl(): ?string
    {
        if (!(($this->authorise)('core.manage', self::EXTENSION))) {
            return null;
        }

        return rtrim(Uri::root(), '/')
            . '/administrator/index.php?option=com_categories&view=categories&extension=' . self::EXTENSION;
    }

    private function editUrl(int $id): ?string
    {
        if (
            !(($this->authorise)('core.manage', self::EXTENSION))
            || !(($this->authorise)('core.edit', self::EXTENSION . '.category.' . $id))
        ) {
            return null;
        }

        return rtrim(Uri::root(), '/')
            . '/administrator/index.php?option=com_categories&task=category.edit&id=' . $id
            . '&extension=' . self::EXTENSION;
    }
}
