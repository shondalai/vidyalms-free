<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class SqlCertificateTemplateRepository implements CertificateTemplateRepository
{
    private const TABLE = 'certificate_templates';
    private const PAGES = 'certificate_template_pages';
    private const ELEMENTS = 'certificate_template_elements';
    private const DATETIME = 'Y-m-d H:i:s';
    private const MAX_REVISION = 2_147_483_647;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function findPublishedDefault(): ?array
    {
        return $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('is_default', '=', 1)
                ->where('state', '=', 'published')
                ->orderBy('id', Query::SORT_ASC),
        );
    }

    public function findById(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));
    }

    public function findByKey(string $templateKey): ?array
    {
        return $this->database->selectOne(Query::from(self::TABLE)->where('template_key', '=', $templateKey));
    }

    public function insert(
        string $name,
        string $renderer,
        string $body,
        bool $isDefault,
        string $state,
        ?string $templateKey = null,
        string $format = 'html',
    ): int {
        $now = $this->clock->now()->format(self::DATETIME);

        return $this->database->insert(self::TABLE, [
            'name' => $name,
            'template_key' => $templateKey,
            'renderer' => $renderer,
            'format' => $format,
            'body' => $body,
            'is_default' => $isDefault ? 1 : 0,
            'active_default_slot' => $isDefault && $state === 'published' ? 'site' : null,
            'state' => $state,
            'has_unpublished_changes' => $state === 'published' ? 0 : 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Two queries for the whole aggregate rather than one per page, because
     * the frozen snapshot needs every page and every element and a fetch
     * inside a loop is the query pattern this codebase asserts against
     * elsewhere.
     */
    public function pagesOf(int $templateId): array
    {
        $pageRows = $this->database->selectAll(
            Query::from(self::PAGES)
                ->where('template_id', '=', $templateId)
                ->orderBy('sequence', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        );

        if ($pageRows === []) {
            return [];
        }

        $pageIds = array_map(static fn (array $row): int => (int) $row['id'], $pageRows);

        $elementRows = $this->database->selectAll(
            Query::from(self::ELEMENTS)
                ->whereIn('page_id', $pageIds)
                ->orderBy('sequence', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        );

        /** @var array<int, list<CertificateTemplateElement>> $byPage */
        $byPage = [];

        foreach ($elementRows as $row) {
            $byPage[(int) $row['page_id']][] = $this->mapElement($row);
        }

        $pages = [];

        foreach ($pageRows as $row) {
            $id = (int) $row['id'];
            $pages[] = $this->mapPage($row)->withElements($byPage[$id] ?? []);
        }

        return $pages;
    }

    public function firstPagesFor(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }
        $first = [];
        foreach (
            $this->database->selectAll(Query::from(self::PAGES)
            ->whereIn('template_id', $templateIds)
            ->orderBy('sequence', Query::SORT_ASC)->orderBy('id', Query::SORT_ASC)) as $row
        ) {
            $first[(int) $row['template_id']] ??= $this->mapPage($row);
        }
        if ($first === []) {
            return [];
        }
        $pageIds = array_map(static fn (CertificateTemplatePage $page): int => $page->id, array_values($first));
        $byPage = [];
        foreach (
            $this->database->selectAll(Query::from(self::ELEMENTS)
            ->whereIn('page_id', $pageIds)
            ->orderBy('sequence', Query::SORT_ASC)->orderBy('id', Query::SORT_ASC)) as $row
        ) {
            $byPage[(int) $row['page_id']][] = $this->mapElement($row);
        }
        foreach ($first as $id => $page) {
            $first[$id] = $page->withElements($byPage[$page->id] ?? []);
        }

        return $first;
    }

    public function insertPage(CertificateTemplatePage $page): int
    {
        $now = $this->clock->now()->format(self::DATETIME);

        return $this->database->insert(self::PAGES, [
            'template_id' => $page->templateId,
            'sequence' => $page->sequence,
            'width' => $page->width,
            'height' => $page->height,
            'orientation' => $page->orientation,
            'margin_top' => $page->marginTop,
            'margin_right' => $page->marginRight,
            'margin_bottom' => $page->marginBottom,
            'margin_left' => $page->marginLeft,
            'background' => $page->background,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function insertElement(int $pageId, CertificateTemplateElement $element): int
    {
        $now = $this->clock->now()->format(self::DATETIME);

        return $this->database->insert(self::ELEMENTS, [
            'page_id' => $pageId,
            'sequence' => $element->sequence,
            'element' => $element->element,
            'name' => $element->name,
            // The envelope is added on write and stripped on read, so the
            // element provider never sees it and a payload written by one
            // version is always readable as what wrote it.
            'data' => json_encode(
                StoredShape::wrap($element->data, $element->dataVersion),
                JSON_THROW_ON_ERROR,
            ),
            'data_version' => $element->dataVersion,
            'posx' => $element->posX,
            'posy' => $element->posY,
            'width' => $element->width,
            'height' => $element->height,
            'refpoint' => $element->refPoint,
            'alignment' => $element->alignment,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function claimRevision(
        int $templateId,
        int $expectedRevision,
        \DateTimeImmutable $now,
    ): bool {
        return 1 === $this->database->update(self::TABLE, [
            'revision' => $expectedRevision + 1,
            'updated_at' => $now->format(self::DATETIME),
        ], [
            'id' => $templateId,
            'revision' => $expectedRevision,
        ]);
    }

    public function bumpRevision(int $templateId, \DateTimeImmutable $now): int
    {
        // This remains only for the pre-publication bulk-reissue caller. A
        // bounded CAS loop prevents it from overwriting an authoring revision
        // that committed between this read and this write.
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            $row = $this->findById($templateId);

            if ($row === null) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('Certificate template %d does not exist.', $templateId));
            }

            $current = (int) ($row['revision'] ?? 1);

            if ($current >= self::MAX_REVISION) {
                throw ConflictException::with(
                    'The certificate template revision cannot advance further.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    ['current_revision' => $current],
                );
            }

            if ($this->claimRevision($templateId, $current, $now)) {
                return $current + 1;
            }
        }

        throw new ConflictException('The certificate template kept changing while its revision was reserved.');
    }

    public function lock(int $templateId, \DateTimeImmutable $lockedAt): void
    {
        $row = $this->findById($templateId);

        if ($row === null || ($row['locked_at'] ?? null) !== null) {
            // Idempotent by design: the lock records when the criteria froze,
            // and rewriting it on the second award would move the moment the
            // audit trail names.
            return;
        }

        $this->database->update(self::TABLE, [
            'locked_at' => $lockedAt->format(self::DATETIME),
            'updated_at' => $lockedAt->format(self::DATETIME),
        ], ['id' => $templateId]);
    }

    public function search(
        ?string $state,
        ?string $format,
        int $limit,
        int $offset,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): array {
        return $this->database->selectAll(
            $this->matching($state, $format, $search, $category, $renderer)
                ->select([
                    'id', 'template_key', 'name', 'renderer', 'format', 'achievement_type', 'revision',
                    'schema_version', 'locale', 'state', 'is_default', 'expiry_mode', 'expiry_days', 'expiry_at',
                    'locked_at', 'published_revision', 'published_at', 'has_unpublished_changes', 'category', 'provenance',
                ])
                ->orderBy('id', Query::SORT_ASC)
                ->limit($limit)
                ->offset($offset),
        );
    }

    public function countMatching(
        ?string $state,
        ?string $format,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): int {
        return $this->database->count($this->matching($state, $format, $search, $category, $renderer));
    }

    public function countsFor(array $templateIds): array
    {
        if ($templateIds === []) {
            return [];
        }

        $counts = [];
        foreach ($templateIds as $templateId) {
            $counts[$templateId] = ['pages' => 0, 'elements' => 0];
        }
        $rows = $this->database->selectAll(
            Query::from(self::PAGES, 'p')->select(['p.template_id'])
                ->leftJoin(self::ELEMENTS, 'e', 'p.id', 'e.page_id')
                ->whereIn('p.template_id', $templateIds)->groupBy('p.template_id')
                ->aggregate(
                    \Shondalai\VidyaLMS\Core\Port\Database\Aggregate::countDistinctOf('p.id', 'pages'),
                    \Shondalai\VidyaLMS\Core\Port\Database\Aggregate::countOf('e.id', 'elements'),
                ),
        );
        foreach ($rows as $row) {
            $counts[(int) $row['template_id']] = ['pages' => (int) $row['pages'], 'elements' => (int) $row['elements']];
        }

        return $counts;
    }

    public function update(int $templateId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $changes['updated_at'] = $this->clock->now()->format(self::DATETIME);

        $this->database->update(self::TABLE, $changes, ['id' => $templateId]);
    }

    public function clearDefaultExcept(int $templateId): void
    {
        $now = $this->clock->now()->format(self::DATETIME);

        foreach ($this->database->selectAll(Query::from(self::TABLE)->where('is_default', '=', 1)) as $row) {
            $id = (int) $row['id'];

            if ($id !== $templateId) {
                $this->database->update(self::TABLE, [
                    'is_default' => 0,
                    'active_default_slot' => null,
                    'updated_at' => $now,
                ], ['id' => $id]);
            }
        }
    }

    public function findPage(int $pageId): ?CertificateTemplatePage
    {
        $row = $this->database->selectOne(Query::from(self::PAGES)->where('id', '=', $pageId));

        return $row === null ? null : $this->mapPage($row);
    }

    public function elementsOf(int $pageId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::ELEMENTS)
                ->where('page_id', '=', $pageId)
                ->orderBy('sequence', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        );

        return array_map(fn (array $row): CertificateTemplateElement => $this->mapElement($row), $rows);
    }

    public function findElement(int $elementId): ?CertificateTemplateElement
    {
        $row = $this->database->selectOne(Query::from(self::ELEMENTS)->where('id', '=', $elementId));

        return $row === null ? null : $this->mapElement($row);
    }

    public function updatePage(CertificateTemplatePage $page): void
    {
        $this->database->update(self::PAGES, [
            'sequence' => $page->sequence,
            'width' => $page->width,
            'height' => $page->height,
            'orientation' => $page->orientation,
            'margin_top' => $page->marginTop,
            'margin_right' => $page->marginRight,
            'margin_bottom' => $page->marginBottom,
            'margin_left' => $page->marginLeft,
            'background' => $page->background,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $page->id]);
    }

    public function updateElement(CertificateTemplateElement $element): void
    {
        $this->database->update(self::ELEMENTS, [
            'sequence' => $element->sequence,
            'name' => $element->name,
            // The envelope is added on write and stripped on read, exactly as
            // the insert does it: a payload written by one version is always
            // readable as what wrote it.
            'data' => json_encode(
                StoredShape::wrap($element->data, $element->dataVersion),
                JSON_THROW_ON_ERROR,
            ),
            'data_version' => $element->dataVersion,
            'posx' => $element->posX,
            'posy' => $element->posY,
            'width' => $element->width,
            'height' => $element->height,
            'refpoint' => $element->refPoint,
            'alignment' => $element->alignment,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $element->id]);
    }

    public function deletePage(int $pageId): void
    {
        $this->database->transactional(function () use ($pageId): void {
            $this->database->delete(self::ELEMENTS, ['page_id' => $pageId]);
            $this->database->delete(self::PAGES, ['id' => $pageId]);
        });
    }

    public function deleteElement(int $elementId): void
    {
        $this->database->delete(self::ELEMENTS, ['id' => $elementId]);
    }

    public function reorderPages(int $templateId, array $orderedPageIds): void
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $this->database->transactional(function () use ($templateId, $orderedPageIds, $now): void {
            $sequence = 1;

            foreach ($orderedPageIds as $pageId) {
                $this->database->update(
                    self::PAGES,
                    ['sequence' => $sequence, 'updated_at' => $now],
                    ['id' => $pageId, 'template_id' => $templateId],
                );
                ++$sequence;
            }
        });
    }

    public function reorderElements(int $pageId, array $orderedElementIds): void
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $this->database->transactional(function () use ($pageId, $orderedElementIds, $now): void {
            $sequence = 1;

            foreach ($orderedElementIds as $elementId) {
                $this->database->update(
                    self::ELEMENTS,
                    ['sequence' => $sequence, 'updated_at' => $now],
                    ['id' => $elementId, 'page_id' => $pageId],
                );
                ++$sequence;
            }
        });
    }

    private function matching(
        ?string $state,
        ?string $format,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): Query {
        $query = Query::from(self::TABLE);
        if ($search !== '') {
            $query->whereContains('name', $search);
        }
        if ($category !== null) {
            $query->where('category', '=', $category);
        }
        if ($renderer !== null) {
            $query->where('renderer', '=', $renderer);
        }

        if ($state !== null && $state !== '') {
            $query->where('state', '=', $state);
        }

        if ($format !== null && $format !== '') {
            $query->where('format', '=', $format);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapPage(array $row): CertificateTemplatePage
    {
        return new CertificateTemplatePage(
            (int) $row['id'],
            (int) $row['template_id'],
            (int) ($row['sequence'] ?? 1),
            (float) ($row['width'] ?? 297),
            (float) ($row['height'] ?? 210),
            \is_string($row['orientation'] ?? null) ? $row['orientation'] : 'landscape',
            (int) ($row['margin_top'] ?? 10),
            (int) ($row['margin_right'] ?? 10),
            (int) ($row['margin_bottom'] ?? 10),
            (int) ($row['margin_left'] ?? 10),
            \is_string($row['background'] ?? null) && $row['background'] !== '' ? $row['background'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapElement(array $row): CertificateTemplateElement
    {
        $stored = [];
        $raw = $row['data'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $stored = $decoded;
            }
        }

        $version = StoredShape::versionOf($stored);

        return new CertificateTemplateElement(
            (int) $row['id'],
            (int) $row['page_id'],
            (string) $row['element'],
            StoredShape::bodyOf($stored),
            // The mirrored column is the indexed answer and the envelope is
            // the authoritative one; a legacy row with no envelope reads as
            // whatever the column says, which is one.
            $version > 0 ? $version : (int) ($row['data_version'] ?? 1),
            (int) ($row['sequence'] ?? 1),
            \is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : null,
            (int) ($row['posx'] ?? 0),
            (int) ($row['posy'] ?? 0),
            isset($row['width']) ? (int) $row['width'] : null,
            isset($row['height']) ? (int) $row['height'] : null,
            \is_string($row['refpoint'] ?? null) ? $row['refpoint'] : CertificateTemplateElement::REFPOINT_TOP_LEFT,
            \is_string($row['alignment'] ?? null) ? $row['alignment'] : 'left',
        );
    }
}
