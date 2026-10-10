<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;

/**
 * A certificate with the person and the course it names, and the one word
 * that says where it stands.
 *
 * That word is now the six-value enumeration rather than three, and the
 * precedence it resolves under is published rather than left to whichever the
 * implementation computes first: revoked, then superseded, then suspended,
 * then not yet valid, then expired, then active. A certificate revoked for
 * plagiarism in 2026 whose expiry passes in 2028 reports revoked, because
 * "lapsed" and "withdrawn" are materially different answers.
 */
final class CertificateRecord
{
    /**
     * The three the screen used to know. STATUS_VALID survives as the filter
     * value the admin list has always sent; the record itself answers
     * "active", which is the stored vocabulary.
     */
    public const STATUS_VALID = 'valid';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        public readonly Certificate $certificate,
        public readonly ?string $learnerName,
        public readonly ?Course $course,
        public readonly string $status,
    ) {
    }

    public static function statusOf(Certificate $certificate, \DateTimeImmutable $now): string
    {
        return $certificate->statusAt($now)->value;
    }

    /**
     * Whether the credential is in force, which the screen still asks as a
     * yes or no beside the word.
     */
    public function isValid(): bool
    {
        return $this->status === CredentialStatus::Active->value;
    }
}
