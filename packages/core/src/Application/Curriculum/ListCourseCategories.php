<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPage;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;

/** Lists the host's course categories without pulling its whole tree into the core. */
final class ListCourseCategories
{
    public const SORTS = ['name', 'id'];

    public function __construct(
        private readonly CourseCategoryPort $categories,
        private readonly AccessGuard $guard,
        private readonly ListingPolicy $policy,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $search = null,
        string $sort = 'name',
        string $order = 'asc',
        int $page = 1,
        int $perPage = 20,
    ): CourseCategoryPage {
        $this->guard->requireAny($principal, Capability::TEACH, Capability::MANAGE);

        if (!\in_array($sort, self::SORTS, true)) {
            throw ValidationException::forField('sort', 'The sort must be name or id.');
        }

        $order = strtolower($order);

        if (!\in_array($order, ['asc', 'desc'], true)) {
            throw ValidationException::forField('order', 'The order must be asc or desc.');
        }

        if ($page < 1) {
            throw ValidationException::forField('page', 'Must be 1 or more.');
        }

        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            throw ValidationException::forField(
                'per_page',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('Must be between 1 and %d.', $this->policy->maxPerPage),
            );
        }

        $search = $search === null || trim($search) === '' ? null : trim($search);
        $native = $this->categories->page($search, $sort, $order, $page, $perPage);

        // Page coordinates are contract input, not adapter opinion. Keeping
        // them here makes the answer exact even when a native API omits or
        // normalizes an empty page's coordinates.
        return new CourseCategoryPage(
            $native->items,
            $native->total,
            $page,
            $perPage,
            $native->managementUrl,
        );
    }
}
