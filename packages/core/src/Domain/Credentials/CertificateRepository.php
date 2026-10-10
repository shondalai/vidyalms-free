<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

interface CertificateRepository
{
    public function findById(int $id): ?Certificate;

    /**
     * The enrolment's current certificate, filtered by status rather than by
     * whichever row the database returned first.
     *
     * This replaced findByEnrolmentId(), which was a first-row-wins lookup
     * that was correct only while a unique index made a second row
     * impossible. Supersession writes a second row for the same enrolment, so
     * the unfiltered form would have started resolving a learner's download
     * and the verification lookup to an arbitrary one.
     */
    public function findActiveByEnrolmentId(int $enrolmentId): ?Certificate;

    /**
     * Releases the exactly-once key from a row that no longer holds it under
     * the clock.
     *
     * Expiry is derived and never stored, so no write can release the key
     * when a credential lapses: without this a completed enrolment whose
     * certificate expired kept the key for ever and could never be issued a
     * replacement. It writes the key column and never the status column,
     * because expired stays a clock answer.
     */
    public function releaseLapsedEnrolmentKey(int $enrolmentId, \DateTimeImmutable $now): void;

    /**
     * Every certificate this enrolment has ever held, newest first, including
     * the superseded and the revoked. The audit question the active lookup
     * deliberately cannot answer.
     *
     * @return list<Certificate>
     */
    public function historyForEnrolment(int $enrolmentId): array;

    public function findByCode(string $code): ?Certificate;

    /**
     * The credential id rather than the human code: the code is printed and
     * retyped, the uuid is embedded in a credential and dereferenced.
     */
    public function findByUuid(string $uuid): ?Certificate;

    public function codeExists(string $code): bool;

    /**
     * @return list<Certificate> newest first
     */
    public function findByUserId(int $userId): array;

    public function insert(NewCertificate $draft): Certificate;

    /**
     * Revocation is permanent and carries a reason: Open Badges 2.0 documents
     * the vocabulary (issued in error, violation of conditions, award
     * reconsidered) and a revocation with no reason is an answer a relying
     * party cannot act on. It also clears the active enrolment key, because a
     * revoked certificate no longer holds the enrolment against a
     * replacement.
     */
    public function markRevoked(int $certificateId, \DateTimeImmutable $revokedAt, ?string $reason = null): void;

    /**
     * A reversible hold, which is what a payment dispute, a plagiarism
     * investigation or a lapsed registration actually needs and what a
     * boolean revocation date cannot express. It keeps the active enrolment
     * key, because a suspended certificate does not entitle the holder to a
     * second one.
     */
    public function markSuspended(int $certificateId, ?string $reason, \DateTimeImmutable $now): void;

    public function markReinstated(int $certificateId, \DateTimeImmutable $now): void;

    /**
     * Nothing is mutated beyond the lifecycle fields: the old row keeps its
     * code, its snapshot and its credits, and the old verification code keeps
     * resolving to a page that says it was replaced and links onward.
     *
     * The supersession link is always written; the status is passed in by the
     * use case, because the published precedence puts revoked above
     * superseded and only the caller knows what the row said before. Writing
     * superseded over a revocation would answer "replaced" to a relying party
     * asking about a credential that was withdrawn, and the two are
     * materially different answers.
     *
     * Both keys the row holds against a replacement are released here: the
     * enrolment key and the issuer-supplied earner id. Both carry a unique
     * index and both are in the reissue carry-forward set, so a replacement
     * carrying either could not be inserted while the predecessor still held
     * it.
     */
    public function markSuperseded(
        int $certificateId,
        int $supersededById,
        \DateTimeImmutable $now,
        CredentialStatus $status = CredentialStatus::Superseded,
    ): void;

    /**
     * Records where the rendered artefact landed, which the column could not
     * do at all: the task wrote the document and discarded the returned path,
     * so the learner had no download and the View action had nothing to point
     * at.
     */
    public function markRendered(
        int $certificateId,
        string $path,
        string $checksum,
        \DateTimeImmutable $renderedAt,
        ?string $rendererKey = null,
    ): void;

    /**
     * The credential as issued, stored from day one even though the free
     * build signs nothing, so adding a signed format is a registration and
     * never a migration.
     */
    public function storeCredential(
        int $certificateId,
        string $credentialJson,
        string $format,
        ?string $proofType = null,
    ): void;

    /**
     * The bulk reissue selection predicate, and it is part of the design
     * rather than an implementation detail: template, active, and a revision
     * below the target. It is idempotent under restart because a reissued row
     * already carries the new revision and is skipped. Enumerating every
     * certificate for the template instead would re-select the superseded
     * rows a previous run produced.
     *
     * @return list<int>
     */
    public function idsForReissue(int $templateId, int $revision, int $limit, int $afterId = 0): array;

    /**
     * The sortable stored columns of the certificate list.
     */
    public const SORT_COLUMNS = ['issued_at', 'expires_at', 'revoked_at'];

    /**
     * Every certificate on the site, newest first, optionally narrowed to one
     * course.
     *
     * Reserved for callers that genuinely need every row; a screen pages
     * with pageOf().
     *
     * @return list<Certificate>
     */
    public function listAll(?int $courseId = null): array;

    public function countRecords(CertificateCriteria $criteria): int;

    /**
     * One page of the site-wide list, filtered, ordered and cut at the
     * database. $sortColumn is one of SORT_COLUMNS.
     *
     * @return list<Certificate>
     */
    public function pageOf(
        CertificateCriteria $criteria,
        string $sortColumn,
        string $direction,
        int $limit,
        int $offset,
    ): array;

    public function countRevoked(): int;

    public function countAll(): int;
}
