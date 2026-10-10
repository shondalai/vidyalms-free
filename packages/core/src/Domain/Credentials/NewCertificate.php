<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * Everything one issuance writes, in one object.
 *
 * The insert takes this rather than twenty positional arguments because the
 * row now carries provenance, lifecycle, credential and idempotency blocks
 * and a twenty-argument call is a call nobody can read and everybody gets
 * wrong by one position. It is also what lets a reissue state its
 * carry-forward set as a value rather than as a sequence of assignments.
 */
final class NewCertificate
{
    /**
     * @param array<string, mixed>|null $templateSnapshot
     * @param array<string, mixed>|null $contextSnapshot
     * @param array<string, mixed>|null $metadata
     * @param list<CertificateCredit>   $credits
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $enrolmentId,
        public readonly int $templateId,
        public readonly string $code,
        public readonly string $uuid,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?\DateTimeImmutable $validFrom = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?int $templateRevision = null,
        public readonly ?array $templateSnapshot = null,
        public readonly ?array $contextSnapshot = null,
        public readonly string $rendererKey = 'html',
        public readonly ?string $engineVersion = null,
        public readonly bool $published = false,
        public readonly ?string $recipientIdentityHash = null,
        public readonly ?string $recipientIdentitySalt = null,
        public readonly ?string $eventRef = null,
        public readonly ?string $issuerEarnerId = null,
        public readonly ?int $supersedesId = null,
        public readonly ?array $metadata = null,
        public readonly array $credits = [],
    ) {
    }

    /**
     * The enrolment key this row holds while it is live. It is the guard the
     * exactly-once issuance rule now rests on, and it is null the moment the
     * row stops being the enrolment's current certificate.
     */
    public function activeEnrolmentKey(): string
    {
        return (string) $this->enrolmentId;
    }

    /**
     * The replacement for a supersession, carrying forward exactly what the
     * design names: the completion date, the validity window unless this is a
     * renewal, the recipient identity pair, the caller's own idempotency key
     * and one copy of every credit row with its reporting date reset.
     *
     * Each of those has a failure behind it. A regenerated salt changes the
     * identity hash, so a relying party that pinned the old one can no longer
     * match the holder; completed_at would silently become the reissue date
     * on a page that labels it "Completed"; and uncopied credits vanish from
     * a compliance export that groups over active rows.
     *
     * @param array<string, mixed>|null $templateSnapshot
     * @param array<string, mixed>|null $contextSnapshot
     * @param list<CertificateCredit>   $credits
     */
    public static function supersedingReissue(
        Certificate $previous,
        string $code,
        string $uuid,
        \DateTimeImmutable $issuedAt,
        int $templateId,
        ?int $templateRevision,
        ?array $templateSnapshot,
        ?array $contextSnapshot,
        string $rendererKey,
        ?\DateTimeImmutable $expiresAt,
        ?string $eventRef,
        array $credits,
    ): self {
        return new self(
            $previous->userId,
            $previous->courseId,
            $previous->enrolmentId,
            $templateId,
            $code,
            $uuid,
            $issuedAt,
            $previous->completedAt,
            $previous->validFrom,
            $expiresAt,
            $templateRevision,
            $templateSnapshot,
            $contextSnapshot,
            $rendererKey,
            $previous->engineVersion,
            $previous->published,
            $previous->recipientIdentityHash,
            $previous->recipientIdentitySalt,
            $eventRef,
            $previous->issuerEarnerId,
            $previous->id,
            $previous->metadata,
            $credits,
        );
    }
}
