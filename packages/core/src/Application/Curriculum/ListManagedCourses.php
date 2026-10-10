<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The courses the caller may act on, in every state, with the counts the
 * course list and the workbench header show.
 *
 * A caller holding manage sees every course. A caller holding teach, grade,
 * enrol or reports without manage sees only the courses they are an assigned
 * instructor of, which is the per-course rule applied to a listing: the set
 * is narrowed rather than each row refused. A caller holding none of them is
 * refused outright, before any course is looked at.
 *
 * Sorting, filtering and paging are the server's work, and all three happen
 * at the database. Four sorts are stored columns; the other three are counts
 * computed on read, which the repository expresses as grouped aggregates
 * over a join, so a page of twenty never reads a course outside it.
 */
final class ListManagedCourses
{
    public const SORTS = [
        'title',
        'state',
        ...CourseRepository::COUNT_SORTS,
        'published_at',
        'updated_at',
    ];

    private const COLUMN_SORTS = [
        'title' => 'title',
        'state' => 'state',
        'published_at' => 'published_at',
        'updated_at' => 'updated_at',
    ];

    private const SURFACE_CAPABILITIES = [
        Capability::TEACH,
        Capability::GRADE,
        Capability::ENROL_OTHERS,
        Capability::VIEW_REPORTS,
    ];

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly ManagedCourseView $view,
        private readonly AccessGuard $guard,
        private readonly ListingPolicy $policy,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $state = null,
        ?string $titleContains = null,
        string $sort = 'title',
        string $order = 'asc',
        int $page = 1,
        int $perPage = 20,
        ?int $categoryId = null,
    ): ManagedCoursePage {
        $this->guard->requireAuthenticated($principal);

        $restrictToIds = $this->scopeFor($principal);

        if ($state !== null && CourseState::tryFrom($state) === null) {
            throw ValidationException::forField('state', 'The state must be draft, published, archived or trashed.');
        }

        if (!\in_array($sort, self::SORTS, true)) {
            throw ValidationException::forField('sort', 'Unknown sort.');
        }

        if ($categoryId !== null && $categoryId < 1) {
            throw ValidationException::forField('category_id', 'Must be a positive category id.');
        }

        $direction = $this->direction($order);
        $page = $this->requirePositive('page', $page);
        $perPage = $this->requirePerPage($perPage);

        $total = $this->courses->countRecords($restrictToIds, $state, $titleContains, $categoryId);
        $column = self::COLUMN_SORTS[$sort] ?? null;
        $offset = ($page - 1) * $perPage;

        if ($column !== null) {
            $records = $this->courses->records(
                $restrictToIds,
                $state,
                $titleContains,
                $column,
                $direction,
                $perPage,
                $offset,
                $categoryId,
            );

            return new ManagedCoursePage($this->view->forRecords($records), $total, $page, $perPage);
        }

        $ids = $this->courses->idsByCount($restrictToIds, $state, $titleContains, $sort, $direction, $perPage, $offset, $categoryId);

        return new ManagedCoursePage(
            $this->view->forRecords($this->courses->recordsByIds($ids)),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * @return list<int>|null null means every course
     */
    private function scopeFor(Principal $principal): ?array
    {
        if ($this->guard->can($principal, Capability::MANAGE)) {
            return null;
        }

        foreach (self::SURFACE_CAPABILITIES as $capability) {
            if ($this->guard->can($principal, $capability)) {
                return $this->courses->courseIdsForInstructor($principal->userId);
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw NotAuthorizedException::forCapability(Capability::TEACH);
    }

    private function direction(string $order): string
    {
        return match (strtolower($order)) {
            'asc' => Query::SORT_ASC,
            'desc' => Query::SORT_DESC,
            default => throw ValidationException::forField('order', 'The order must be asc or desc.'),
        };
    }

    private function requirePositive(string $field, int $value): int
    {
        if ($value < 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Must be 1 or more.');
        }

        return $value;
    }

    private function requirePerPage(int $perPage): int
    {
        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('per_page', sprintf('Must be between 1 and %d.', $this->policy->maxPerPage));
        }

        return $perPage;
    }
}
