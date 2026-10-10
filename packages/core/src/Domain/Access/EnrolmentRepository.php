<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

interface EnrolmentRepository
{
    /**
     * Public learner-directory sort keys mapped to grouped query aliases.
     */
    public const LEARNER_SORT_COLUMNS = [
        'last_active' => 'last_activity_at',
        'enrolled_at' => 'last_enrolled_at',
        'progress' => 'average_progress',
        'courses' => 'course_count',
    ];

    public function find(int $id): ?Enrolment;

    public function findByUserAndCourse(int $userId, int $courseId): ?Enrolment;

    /**
     * Persists a new enrolment and returns it with its generated id.
     */
    public function add(Enrolment $enrolment): Enrolment;

    public function updateStatus(int $id, string $status): void;

    /**
     * Flips the enrolment back to active under a new covering entitlement.
     */
    public function reactivate(int $id, int $entitlementId): void;

    /**
     * Enrolment rows joined with the course title and slug, newest first.
     * Row keys: id, user_id, course_id, entitlement_id, status, enrolled_at,
     * completed_at, last_activity_at, progress_percent, course_title,
     * course_slug.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUserWithCourse(int $userId): array;

    /** @return list<array<string, mixed>> One SQL page, newest enrolment/id first. */
    public function pageForUserWithCourse(int $userId, int $limit, int $offset, ?int $courseId = null, ?int $organisationId = null): array;

    public function countForUserWithCourse(int $userId, ?int $courseId = null, ?int $organisationId = null): int;

    /** @return list<array{id: int, name: string}> */
    public function organisationsForUser(int $userId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCourse(int $courseId, int $limit, int $offset): array;

    public function countForCourse(int $courseId): int;

    /**
     * The sortable stored columns of a roster, mapped to the column the
     * repository orders on. "expires_at" lives on the entitlement, which is
     * why the map and not the caller decides.
     */
    public const SORT_COLUMNS = [
        'enrolled_at' => 'e.enrolled_at',
        'progress' => 'e.progress_percent',
        'last_active' => 'e.last_activity_at',
        'status' => 'e.status',
        'expires_at' => 't.expires_at',
    ];

    /**
     * Enrolments with their entitlement and course, filtered at the database
     * and returned whole, newest enrolment first.
     *
     * Reserved for callers that genuinely need every row (one learner's
     * record, a course-wide recompute). A screen pages with pageOfRecords().
     *
     * @return list<EnrolmentRecord> newest enrolment first
     */
    public function records(EnrolmentCriteria $criteria): array;

    public function countRecords(EnrolmentCriteria $criteria): int;

    /**
     * One page of the same listing, ordered and cut at the database.
     *
     * $sortColumn is a key of SORT_COLUMNS. $nullsLast puts rows without a
     * value after every row that has one in both directions, which is what
     * "never expires" means in a list sorted by expiry.
     *
     * @return list<EnrolmentRecord>
     */
    public function pageOfRecords(
        EnrolmentCriteria $criteria,
        string $sortColumn,
        string $direction,
        bool $nullsLast,
        int $limit,
        int $offset,
    ): array;

    /**
     * Just the identities of the matching set: two integers a row, no join
     * to the course and no entitlement.
     *
     * This is what a sort by learner name reads. The name lives in the CMS,
     * so the order cannot be decided at the database; reading ids rather
     * than records means the set that has to cross into PHP is scalars, and
     * only the page is then hydrated.
     *
     * @return list<array{enrolment_id: int, user_id: int}>
     */
    public function identities(EnrolmentCriteria $criteria): array;

    /**
     * @param list<int> $enrolmentIds
     *
     * @return list<EnrolmentRecord> in the order the ids were given
     */
    public function recordsByIds(array $enrolmentIds): array;

    /**
     * @return list<EnrolmentRecord> newest first, every state
     */
    public function recordsForUser(int $userId): array;

    public function recordFor(int $courseId, int $userId): ?EnrolmentRecord;

    /**
     * Site-wide counts for the admin filter chips, unaffected by the current
     * filters. "expiring" counts active enrolments whose covering entitlement
     * expires inside the window.
     *
     * @return array{active: int, expiring: int, suspended: int}
     */
    public function facets(\DateTimeImmutable $from, \DateTimeImmutable $until): array;

    /**
     * Exact number of distinct learner ids after the optional host-account
     * search has been intersected. Null means all; an empty list means none.
     *
     * @param list<int>|null $userIds
     */
    public function countLearners(?array $userIds): int;

    /**
     * One database-paged row per distinct enrolled user. Aggregates are
     * ordered before paging and user id is the stable final tie-break.
     *
     * @param list<int>|null $userIds
     *
     * @return list<LearnerSummary>
     */
    public function learnerSummaries(
        ?array $userIds,
        string $sort,
        string $direction,
        int $limit,
        int $offset,
    ): array;
}
