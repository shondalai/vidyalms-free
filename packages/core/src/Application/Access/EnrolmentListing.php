<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentCriteria;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRecord;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Turns enrolment criteria into a page of roster rows, and owns the one
 * policy decision the rosters share: where the learner's name is allowed to
 * cost something.
 *
 * Three paths, and the difference between them is where the name comes from.
 *
 * - Sorting on a stored column filters, orders and cuts the page at the
 *   database, then resolves the names of that page in one users call. A
 *   course with ten thousand learners costs the same as one with ten.
 * - Searching resolves the term through the users port first and passes the
 *   matching ids back into the query as an IN, so a CMS-side search still
 *   ends up as a page cut at the database. This uses the port's uncapped id
 *   search rather than the deliberately capped account picker: totals and
 *   later pages therefore remain exact even for a broad search.
 * - Sorting by name is the honest exception: the order lives in the CMS, so
 *   the whole matching set has to be named before the page can be cut. Only
 *   ids cross into PHP, never records, and above the policy's name sort
 *   ceiling the request is refused with 422 rather than answered slowly. The
 *   message tells the caller to filter first, which is the only thing that
 *   can actually help.
 *
 * The ceilings are a ListingPolicy the host supplies, because they are
 * judgements about a site rather than facts about the product: a university
 * raises the name sort ceiling, a shared host lowers the page size, and
 * neither has to fork a core file to do it. The policy's separate search
 * match limit still belongs to account pickers, not persisted directories.
 *
 * Holds no authorization; every caller is a use case that already applied
 * its own rule.
 */
final class EnrolmentListing
{
    /** The sort whose order lives in the host CMS rather than in our tables. */
    public const SORT_NAME = 'name';

    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly UsersPort $users,
        private readonly ListingPolicy $policy,
    ) {
    }

    /**
     * @param list<string> $allowedSorts
     */
    public function page(
        EnrolmentCriteria $criteria,
        ?string $search,
        string $sort,
        string $order,
        int $page,
        int $perPage,
        array $allowedSorts,
    ): LearnerEnrolmentPage {
        if (!\in_array($sort, $allowedSorts, true)) {
            throw ValidationException::forField('sort', 'Unknown sort.');
        }

        $order = strtolower($order);

        if (!\in_array($order, ['asc', 'desc'], true)) {
            throw ValidationException::forField('order', 'The order must be asc or desc.');
        }

        if ($page < 1) {
            throw ValidationException::forField('page', 'Must be 1 or more.');
        }

        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('per_page', sprintf('Must be between 1 and %d.', $this->policy->maxPerPage));
        }

        $term = $search === null || trim($search) === '' ? null : trim($search);

        if ($term !== null) {
            $criteria = $criteria->narrowedToUsers($this->userIdsMatching($term));
        }

        $total = $this->enrolments->countRecords($criteria);
        $offset = ($page - 1) * $perPage;

        if ($sort === self::SORT_NAME) {
            return $this->pageByName($criteria, $order === 'desc', $page, $perPage, $offset, $total);
        }

        $records = $this->enrolments->pageOfRecords(
            $criteria,
            $sort,
            $order === 'desc' ? Query::SORT_DESC : Query::SORT_ASC,
            // "Never expires" is the absence of a date, not the largest
            // one, so it sorts last whichever way the column is ordered.
            $sort === 'expires_at',
            $perPage,
            $offset,
        );

        return new LearnerEnrolmentPage($this->resolve($records), $total, $page, $perPage);
    }

    /**
     * One users lookup for the whole page, never one per row.
     *
     * A roster row shows the learner's name, and nothing on the management
     * surface emits their email, so the port is asked for the set of names
     * it has a set-shaped method for. This used to call findById() per
     * distinct learner to fill an email field no response carried, which on
     * both hosts is a User load each: a page of a hundred was a hundred
     * reads of the CMS user table to build twenty names. If a roster is ever
     * meant to show an address, the port grows a set-shaped account lookup
     * and this asks for that; it does not go back to one call per row.
     *
     * @param list<EnrolmentRecord>   $records
     * @param array<int, string>|null $names names already resolved by the
     *                                       caller, so a sort by name that
     *                                       had to name the whole set does
     *                                       not name the page again
     *
     * @return list<LearnerEnrolment>
     */
    public function resolve(array $records, ?array $names = null): array
    {
        if ($records === []) {
            return [];
        }

        if ($names === null) {
            $userIds = [];

            foreach ($records as $record) {
                $userIds[$record->enrolment->userId] = true;
            }

            $names = $this->users->namesFor(array_keys($userIds));
        }

        $rows = [];

        foreach ($records as $record) {
            // Absent rather than null: a missing key is a CMS account that is
            // gone, which is not the same as a person with an empty name.
            $rows[] = new LearnerEnrolment($record, $names[$record->enrolment->userId] ?? null);
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function userIdsMatching(string $term): array
    {
        $ids = [];

        foreach ($this->users->idsMatching($term) as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        ksort($ids);

        return array_values($ids);
    }

    private function pageByName(
        EnrolmentCriteria $criteria,
        bool $descending,
        int $page,
        int $perPage,
        int $offset,
        int $total,
    ): LearnerEnrolmentPage {
        if ($total > $this->policy->nameSortCeiling) {
            throw ValidationException::forField('sort', sprintf(
                'Sorting %d rows by learner name needs every name from the site at once, which is refused above %d. '
                . 'Narrow the list with a filter or a search first, or sort on a stored column.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $total,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->policy->nameSortCeiling,
            ));
        }

        $identities = $this->enrolments->identities($criteria);
        $names = $this->users->namesFor(array_values(array_unique(array_map(
            static fn (array $identity): int => $identity['user_id'],
            $identities,
        ))));

        $factor = $descending ? -1 : 1;

        usort($identities, static function (array $left, array $right) use ($names, $factor): int {
            $compared = strcasecmp($names[$left['user_id']] ?? '', $names[$right['user_id']] ?? '');

            return $compared !== 0
                ? $compared * $factor
                : $left['enrolment_id'] <=> $right['enrolment_id'];
        });

        $pageIds = array_map(
            static fn (array $identity): int => $identity['enrolment_id'],
            \array_slice($identities, $offset, $perPage),
        );

        return new LearnerEnrolmentPage(
            $this->resolve($this->enrolments->recordsByIds(array_values($pageIds)), $names),
            $total,
            $page,
            $perPage,
        );
    }
}
