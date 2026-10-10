<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * The exact, distinct learner directory behind GET /admin/learners.
 *
 * A learner is a host user id present on at least one enrolment, not any CMS
 * account an administrator could potentially enrol. Search begins in the
 * host because names and email live there, then the learning database owns
 * intersection, aggregate ordering and paging. Only the final page is
 * hydrated, through one set-shaped account lookup.
 */
final class ListLearners
{
    public const SORTS = ['last_active', 'enrolled_at', 'progress', 'courses'];

    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
        private readonly ListingPolicy $policy,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $search = null,
        string $sort = 'last_active',
        string $order = 'desc',
        int $page = 1,
        int $perPage = 20,
    ): AdminLearnerPage {
        $this->guard->require($principal, Capability::MANAGE);

        if (!\in_array($sort, self::SORTS, true)) {
            throw ValidationException::forField('sort', 'Unknown sort.');
        }

        $direction = match (strtolower($order)) {
            'asc' => Query::SORT_ASC,
            'desc' => Query::SORT_DESC,
            default => throw ValidationException::forField('order', 'The order must be asc or desc.'),
        };

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

        $term = $search === null || trim($search) === '' ? null : trim($search);
        $userIds = $term === null ? null : $this->normaliseIds($this->users->idsMatching($term));
        $total = $this->enrolments->countLearners($userIds);
        $summaries = $this->enrolments->learnerSummaries(
            $userIds,
            $sort,
            $direction,
            $perPage,
            ($page - 1) * $perPage,
        );
        $accounts = $this->users->accountsFor(array_map(
            static fn ($summary): int => $summary->userId,
            $summaries,
        ));
        $items = [];

        foreach ($summaries as $summary) {
            $items[] = new AdminLearner($summary, $accounts[$summary->userId] ?? null);
        }

        return new AdminLearnerPage($items, $total, $page, $perPage);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function normaliseIds(array $ids): array
    {
        $normalised = [];

        foreach ($ids as $id) {
            if ($id > 0) {
                $normalised[$id] = $id;
            }
        }

        ksort($normalised);

        return array_values($normalised);
    }
}
