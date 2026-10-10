<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityGrant;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Reads and writes availability_grants.
 *
 * Expiry is never swept here: an expired row stays as history and stops
 * applying at the instant it expires, which is a read-time predicate exactly
 * as it is on a certificate.
 */
final class SqlAvailabilityGrantRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Every grant this learner holds against a set of subjects, whatever the
     * scope. One read for a whole render set.
     *
     * @param list<string> $subjectTypes
     *
     * @return list<AvailabilityGrant>
     */
    public function forLearner(int $userId, array $subjectTypes): array
    {
        if ($subjectTypes === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('availability_grants')
                ->where('user_id', '=', $userId)
                ->whereIn('subject_type', $subjectTypes),
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(int $id): ?AvailabilityGrant
    {
        $row = $this->database->selectOne(Query::from('availability_grants')->where('id', '=', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * How many candidate rows one listing scans before the subject filter.
     * Grants are exceptional administrative rows rather than a per-learner
     * table, so a course with more than this many is a support conversation
     * rather than a page to render.
     */
    public const MAX_SCAN = 2000;

    /**
     * Every grant that bears on this course, whatever its scope.
     *
     * Filtering on the course scope alone made a cohort-scoped or site-scoped
     * row invisible on every screen: a deny written at cohort scope reported
     * a total of zero on the course it gated and on every other course too,
     * so the design's promise that a grant is visible in the blocked-learners
     * report and attributable held for one of the four scopes.
     *
     * The subject is the join, because a grant names a subject of this course
     * whatever scope it carries. The pair (type, id) is matched in PHP rather
     * than in SQL: the port's query builder has no grouped disjunction, and a
     * bare subject_id IN would conflate lesson 4 with quiz 4. The candidate
     * set is already narrowed by the id list, and the scan is capped.
     *
     * @param array<string, list<int>>|null $subjectIds this course's gated row ids, keyed by subject type
     *
     * @return array{items: list<AvailabilityGrant>, total: int}
     */
    public function page(int $courseId, ?int $userId, int $limit, int $offset, ?array $subjectIds = null): array
    {
        if ($subjectIds === null) {
            return $this->pageByCourseScope($courseId, $userId, $limit, $offset);
        }

        $ids = [];

        foreach ($subjectIds as $typeIds) {
            foreach ($typeIds as $id) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return ['items' => [], 'total' => 0];
        }

        $query = Query::from('availability_grants')
            ->whereIn('subject_id', array_keys($ids))
            ->orderBy('id', Query::SORT_DESC)
            ->limit(self::MAX_SCAN);

        if ($userId !== null) {
            $query->where('user_id', '=', $userId);
        }

        $matched = [];

        foreach ($this->database->selectAll($query) as $row) {
            $type = (string) ($row['subject_type'] ?? '');
            $id = (int) ($row['subject_id'] ?? 0);

            if (\in_array($id, $subjectIds[$type] ?? [], true)) {
                $matched[] = $row;
            }
        }

        return [
            'items' => array_map($this->hydrate(...), \array_slice($matched, $offset, $limit)),
            'total' => \count($matched),
        ];
    }

    /**
     * @return array{items: list<AvailabilityGrant>, total: int}
     */
    private function pageByCourseScope(int $courseId, ?int $userId, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($courseId, $userId): Query {
            $query->where('scope_type', '=', AvailabilityGrant::SCOPE_COURSE)
                ->where('scope_id', '=', $courseId);

            if ($userId !== null) {
                $query->where('user_id', '=', $userId);
            }

            return $query;
        };

        $total = $this->database->count($filter(Query::from('availability_grants')));
        $rows = $this->database->selectAll(
            $filter(Query::from('availability_grants'))
                ->orderBy('id', Query::SORT_DESC)
                ->limit($limit)
                ->offset($offset),
        );

        return ['items' => array_map($this->hydrate(...), $rows), 'total' => $total];
    }

    /**
     * One row per (scope, subject, learner): a second write to the same
     * five columns replaces the first rather than stacking two answers
     * nothing could order.
     */
    public function save(AvailabilityGrant $grant): AvailabilityGrant
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $values = [
            'mode' => $grant->mode,
            'reason' => $grant->reason,
            'granted_by' => $grant->grantedBy,
            'expires_at' => $grant->expiresAt?->format(self::DATETIME_FORMAT),
            'updated_at' => $now,
        ];

        $existing = $this->database->selectOne(
            Query::from('availability_grants')
                ->select(['id', 'created_at'])
                ->where('scope_type', '=', $grant->scopeType)
                ->where('scope_id', '=', $grant->scopeId)
                ->where('subject_type', '=', $grant->subject->type)
                ->where('subject_id', '=', $grant->subject->id)
                ->where('user_id', '=', $grant->userId),
        );

        if ($existing === null) {
            $id = $this->database->insert('availability_grants', $values + [
                'scope_type' => $grant->scopeType,
                'scope_id' => $grant->scopeId,
                'subject_type' => $grant->subject->type,
                'subject_id' => $grant->subject->id,
                'user_id' => $grant->userId,
                'created_at' => $now,
            ]);
            $createdAt = $this->clock->now();
        } else {
            $id = (int) $existing['id'];
            $this->database->update('availability_grants', $values, ['id' => $id]);
            $rawCreated = $existing['created_at'] ?? null;
            $createdAt = \is_string($rawCreated) && $rawCreated !== ''
                ? new \DateTimeImmutable($rawCreated, new \DateTimeZone('UTC'))
                : $this->clock->now();
        }

        return new AvailabilityGrant(
            $id,
            $grant->scopeType,
            $grant->scopeId,
            $grant->subject,
            $grant->userId,
            $grant->mode,
            $grant->reason,
            $grant->grantedBy,
            $grant->expiresAt,
            $createdAt,
        );
    }

    public function delete(int $id): bool
    {
        return $this->database->delete('availability_grants', ['id' => $id]) > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AvailabilityGrant
    {
        return new AvailabilityGrant(
            (int) $row['id'],
            (string) $row['scope_type'],
            (int) $row['scope_id'],
            new GateSubject((string) $row['subject_type'], (int) $row['subject_id']),
            (int) $row['user_id'],
            (string) $row['mode'],
            $row['reason'] === null ? null : (string) $row['reason'],
            (int) $row['granted_by'],
            $this->instant($row['expires_at'] ?? null),
            $this->instant($row['created_at'] ?? null),
        );
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }
}
