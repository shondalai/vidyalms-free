<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * Templates cross this boundary as plain rows because the renderer extension
 * point consumes the row shape directly; the paid designer adds columns the
 * core never needs to model.
 *
 * Pages and elements cross as entities rather than rows, because the layout
 * service and the element providers both read them and neither should be
 * parsing an associative array to find a reference point.
 */
interface CertificateTemplateRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedDefault(): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array;

    /**
     * A template by its shipped identifier rather than its editable name, so
     * a package can still find what it seeded after somebody has renamed it.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(string $templateKey): ?array;

    public function insert(
        string $name,
        string $renderer,
        string $body,
        bool $isDefault,
        string $state,
        ?string $templateKey = null,
        string $format = 'html',
    ): int;

    /**
     * The pages of one template in sequence, each carrying its elements in
     * draw order. One call, because the frozen snapshot needs the whole
     * aggregate and a per-page fetch inside a loop is the query pattern this
     * codebase tests against elsewhere.
     *
     * @return list<CertificateTemplatePage>
     */
    public function pagesOf(int $templateId): array;

    /**
     * First-page artwork for one bounded library page, loaded in two queries.
     * @param list<int> $templateIds
     * @return array<int, CertificateTemplatePage> keyed by template id
     */
    public function firstPagesFor(array $templateIds): array;

    public function insertPage(CertificateTemplatePage $page): int;

    public function insertElement(int $pageId, CertificateTemplateElement $element): int;

    /**
     * Atomically claims one aggregate revision. The equality predicate is
     * the concurrency boundary: exactly one writer can move expected to
     * expected + 1, and a zero result means missing or stale.
     */
    public function claimRevision(
        int $templateId,
        int $expectedRevision,
        \DateTimeImmutable $now,
    ): bool;

    /**
     * Legacy reservation used by bulk reissue until publication revisions
     * replace it. Implementations must use a CAS retry and must never write a
     * revision derived from a stale read.
     */
    public function bumpRevision(int $templateId, \DateTimeImmutable $now): int;

    /**
     * Freezes criteria and expiry on first award, following Moodle's badge
     * lock rule. Without it the same credential means different things to
     * different holders and every audit question becomes unanswerable.
     * Idempotent: an already locked template keeps its original timestamp.
     */
    public function lock(int $templateId, \DateTimeImmutable $lockedAt): void;

    /**
     * One page of templates for the authoring list, newest identifier last so
     * the seeded default stays where an author expects it.
     *
     * @return list<array<string, mixed>>
     */
    public function search(
        ?string $state,
        ?string $format,
        int $limit,
        int $offset,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): array;

    public function countMatching(
        ?string $state,
        ?string $format,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): int;

    /**
     * How many pages and elements each of these templates holds, keyed by
     * template id. Two queries for the whole page of the list, because a
     * count per row inside a loop is the query pattern this codebase asserts
     * against elsewhere.
     *
     * @param list<int> $templateIds
     *
     * @return array<int, array{pages: int, elements: int}>
     */
    public function countsFor(array $templateIds): array;

    /**
     * Column values, applied to the template row. Templates cross this
     * boundary as rows in both directions for the reason the class docblock
     * gives: the renderer extension point consumes the row shape directly and
     * the paid designer adds columns the core never models. The repository
     * owns updated_at.
     *
     * @param array<string, mixed> $changes
     */
    public function update(int $templateId, array $changes): void;

    /**
     * Takes the default flag off every other template, so exactly one row can
     * answer findPublishedDefault(). Issuance resolves the default by that
     * query, and two rows carrying the flag make which one it finds an
     * accident of insertion order.
     */
    public function clearDefaultExcept(int $templateId): void;

    /**
     * One page without its elements. The page routes address a page by its
     * own id and have to find the template it belongs to before they may
     * check the aggregate's revision or its lock.
     */
    public function findPage(int $pageId): ?CertificateTemplatePage;

    /**
     * The elements of one page in draw order.
     *
     * @return list<CertificateTemplateElement>
     */
    public function elementsOf(int $pageId): array;

    public function findElement(int $elementId): ?CertificateTemplateElement;

    public function updatePage(CertificateTemplatePage $page): void;

    public function updateElement(CertificateTemplateElement $element): void;

    /**
     * Removes a page and the elements on it in one transaction, because an
     * element with no page has no paper to be drawn on and would be
     * unreachable rather than merely unused.
     */
    public function deletePage(int $pageId): void;

    public function deleteElement(int $elementId): void;

    /**
     * Replaces the page order with the given ids, in one transaction. The
     * caller has already proved the list is exactly the template's pages: a
     * partial reorder would silently drop a page from the render.
     *
     * @param list<int> $orderedPageIds
     */
    public function reorderPages(int $templateId, array $orderedPageIds): void;

    /**
     * @param list<int> $orderedElementIds
     */
    public function reorderElements(int $pageId, array $orderedElementIds): void;
}
