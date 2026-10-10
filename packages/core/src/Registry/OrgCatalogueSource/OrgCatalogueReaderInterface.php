<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource;

/**
 * The three questions an `OrgCatalogueSource` provider may ask the database,
 * and the only three.
 *
 * It exists so {@see \Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext}
 * can carry a reader without carrying a `DatabasePort`. Every implementation is
 * already narrowed to one organisation at construction, so a provider cannot
 * supply the tenant, cannot widen the predicate and cannot write.
 *
 * None of the three answers authorises anything. `releasedCourseIds` is what a
 * site owner released, which is the maximum a source may select from, and
 * `CourseReleasePolicy` is still run over every emitted grant by the caller:
 * a provider that ignored this reader entirely and named a course of its own
 * invention still grants nothing.
 */
interface OrgCatalogueReaderInterface
{
    /**
     * The course ids this organisation currently reaches, at the reader's own
     * instant.
     *
     * @return list<int>
     */
    public function releasedCourseIds(): array;

    /**
     * The course ids named by this organisation's active catalogue entries
     * scoped to any of these units.
     *
     * Entries of the asking source's own key are excluded by the caller, so a
     * subtree source cannot resolve through another subtree source and loop.
     *
     * @param list<int> $unitIds
     * @param list<string> $excludeSourceKeys
     *
     * @return list<array{course_id: int, unit_id: int}>
     */
    public function courseIdsAssignedToUnits(array $unitIds, array $excludeSourceKeys = []): array;

    /**
     * Which of these course ids exist and are published.
     *
     * @param list<int> $courseIds
     *
     * @return list<int>
     */
    public function publishedCourseIds(array $courseIds): array;
}
