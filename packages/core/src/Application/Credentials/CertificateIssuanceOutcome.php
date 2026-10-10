<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;

/** A disabled course is a deliberate skip, never a failed job or fake award. */
final class CertificateIssuanceOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly ?Certificate $certificate,
        public readonly ?string $reason = null,
    ) {
    }

    public function requireCertificate(): Certificate
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->certificate ?? throw new \LogicException('Certificate issuance was skipped: ' . $this->reason);
    }
}
