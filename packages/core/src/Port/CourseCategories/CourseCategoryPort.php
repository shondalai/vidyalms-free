<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/**
 * Read access to the CMS category source used by courses.
 *
 * Paging, literal own-name search and ordering happen inside the host. The
 * only portable sorts are name and id; both use id in the same direction as
 * their final tie break. Full ancestry labels are resolved for the returned
 * page rather than by materialising a whole category tree in the core.
 *
 * A lookup is a point-in-time host read, not a portable lock. Native category
 * stores do not share one transaction/foreign-key contract across Joomla and
 * WordPress, so a host administrator may delete a category immediately after
 * validation. Courses deliberately tolerate that eventual orphan: exact
 * lookup then reports the id as missing and an author can clear or replace it.
 */
interface CourseCategoryPort
{
    public function page(
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
    ): CourseCategoryPage;

    /** Includes trashed rows; null means the native category no longer exists. */
    public function findById(int $id): ?CourseCategory;
}
