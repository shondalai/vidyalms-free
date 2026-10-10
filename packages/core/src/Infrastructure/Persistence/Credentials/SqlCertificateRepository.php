<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCriteria;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\NewCertificate;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SqlCertificateRepository implements CertificateRepository
{
    private const TABLE = 'certificates';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function findById(int $id): ?Certificate
    {
        return $this->findOneBy('id', $id);
    }

    /**
     * The key column answers uniqueness, and the clock answers the question.
     *
     * Expiry is derived and never stored, so no write can release the key
     * when a certificate lapses: the row keeps holding the enrolment for
     * ever, this lookup kept returning it, and the learner could never be
     * issued a replacement. The domain already knows which statuses hold the
     * key, so the predicate narrows the read and the status decides.
     */
    public function findActiveByEnrolmentId(int $enrolmentId): ?Certificate
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('active_enrolment_key', '=', (string) $enrolmentId),
        );

        if ($row === null) {
            return null;
        }

        $certificate = $this->map($row);

        return $certificate->statusAt($this->clock->now())->holdsActiveKey() ? $certificate : null;
    }

    public function releaseLapsedEnrolmentKey(int $enrolmentId, \DateTimeImmutable $now): void
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('active_enrolment_key', '=', (string) $enrolmentId),
        );

        if ($row === null) {
            return;
        }

        $certificate = $this->map($row);

        if ($certificate->statusAt($now)->holdsActiveKey()) {
            return;
        }

        // The column's own contract is that it is null for superseded,
        // revoked and expired rows. Expiry is derived, so no write ever put
        // it there; this is the write that makes the column say what it
        // claims. The status column is untouched, because expired stays a
        // clock answer and never a record.
        $this->database->update(self::TABLE, [
            'active_enrolment_key' => null,
            'updated_at' => $now->format(self::DATETIME),
        ], ['id' => $certificate->id]);
    }

    public function historyForEnrolment(int $enrolmentId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('enrolment_id', '=', $enrolmentId)
                ->orderBy('issued_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC),
        );

        return array_map($this->map(...), $rows);
    }

    public function findByCode(string $code): ?Certificate
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('code', '=', $code));

        return $row === null ? null : $this->map($row);
    }

    public function findByUuid(string $uuid): ?Certificate
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('uuid', '=', $uuid));

        return $row === null ? null : $this->map($row);
    }

    public function codeExists(string $code): bool
    {
        return $this->database->count(Query::from(self::TABLE)->where('code', '=', $code)) > 0;
    }

    public function findByUserId(int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('user_id', '=', $userId)
                ->orderBy('issued_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC),
        );

        return array_map($this->map(...), $rows);
    }

    public function insert(NewCertificate $draft): Certificate
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $id = $this->database->insert(self::TABLE, [
            'user_id' => $draft->userId,
            'course_id' => $draft->courseId,
            'enrolment_id' => $draft->enrolmentId,
            'template_id' => $draft->templateId,
            'template_revision' => $draft->templateRevision,
            'template_snapshot' => $this->encode($draft->templateSnapshot),
            'context_snapshot' => $this->encode($draft->contextSnapshot),
            'renderer_key' => $draft->rendererKey,
            'engine_version' => $draft->engineVersion,
            'code' => $draft->code,
            'uuid' => $draft->uuid,
            'issued_at' => $draft->issuedAt->format(self::DATETIME),
            'completed_at' => $draft->completedAt?->format(self::DATETIME),
            'valid_from' => $draft->validFrom?->format(self::DATETIME),
            'expires_at' => $draft->expiresAt?->format(self::DATETIME),
            'status' => CredentialStatus::Active->value,
            // The exactly-once guard. A replayed CourseCompleted or a
            // concurrent issuance loses this unique index and finds the row
            // that won, which is what the retry above it depends on.
            'active_enrolment_key' => $draft->activeEnrolmentKey(),
            'published' => $draft->published ? 1 : 0,
            'supersedes_id' => $draft->supersedesId,
            'recipient_identity_hash' => $draft->recipientIdentityHash,
            'recipient_identity_salt' => $draft->recipientIdentitySalt,
            'event_ref' => $draft->eventRef,
            'issuer_earner_id' => $draft->issuerEarnerId,
            'artefact_path' => null,
            'revoked_at' => null,
            'metadata' => $this->encode($draft->metadata),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findById($id) ?? throw new \RuntimeException('The certificate insert returned no row.');
    }

    public function markRevoked(int $certificateId, \DateTimeImmutable $revokedAt, ?string $reason = null): void
    {
        $this->database->update(self::TABLE, [
            'status' => CredentialStatus::Revoked->value,
            'revoked_at' => $revokedAt->format(self::DATETIME),
            'revocation_reason' => $reason,
            // Released, because a revoked certificate no longer holds the
            // enrolment against the replacement the learner may be entitled
            // to.
            'active_enrolment_key' => null,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $certificateId]);
    }

    public function markSuspended(int $certificateId, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->database->update(self::TABLE, [
            'status' => CredentialStatus::Suspended->value,
            'revocation_reason' => $reason,
            'updated_at' => $now->format(self::DATETIME),
        ], ['id' => $certificateId]);
    }

    public function markReinstated(int $certificateId, \DateTimeImmutable $now): void
    {
        $this->database->update(self::TABLE, [
            'status' => CredentialStatus::Active->value,
            'revocation_reason' => null,
            'updated_at' => $now->format(self::DATETIME),
        ], ['id' => $certificateId]);
    }

    public function markSuperseded(
        int $certificateId,
        int $supersededById,
        \DateTimeImmutable $now,
        CredentialStatus $status = CredentialStatus::Superseded,
    ): void {
        $this->database->update(self::TABLE, [
            'status' => $status->value,
            'superseded_by_id' => $supersededById,
            // Both unique keys the predecessor holds against a replacement.
            // The replacement carries the same issuer_earner_id forward, and
            // that column is unique where it is not null, so leaving it on
            // the superseded row makes every reissue of an imported
            // credential fail on the index.
            'active_enrolment_key' => null,
            'issuer_earner_id' => null,
            'updated_at' => $now->format(self::DATETIME),
        ], ['id' => $certificateId]);
    }

    public function markRendered(
        int $certificateId,
        string $path,
        string $checksum,
        \DateTimeImmutable $renderedAt,
        ?string $rendererKey = null,
    ): void {
        $values = [
            'artefact_path' => $path,
            'artefact_checksum' => $checksum,
            'rendered_at' => $renderedAt->format(self::DATETIME),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ];

        if ($rendererKey !== null) {
            $values['renderer_key'] = $rendererKey;
        }

        $this->database->update(self::TABLE, $values, ['id' => $certificateId]);
    }

    public function storeCredential(
        int $certificateId,
        string $credentialJson,
        string $format,
        ?string $proofType = null,
    ): void {
        $this->database->update(self::TABLE, [
            'credential_json' => $credentialJson,
            'credential_format' => $format,
            'proof_type' => $proofType,
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $certificateId]);
    }

    public function idsForReissue(int $templateId, int $revision, int $limit, int $afterId = 0): array
    {
        $query = Query::from(self::TABLE)
            ->select(['id'])
            ->where('template_id', '=', $templateId)
            ->where('status', '=', CredentialStatus::Active->value)
            ->where('id', '>', $afterId)
            ->whereAnyOf(
                Predicate::isNull('template_revision'),
                Predicate::compare('template_revision', '<', $revision),
            )
            ->orderBy('id', Query::SORT_ASC)
            ->limit($limit);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->selectAll($query),
        );
    }

    public function listAll(?int $courseId = null): array
    {
        $query = Query::from(self::TABLE)
            ->orderBy('issued_at', Query::SORT_DESC)
            ->orderBy('id', Query::SORT_DESC);

        if ($courseId !== null) {
            $query->where('course_id', '=', $courseId);
        }

        return array_map($this->map(...), $this->database->selectAll($query));
    }

    public function countRecords(CertificateCriteria $criteria): int
    {
        return $this->database->count($this->criteriaQuery($criteria));
    }

    public function pageOf(
        CertificateCriteria $criteria,
        string $sortColumn,
        string $direction,
        int $limit,
        int $offset,
    ): array {
        if (!\in_array($sortColumn, CertificateRepository::SORT_COLUMNS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('"%s" is not a sortable certificate column.', $sortColumn));
        }

        $query = $this->criteriaQuery($criteria)
            ->orderBy($sortColumn, $direction)
            ->orderBy('id', $direction)
            ->limit($limit)
            ->offset($offset);

        return array_map($this->map(...), $this->database->selectAll($query));
    }

    /**
     * The two derived statuses are the clock's answer and never a column, so
     * a filter on "expired" is a predicate over expires_at and not a lookup
     * of rows somebody rewrote. Judging it here is what lets an expiry that
     * passed a minute ago show as expired with no row having been touched,
     * and it is why a clock change can never leave the list stale.
     */
    private function criteriaQuery(CertificateCriteria $criteria): Query
    {
        $query = Query::from(self::TABLE);
        $now = $criteria->asOf->format(self::DATETIME);
        $active = CredentialStatus::Active->value;

        if ($criteria->courseId !== null) {
            $query->where('course_id', '=', $criteria->courseId);
        }

        if ($criteria->templateId !== null) {
            $query->where('template_id', '=', $criteria->templateId);
        }

        switch ($criteria->status) {
            case CredentialStatus::Revoked->value:
                $query->where('status', '=', CredentialStatus::Revoked->value);
                break;
            case CredentialStatus::Suspended->value:
                $query->where('status', '=', CredentialStatus::Suspended->value);
                break;
            case CredentialStatus::Superseded->value:
                $query->where('status', '=', CredentialStatus::Superseded->value);
                break;
            case CredentialStatus::Expired->value:
                $query->where('status', '=', $active)
                    ->where('expires_at', '<=', $now)
                    // Not-yet-valid precedes expired when a malformed or
                    // imported validity window has both conditions true.
                    ->whereAnyOf(
                        Predicate::isNull('valid_from'),
                        Predicate::compare('valid_from', '<=', $now),
                    );
                break;
            case CredentialStatus::NotYetValid->value:
                $query->where('status', '=', $active)->where('valid_from', '>', $now);
                break;
            case CredentialStatus::Active->value:
            case CertificateCriteria::STATUS_VALID:
                $query->where('status', '=', $active)
                    ->whereAnyOf(
                        Predicate::isNull('expires_at'),
                        Predicate::compare('expires_at', '>', $now),
                    )
                    ->whereAnyOf(
                        Predicate::isNull('valid_from'),
                        Predicate::compare('valid_from', '<=', $now),
                    );
                break;
        }

        $search = [];

        if ($criteria->code !== null) {
            $search[] = Predicate::compare('code', '=', $criteria->code);
        }

        if ($criteria->userIds !== null) {
            $search[] = Predicate::in('user_id', $criteria->userIds);
        }

        if ($search !== []) {
            $query->whereAnyOf(...$search);
        }

        return $query;
    }

    /**
     * Every revocation this site has made, not every row currently reading
     * revoked.
     *
     * It counts `revoked_at` rather than `status` because it sits beside
     * `countAll()` on the dashboard, and the contract calls that one "every
     * certificate ever issued, revoked ones included": a lifetime figure
     * against a point-in-time one would be two different questions under one
     * heading. A certificate revoked and then replaced stores `superseded` in
     * `status`, because supersession is the later fact, so counting by status
     * quietly loses the revocation the moment a correction is issued, which is
     * exactly when an administrator most wants to see it.
     */
    public function countRevoked(): int
    {
        return $this->database->count(Query::from(self::TABLE)->whereNotNull('revoked_at'));
    }

    public function countAll(): int
    {
        return $this->database->count(Query::from(self::TABLE));
    }

    private function findOneBy(string $column, int $value): ?Certificate
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where($column, '=', $value));

        return $row === null ? null : $this->map($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Certificate
    {
        return new Certificate(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (int) $row['enrolment_id'],
            (int) $row['template_id'],
            (string) $row['code'],
            $this->dateTime((string) $row['issued_at']),
            $this->nullableDateTime($row['expires_at'] ?? null),
            $this->nullableString($row['artefact_path'] ?? null),
            $this->nullableDateTime($row['revoked_at'] ?? null),
            $this->decodeJson($row['metadata'] ?? null),
            CredentialStatus::fromStored($this->nullableString($row['status'] ?? null)),
            $this->nullableString($row['uuid'] ?? null),
            $this->nullableDateTime($row['completed_at'] ?? null),
            $this->nullableDateTime($row['valid_from'] ?? null),
            $this->nullableString($row['active_enrolment_key'] ?? null),
            $this->nullableString($row['revocation_reason'] ?? null),
            $this->nullableInt($row['superseded_by_id'] ?? null),
            $this->nullableInt($row['supersedes_id'] ?? null),
            (int) ($row['published'] ?? 0) === 1,
            $this->nullableInt($row['template_revision'] ?? null),
            $this->decodeJson($row['template_snapshot'] ?? null),
            $this->decodeJson($row['context_snapshot'] ?? null),
            $this->nullableString($row['renderer_key'] ?? null) ?? 'html',
            $this->nullableString($row['engine_version'] ?? null),
            $this->nullableString($row['artefact_checksum'] ?? null),
            $this->nullableDateTime($row['rendered_at'] ?? null),
            $this->nullableString($row['credential_json'] ?? null),
            $this->nullableString($row['credential_format'] ?? null),
            $this->nullableString($row['proof_type'] ?? null),
            $this->nullableString($row['recipient_identity_hash'] ?? null),
            $this->nullableString($row['recipient_identity_salt'] ?? null),
            $this->nullableString($row['event_ref'] ?? null),
            $this->nullableString($row['issuer_earner_id'] ?? null),
        );
    }

    private function dateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private function nullableDateTime(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== '' ? $this->dateTime($value) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function encode(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
