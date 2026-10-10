<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentCriteria;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRecord;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\LearnerSummary;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseEnrolmentRepository implements EnrolmentRepository
{
    private const TABLE = 'enrolments';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(int $id): ?Enrolment
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function findByUserAndCourse(int $userId, int $courseId): ?Enrolment
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        );

        return $row === null ? null : $this->map($row);
    }

    public function add(Enrolment $enrolment): Enrolment
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $id = $this->database->insert(self::TABLE, [
            'user_id' => $enrolment->userId,
            'course_id' => $enrolment->courseId,
            'entitlement_id' => $enrolment->entitlementId,
            'status' => $enrolment->status,
            'enrolled_at' => $enrolment->enrolledAt->format(self::DATETIME_FORMAT),
            'completed_at' => $enrolment->completedAt?->format(self::DATETIME_FORMAT),
            'last_activity_at' => $enrolment->lastActivityAt?->format(self::DATETIME_FORMAT),
            'progress_percent' => $enrolment->progressPercent,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $enrolment->withId($id);
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->database->update(self::TABLE, [
            'status' => $status,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    public function reactivate(int $id, int $entitlementId): void
    {
        $this->database->update(self::TABLE, [
            'status' => Enrolment::STATUS_ACTIVE,
            'entitlement_id' => $entitlementId,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    public function listForUserWithCourse(int $userId): array
    {
        return $this->withSources($userId, $this->database->selectAll($this->userCourseQuery($userId)));
    }

    public function pageForUserWithCourse(int $userId, int $limit, int $offset, ?int $courseId = null, ?int $organisationId = null): array
    {
        return $this->withSources($userId, $this->database->selectAll(
            $this->userCourseQuery($userId, $courseId, $organisationId)->limit($limit)->offset($offset)
        ));
    }

    public function countForUserWithCourse(int $userId, ?int $courseId = null, ?int $organisationId = null): int
    {
        return $this->database->count($this->userCourseQuery($userId, $courseId, $organisationId));
    }

    private function userCourseQuery(int $userId, ?int $courseId = null, ?int $organisationId = null): Query
    {
        $query = Query::from(self::TABLE, 'e')
                ->select([
                    'e.id',
                    'e.user_id',
                    'e.course_id',
                    'e.entitlement_id',
                    'e.status',
                    'e.enrolled_at',
                    'e.completed_at',
                    'e.last_activity_at',
                    'e.progress_percent',
                    'c.title AS course_title',
                    'c.slug AS course_slug',
                    // The provenance line. GDPR Article 15(1)(c) requires that
                    // a data subject be told who the recipients of their data
                    // are, and an organisation reading a learner's progress is
                    // a recipient, so naming the organisation that paid for an
                    // enrolment is free rather than a courtesy. The column is
                    // already stamped on the entitlement, so this is two left
                    // joins and no new storage.
                    'o.id AS organisation_id',
                    'o.name AS organisation_name',
                ])
                ->join('courses', 'c', 'e.course_id', 'c.id')
                // Left joins throughout: a self-purchased enrolment has no
                // organisation and must still appear, with both columns null.
                ->leftJoin('entitlements', 'ent', 'e.entitlement_id', 'ent.id')
                ->leftJoin('organisations', 'o', 'ent.organisation_id', 'o.id')
                ->where('e.user_id', '=', $userId)
                ->where('c.state', '!=', 'trashed')
                ->orderBy('e.enrolled_at', Query::SORT_DESC)
                ->orderBy('e.id', Query::SORT_DESC);
        if ($courseId !== null) {
            $query->where('e.course_id', '=', $courseId);
        }
        if ($organisationId !== null) {
            $query->join('entitlements', 'source_filter', 'e.course_id', 'source_filter.course_id', [['e.user_id', 'source_filter.user_id']]);
            if ($organisationId === 0) {
                $query->whereNull('source_filter.organisation_id');
            } else {
                $query->where('source_filter.organisation_id', '=', $organisationId);
            }
            foreach ($query->columns() as $column) {
                $query->groupBy(explode(' AS ', $column)[0]);
            }
        }
        return $query;
    }

    public function organisationsForUser(int $userId): array
    {
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $this->database->selectAll(Query::from('entitlements', 't')->select(['o.id', 'o.name'])
                ->join('organisations', 'o', 't.organisation_id', 'o.id')
                ->join('enrolments', 'e', 't.course_id', 'e.course_id', [['t.user_id', 'e.user_id']])
                ->join('courses', 'c', 't.course_id', 'c.id')->where('c.state', '!=', 'trashed')
                ->where('t.user_id', '=', $userId)->groupBy('o.id')->groupBy('o.name')->orderBy('o.name')->orderBy('o.id')));
    }

    /** One batched provenance lookup for the visible page, including secondary grants.
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withSources(int $userId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $sources = [];
        foreach ($this->database->selectAll(Query::from('entitlements', 't')
            ->select(['t.course_id', 't.organisation_id', 'o.name'])
            ->leftJoin('organisations', 'o', 't.organisation_id', 'o.id')
            ->where('t.user_id', '=', $userId)->whereIn('t.course_id', array_map(static fn (array $row): int => (int) $row['course_id'], $rows))) as $source) {
            $courseId = (int) $source['course_id'];
            $sources[$courseId] ??= ['personal' => false, 'organisations' => []];
            if ($source['organisation_id'] === null) {
                $sources[$courseId]['personal'] = true;
            } elseif ($source['name'] !== null) {
                $id = (int) $source['organisation_id'];
                $sources[$courseId]['organisations'][$id] = ['id' => $id, 'name' => (string) $source['name']];
            }
        }
        return array_map(function (array $row) use ($sources): array {
            $row = $this->normaliseListRow($row);
            $row['organisations'] = array_values($sources[$row['course_id']]['organisations'] ?? []);
            $row['personal_enrolment'] = $sources[$row['course_id']]['personal'] ?? false;
            return $row;
        }, $rows);
    }

    public function listForCourse(int $courseId, int $limit, int $offset): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('course_id', '=', $courseId)
                ->orderBy('enrolled_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC)
                ->limit($limit)
                ->offset($offset),
        );

        return array_map($this->normaliseListRow(...), $rows);
    }

    public function countForCourse(int $courseId): int
    {
        return $this->database->count(Query::from(self::TABLE)->where('course_id', '=', $courseId));
    }

    public function records(EnrolmentCriteria $criteria): array
    {
        return array_map(
            $this->mapRecord(...),
            $this->database->selectAll($this->defaultOrder($this->recordQuery($criteria))),
        );
    }

    public function countRecords(EnrolmentCriteria $criteria): int
    {
        return $this->database->count($this->recordQuery($criteria));
    }

    public function pageOfRecords(
        EnrolmentCriteria $criteria,
        string $sortColumn,
        string $direction,
        bool $nullsLast,
        int $limit,
        int $offset,
    ): array {
        $column = EnrolmentRepository::SORT_COLUMNS[$sortColumn]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a sortable enrolment column.', $sortColumn));

        $query = $this->recordQuery($criteria)
            ->orderBy($column, $direction, $nullsLast)
            ->orderBy('e.id', Query::SORT_ASC)
            ->limit($limit)
            ->offset($offset);

        return array_map($this->mapRecord(...), $this->database->selectAll($query));
    }

    public function identities(EnrolmentCriteria $criteria): array
    {
        $query = Query::from(self::TABLE, 'e')
            ->select(['e.id AS enrolment_id', 'e.user_id'])
            ->join('entitlements', 't', 'e.entitlement_id', 't.id')
            ->orderBy('e.id', Query::SORT_ASC);

        $this->narrow($query, $criteria);

        return array_map(
            static fn (array $row): array => [
                'enrolment_id' => (int) $row['enrolment_id'],
                'user_id' => (int) $row['user_id'],
            ],
            $this->database->selectAll($query),
        );
    }

    public function recordsByIds(array $enrolmentIds): array
    {
        if ($enrolmentIds === []) {
            return [];
        }

        $query = Query::from(self::TABLE, 'e')
            ->select($this->recordColumns())
            ->join('courses', 'c', 'e.course_id', 'c.id')
            ->join('entitlements', 't', 'e.entitlement_id', 't.id')
            ->whereIn('e.id', $enrolmentIds);

        $byId = [];

        foreach ($this->database->selectAll($query) as $row) {
            $byId[(int) $row['enrolment_id']] = $this->mapRecord($row);
        }

        $records = [];

        foreach ($enrolmentIds as $id) {
            if (isset($byId[$id])) {
                $records[] = $byId[$id];
            }
        }

        return $records;
    }

    public function recordsForUser(int $userId): array
    {
        return $this->records(new EnrolmentCriteria(userId: $userId));
    }

    public function recordFor(int $courseId, int $userId): ?EnrolmentRecord
    {
        $records = $this->records(new EnrolmentCriteria(courseId: $courseId, userId: $userId));

        return $records[0] ?? null;
    }

    public function facets(\DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        $expiring = $this->recordQuery(new EnrolmentCriteria(
            status: Enrolment::STATUS_ACTIVE,
            expiringAfter: $from,
            expiringBefore: $until,
        ));

        return [
            'active' => $this->database->count(
                Query::from(self::TABLE)->where('status', '=', Enrolment::STATUS_ACTIVE),
            ),
            'expiring' => $this->database->count($expiring),
            'suspended' => $this->database->count(
                Query::from(self::TABLE)->where('status', '=', Enrolment::STATUS_SUSPENDED),
            ),
        ];
    }

    public function countLearners(?array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return $this->database->count($this->learnerSummaryQuery($userIds));
    }

    public function learnerSummaries(
        ?array $userIds,
        string $sort,
        string $direction,
        int $limit,
        int $offset,
    ): array {
        if ($userIds === []) {
            return [];
        }

        $column = EnrolmentRepository::LEARNER_SORT_COLUMNS[$sort]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \InvalidArgumentException(sprintf('"%s" is not a learner-directory sort.', $sort));

        $query = $this->learnerSummaryQuery($userIds)
            ->orderBy($column, $direction, $sort === 'last_active')
            ->orderBy('e.user_id', Query::SORT_ASC)
            ->limit($limit)
            ->offset($offset);

        return array_map(
            fn (array $row): LearnerSummary => new LearnerSummary(
                (int) $row['user_id'],
                (int) $row['course_count'],
                (int) $row['active_course_count'],
                (int) $row['completed_course_count'],
                (float) $row['average_progress'],
                $this->parseOrNull($row['last_activity_at']),
                new \DateTimeImmutable((string) $row['last_enrolled_at'], new \DateTimeZone('UTC')),
            ),
            $this->database->selectAll($query),
        );
    }

    /**
     * @param list<int>|null $userIds
     */
    private function learnerSummaryQuery(?array $userIds): Query
    {
        $query = Query::from(self::TABLE, 'e')
            ->select(['e.user_id'])
            ->aggregate(
                Aggregate::count('course_count'),
                Aggregate::countWhere(
                    [Predicate::compare('e.status', '=', Enrolment::STATUS_ACTIVE)],
                    'active_course_count',
                ),
                Aggregate::countWhere(
                    [Predicate::compare('e.status', '=', Enrolment::STATUS_COMPLETED)],
                    'completed_course_count',
                ),
                Aggregate::avg('e.progress_percent', 'average_progress'),
                Aggregate::max('e.last_activity_at', 'last_activity_at'),
                Aggregate::max('e.enrolled_at', 'last_enrolled_at'),
            )
            ->groupBy('e.user_id');

        if ($userIds !== null) {
            $query->whereIn('e.user_id', $userIds);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function recordColumns(): array
    {
        return [
            'e.id AS enrolment_id',
            'e.user_id',
            'e.course_id',
            'e.entitlement_id',
            'e.status',
            'e.enrolled_at',
            'e.completed_at',
            'e.last_activity_at',
            'e.progress_percent',
            'c.title AS course_title',
            'c.slug AS course_slug',
            't.id AS ent_id',
            't.source AS ent_source',
            't.source_ref AS ent_source_ref',
            't.status AS ent_status',
            't.granted_by AS ent_granted_by',
            't.starts_at AS ent_starts_at',
            't.expires_at AS ent_expires_at',
        ];
    }

    private function recordQuery(EnrolmentCriteria $criteria): Query
    {
        $query = Query::from(self::TABLE, 'e')
            ->select($this->recordColumns())
            ->join('courses', 'c', 'e.course_id', 'c.id')
            ->join('entitlements', 't', 'e.entitlement_id', 't.id');

        return $this->narrow($query, $criteria);
    }

    private function narrow(Query $query, EnrolmentCriteria $criteria): Query
    {
        if ($criteria->courseId !== null) {
            $query->where('e.course_id', '=', $criteria->courseId);
        }

        if ($criteria->userId !== null) {
            $query->where('e.user_id', '=', $criteria->userId);
        }

        if ($criteria->userIds !== null) {
            $query->whereIn('e.user_id', $criteria->userIds);
        }

        if ($criteria->status !== null) {
            $query->where('e.status', '=', $criteria->status);
        }

        if ($criteria->expiringAfter !== null) {
            $query->where('t.expires_at', '>', $criteria->expiringAfter->format(self::DATETIME_FORMAT));
        }

        if ($criteria->expiringBefore !== null) {
            $query->where('t.expires_at', '<=', $criteria->expiringBefore->format(self::DATETIME_FORMAT));
        }

        return $query;
    }

    private function defaultOrder(Query $query): Query
    {
        return $query->orderBy('e.enrolled_at', Query::SORT_DESC)->orderBy('e.id', Query::SORT_DESC);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecord(array $row): EnrolmentRecord
    {
        $enrolment = new Enrolment(
            (int) $row['enrolment_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (int) $row['entitlement_id'],
            (string) $row['status'],
            new \DateTimeImmutable((string) $row['enrolled_at'], new \DateTimeZone('UTC')),
            $this->parseOrNull($row['completed_at']),
            $this->parseOrNull($row['last_activity_at']),
            (float) $row['progress_percent'],
        );

        $entitlement = new Entitlement(
            (int) $row['ent_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (string) $row['ent_source'],
            $row['ent_source_ref'] === null ? null : (string) $row['ent_source_ref'],
            (string) $row['ent_status'],
            $row['ent_granted_by'] === null ? null : (int) $row['ent_granted_by'],
            $this->parseOrNull($row['ent_starts_at']),
            $this->parseOrNull($row['ent_expires_at']),
        );

        return new EnrolmentRecord($enrolment, $entitlement, (string) $row['course_title'], (string) $row['course_slug']);
    }
    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Enrolment
    {
        return new Enrolment(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (int) $row['entitlement_id'],
            (string) $row['status'],
            new \DateTimeImmutable((string) $row['enrolled_at'], new \DateTimeZone('UTC')),
            $this->parseOrNull($row['completed_at']),
            $this->parseOrNull($row['last_activity_at']),
            (float) $row['progress_percent'],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function normaliseListRow(array $row): array
    {
        foreach (['id', 'user_id', 'course_id', 'entitlement_id'] as $column) {
            $row[$column] = (int) $row[$column];
        }

        $row['progress_percent'] = (float) $row['progress_percent'];

        // Present on the two reads that join the provenance and absent on the
        // ones that do not, so a caller of listForCourse() is not handed a
        // pair of nulls that would read as "nobody is paying for this".
        if (array_key_exists('organisation_id', $row)) {
            $row['organisation_id'] = $row['organisation_id'] === null ? null : (int) $row['organisation_id'];
        }

        return $row;
    }

    private function parseOrNull(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
