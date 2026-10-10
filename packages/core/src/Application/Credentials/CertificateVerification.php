<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;

/**
 * The public answer of the verification page.
 *
 * An unknown code is an ordinary negative answer and never an exception, so
 * probing the endpoint reveals nothing structural. It does not follow that
 * the endpoint reveals nothing at all: a valid response returns a learner
 * name and a course title, which is intended and is what makes a certificate
 * verifiable. The old docblock claimed probing revealed nothing beyond valid
 * or not, and that overstated the safety of a page that is, by design, a PII
 * disclosure surface. What protects it is the noindex header, the rate limit
 * in front of it, the site setting and the per-certificate publication flag
 * that decide whether the name appears at all, and the rule that an email
 * address, a user id, an enrolment id and the internal certificate id are
 * never in this payload.
 *
 * Verification is not validation, and this shape must not imply otherwise.
 * VC 2.0 defines verification as checking that the document conforms, that
 * the securing mechanism is satisfied and that the status check succeeds, and
 * says plainly that it does not imply evaluation of the truth of the claims.
 * Whether a course completion means the holder can do the job is the relying
 * party's problem.
 *
 * status carries the six-value enumeration under the published precedence,
 * while revoked and expired stay independent booleans, because the two axes
 * really are independent and a single-valued status cannot report both at
 * once.
 */
final class CertificateVerification
{
    public const REASON_NOT_FOUND = 'not_found';
    public const REASON_REVOKED = 'revoked';
    public const REASON_EXPIRED = 'expired';
    public const REASON_SUSPENDED = 'suspended';
    public const REASON_SUPERSEDED = 'superseded';
    public const REASON_NOT_YET_VALID = 'not_yet_valid';

    /**
     * @param list<CertificateCredit> $credits
     */
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $reason,
        public readonly ?string $courseTitle,
        public readonly ?string $learnerName,
        public readonly ?\DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?CredentialStatus $status = null,
        public readonly bool $revoked = false,
        public readonly bool $expired = false,
        public readonly ?\DateTimeImmutable $revokedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly ?\DateTimeImmutable $validFrom = null,
        public readonly array $credits = [],
        public readonly ?string $credentialUrl = null,
        public readonly ?string $supersededByUrl = null,
    ) {
    }

    public static function notFound(): self
    {
        return new self(false, self::REASON_NOT_FOUND, null, null, null, null);
    }

    /**
     * @param list<CertificateCredit> $credits
     */
    public static function of(
        CredentialStatus $status,
        ?string $courseTitle,
        ?string $learnerName,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $expiresAt,
        bool $revoked,
        bool $expired,
        ?\DateTimeImmutable $revokedAt = null,
        ?\DateTimeImmutable $completedAt = null,
        ?\DateTimeImmutable $validFrom = null,
        array $credits = [],
        ?string $credentialUrl = null,
        ?string $supersededByUrl = null,
    ): self {
        return new self(
            $status->isValid(),
            $status->reason(),
            $courseTitle,
            $learnerName,
            $issuedAt,
            $expiresAt,
            $status,
            $revoked,
            $expired,
            $revokedAt,
            $completedAt,
            $validFrom,
            $credits,
            $credentialUrl,
            $supersededByUrl,
        );
    }

    /**
     * Kept because the contract names it and the admin filter sends it: an
     * invalid answer with an explicit reason, for a caller that has already
     * decided what the reason is.
     */
    public static function invalid(
        string $reason,
        ?string $courseTitle,
        ?string $learnerName,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $expiresAt,
    ): self {
        return new self(false, $reason, $courseTitle, $learnerName, $issuedAt, $expiresAt);
    }

    public static function valid(
        ?string $courseTitle,
        ?string $learnerName,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            true,
            null,
            $courseTitle,
            $learnerName,
            $issuedAt,
            $expiresAt,
            CredentialStatus::Active,
        );
    }

    /**
     * The one word the payload leads with, or null when there is no
     * credential for it to describe.
     *
     * It used to fall back to revoked whenever no status had been resolved,
     * which is exactly the not-found case, so a mistyped code told the public
     * that the certificate had been withdrawn. The enumeration is a closed
     * six describing a credential, and "no such credential" is not one of
     * them: the honest answer is no status at all, with the reason carrying
     * what happened. Both host pages render the status line only when this
     * answers.
     */
    public function statusValue(): ?string
    {
        if ($this->status !== null) {
            return $this->status->value;
        }

        return $this->valid ? CredentialStatus::Active->value : null;
    }
}
