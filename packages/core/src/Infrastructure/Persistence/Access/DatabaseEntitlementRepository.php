<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgEntitlementBoundary;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseEntitlementRepository implements EntitlementRepository
{
    private const TABLE = 'entitlements';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(int $id): ?Entitlement
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function findBySourceRef(string $source, string $sourceRef): ?Entitlement
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('source', '=', $source)
                ->where('source_ref', '=', $sourceRef),
        );

        return $row === null ? null : $this->map($row);
    }

    public function findBySourceRefDigest(string $digest): ?Entitlement
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('source_ref_digest', '=', $digest),
        );

        return $row === null ? null : $this->map($row);
    }

    public function add(Entitlement $entitlement): Entitlement
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $id = $this->database->insert(self::TABLE, [
            'user_id' => $entitlement->userId,
            'course_id' => $entitlement->courseId,
            'source' => $entitlement->source,
            'source_ref' => $entitlement->sourceRef,
            // Written inside the insert and never in a second statement. The
            // unique index is on this column, so a digest written afterwards
            // leaves the row unprotected for the width of the window between
            // the two writes, which is exactly the race the index was added
            // to close.
            'source_ref_digest' => SourceRef::digest($entitlement->source, $entitlement->sourceRef),
            'organisation_id' => $entitlement->organisationId,
            'status' => $entitlement->status,
            'granted_by' => $entitlement->grantedBy,
            'starts_at' => $this->formatOrNull($entitlement->startsAt),
            'expires_at' => $this->formatOrNull($entitlement->expiresAt),
            'revoked_reason' => $entitlement->revokedReason,
            'revoked_ref' => $entitlement->revokedRef,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $entitlement->withId($id);
    }

    public function updateStatus(int $id, string $status, ?string $reason = null, ?string $ref = null): void
    {
        $values = [
            'status' => $status,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ];

        // Written only when given, so a status change that carries no reason
        // cannot erase one an earlier revocation recorded.
        if ($reason !== null) {
            $values['revoked_reason'] = $reason;
        }

        if ($ref !== null) {
            $values['revoked_ref'] = $ref;
        }

        $this->database->update(self::TABLE, $values, ['id' => $id]);
    }

    public function expireIfStillDue(Entitlement $entitlement, \DateTimeImmutable $moment): bool
    {
        if (!$entitlement->hasId() || $entitlement->expiresAt === null || $entitlement->expiresAt > $moment) {
            return false;
        }

        // The expiry is in the WHERE, not only the id: this is a
        // compare-and-swap against the row as it was read. MySQL counts
        // changed rows rather than matched ones, and active to expired always
        // changes the column, so one affected row means this update won.
        $affected = $this->database->update(self::TABLE, [
            'status' => Entitlement::STATUS_EXPIRED,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], [
            'id' => $entitlement->id(),
            'status' => Entitlement::STATUS_ACTIVE,
            'expires_at' => $entitlement->expiresAt->format(self::DATETIME_FORMAT),
        ]);

        return $affected > 0;
    }

    public function updateExpiry(int $id, ?\DateTimeImmutable $expiresAt, string $status): void
    {
        $this->database->update(self::TABLE, [
            'expires_at' => $this->formatOrNull($expiresAt),
            'status' => $status,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    public function activeForUserAndCourse(int $userId, int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                ->where('status', '=', Entitlement::STATUS_ACTIVE)
                ->orderBy('id'),
        );

        return array_map($this->map(...), OrgEntitlementBoundary::retain(
            $this->database,
            $rows,
            $this->clock->now()->format(self::DATETIME_FORMAT)
        ));
    }

    public function activeExpiringBy(\DateTimeImmutable $moment): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('status', '=', Entitlement::STATUS_ACTIVE)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $moment->format(self::DATETIME_FORMAT))
                ->orderBy('id'),
        );

        return array_map($this->map(...), $rows);
    }

    public function activeScheduledBy(\DateTimeImmutable $moment): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('status', '=', Entitlement::STATUS_ACTIVE)
                ->whereNotNull('starts_at')
                ->where('starts_at', '<=', $moment->format(self::DATETIME_FORMAT))
                ->orderBy('id'),
        );

        return array_map($this->map(...), OrgEntitlementBoundary::retain(
            $this->database,
            $rows,
            $moment->format(self::DATETIME_FORMAT)
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Entitlement
    {
        return new Entitlement(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (string) $row['source'],
            $row['source_ref'] === null ? null : (string) $row['source_ref'],
            (string) $row['status'],
            $row['granted_by'] === null ? null : (int) $row['granted_by'],
            $this->parseOrNull($row['starts_at']),
            $this->parseOrNull($row['expires_at']),
            isset($row['organisation_id']) ? (int) $row['organisation_id'] : null,
            isset($row['revoked_reason']) ? (string) $row['revoked_reason'] : null,
            isset($row['revoked_ref']) ? (string) $row['revoked_ref'] : null,
        );
    }

    private function formatOrNull(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format(self::DATETIME_FORMAT);
    }

    private function parseOrNull(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
