<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One issued course certificate.
 *
 * Nothing is ever mutated or deleted beyond the lifecycle fields, so the
 * verification trail stays intact: a correction is a new row with a new
 * snapshot and a new code, the old row becomes superseded and keeps
 * resolving to a page that says so and links onward. That is Credly's
 * replaced state, which is the nearest correct answer in the market, and it
 * is why the exactly-once guard sits on the nullable active_enrolment_key
 * rather than on a unique enrolment_id: the unique index made supersession
 * structurally impossible, and dropping it without a replacement would let
 * two concurrent replays each insert and leave the learner's download
 * resolving to an arbitrary row.
 *
 * The provenance block is the point. template_snapshot and context_snapshot
 * hold what was resolved at issue, so correcting a template cannot rewrite a
 * certificate somebody already holds. A row with no snapshot predates the
 * change, renders from the live template, and says so.
 */
final class Certificate
{
    /**
     * @param array<string, mixed>|null $templateSnapshot the frozen template, its pages and its elements
     * @param array<string, mixed>|null $contextSnapshot  the frozen resolved token values
     * @param array<string, mixed>|null $metadata
     * @param list<CertificateCredit>   $credits
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $enrolmentId,
        public readonly int $templateId,
        public readonly string $code,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?string $artefactPath = null,
        public readonly ?\DateTimeImmutable $revokedAt = null,
        public readonly ?array $metadata = null,
        public readonly CredentialStatus $storedStatus = CredentialStatus::Active,
        public readonly ?string $uuid = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?\DateTimeImmutable $validFrom = null,
        public readonly ?string $activeEnrolmentKey = null,
        public readonly ?string $revocationReason = null,
        public readonly ?int $supersededById = null,
        public readonly ?int $supersedesId = null,
        public readonly bool $published = false,
        public readonly ?int $templateRevision = null,
        public readonly ?array $templateSnapshot = null,
        public readonly ?array $contextSnapshot = null,
        public readonly string $rendererKey = 'html',
        public readonly ?string $engineVersion = null,
        public readonly ?string $artefactChecksum = null,
        public readonly ?\DateTimeImmutable $renderedAt = null,
        public readonly ?string $credentialJson = null,
        public readonly ?string $credentialFormat = null,
        public readonly ?string $proofType = null,
        public readonly ?string $recipientIdentityHash = null,
        public readonly ?string $recipientIdentitySalt = null,
        public readonly ?string $eventRef = null,
        public readonly ?string $issuerEarnerId = null,
        public readonly array $credits = [],
    ) {
    }

    /**
     * Revoked is a stored decision and stays true whatever the clock does
     * afterwards, which is why it is reported as its own boolean beside the
     * status: revoked and expired are independent axes and a single-valued
     * status cannot carry both.
     */
    public function isRevoked(): bool
    {
        return $this->storedStatus === CredentialStatus::Revoked || $this->revokedAt !== null;
    }

    public function isSuspended(): bool
    {
        return $this->storedStatus === CredentialStatus::Suspended;
    }

    public function isSuperseded(): bool
    {
        return $this->storedStatus === CredentialStatus::Superseded;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function statusAt(\DateTimeImmutable $now): CredentialStatus
    {
        return CredentialStatus::resolve($this->storedStatus, $this->validFrom, $this->expiresAt, $now);
    }

    public function belongsTo(int $userId): bool
    {
        return $this->userId === $userId;
    }

    public function hasSnapshot(): bool
    {
        return $this->templateSnapshot !== null;
    }

    /**
     * The shared shape a credential format, the status list allocator and the
     * verification contract are all written against, so those behaviours are
     * implemented once and a badge award persisting the same record reuses
     * every one of them.
     *
     * @param list<CertificateCredit> $credits
     */
    public function toIssuanceRecord(string $achievementType = 'CertificateOfCompletion', array $credits = []): IssuanceRecord
    {
        return new IssuanceRecord(
            IssuanceRecord::SUBJECT_CERTIFICATE,
            $this->id,
            $this->uuid,
            $this->code,
            $this->userId,
            $this->courseId,
            $this->enrolmentId,
            $this->templateId,
            $this->templateRevision,
            $this->templateSnapshot,
            $this->contextSnapshot,
            $this->rendererKey,
            $this->engineVersion,
            $this->issuedAt,
            $this->completedAt,
            $this->validFrom,
            $this->expiresAt,
            $this->storedStatus,
            $this->revokedAt,
            $this->revocationReason,
            $this->supersededById,
            $this->supersedesId,
            $this->published,
            $this->credentialJson,
            $this->credentialFormat,
            $this->proofType,
            $this->recipientIdentityHash,
            $this->recipientIdentitySalt,
            $this->artefactPath,
            $this->artefactChecksum,
            $this->renderedAt,
            $this->eventRef,
            $this->issuerEarnerId,
            $achievementType,
            $credits === [] ? $this->credits : $credits,
        );
    }
}
