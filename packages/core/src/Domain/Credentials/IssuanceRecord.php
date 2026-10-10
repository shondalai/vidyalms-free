<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One issued credential, in the shape both credential types persist.
 *
 * "Badges are identical to certificates" was a resemblance until something
 * enforced it, and stated as a resemblance it was already untrue in three
 * ways. So there is one record shape here and both tables persist it:
 * revocation, expiry, verification, supersession and status list allocation
 * are then implemented once rather than four times, and a column that appears
 * on one table and not the other fails to construct rather than drifting
 * quietly.
 *
 * The provenance block is the point of the design. template_snapshot and
 * context_snapshot hold the resolved template, its pages, its elements and
 * every resolved token value at the moment of issue, so rendering reads the
 * snapshot and never the live template and correcting a template cannot
 * rewrite a credential somebody already holds. A record with no snapshot is a
 * row issued before the snapshot existed; it falls back to the live template
 * and the response says that it did.
 */
final class IssuanceRecord
{
    public const SUBJECT_CERTIFICATE = 'certificate';
    public const SUBJECT_BADGE_AWARD = 'badge_award';

    /**
     * @param array<string, mixed>|null $templateSnapshot
     * @param array<string, mixed>|null $contextSnapshot
     * @param list<CertificateCredit>   $credits
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly int $id,
        public readonly ?string $uuid,
        public readonly string $code,
        public readonly int $userId,
        public readonly ?int $courseId,
        public readonly ?int $enrolmentId,
        public readonly ?int $templateId,
        public readonly ?int $templateRevision,
        public readonly ?array $templateSnapshot,
        public readonly ?array $contextSnapshot,
        public readonly string $rendererKey,
        public readonly ?string $engineVersion,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly ?\DateTimeImmutable $validFrom,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly CredentialStatus $storedStatus,
        public readonly ?\DateTimeImmutable $revokedAt,
        public readonly ?string $revocationReason,
        public readonly ?int $supersededById,
        public readonly ?int $supersedesId,
        public readonly bool $published,
        public readonly ?string $credentialJson,
        public readonly ?string $credentialFormat,
        public readonly ?string $proofType,
        public readonly ?string $recipientIdentityHash,
        public readonly ?string $recipientIdentitySalt,
        public readonly ?string $artefactPath,
        public readonly ?string $artefactChecksum,
        public readonly ?\DateTimeImmutable $renderedAt,
        public readonly ?string $eventRef,
        public readonly ?string $issuerEarnerId,
        public readonly string $achievementType = 'CertificateOfCompletion',
        public readonly array $credits = [],
    ) {
    }

    /**
     * The status as of now, with the derived values applied under the
     * published precedence.
     */
    public function statusAt(\DateTimeImmutable $now): CredentialStatus
    {
        return CredentialStatus::resolve($this->storedStatus, $this->validFrom, $this->expiresAt, $now);
    }

    /**
     * Independent of the status, on purpose: a credential can be unexpired
     * and revoked, or expired and not revoked, and a single-valued status
     * cannot report both at once.
     */
    public function isRevoked(): bool
    {
        return $this->storedStatus === CredentialStatus::Revoked;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    /**
     * Whether this record renders from what it froze. False means the row
     * predates the snapshot and the render falls back to the live template,
     * which the response has to say.
     */
    public function hasSnapshot(): bool
    {
        return $this->templateSnapshot !== null;
    }
}
