<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * A reversible hold, which revocation cannot express and a boolean revocation
 * date could not carry at all.
 *
 * Bitstring Status List v1.0 names suspension as a first-class reversible
 * purpose beside revocation, and the cases are real: a payment dispute, a
 * plagiarism investigation, a lapsed continuing-education registration. Every
 * one of them ends either way, and answering "revoked" in the meantime is a
 * permanent and defamatory answer to a temporary question.
 *
 * A suspended certificate keeps its enrolment key, because a hold does not
 * entitle the holder to a second certificate.
 *
 * A revoked certificate cannot be suspended: revocation is permanent, and
 * stepping back from it would rewrite an evidentiary record.
 */
final class SuspendCertificate
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly ?CredentialStatusRepository $statusLists = null,
    ) {
    }

    public function execute(Principal $principal, int $certificateId, ?string $reason = null): Certificate
    {
        return $this->apply($principal, $certificateId, true, $reason);
    }

    public function reinstate(Principal $principal, int $certificateId): Certificate
    {
        return $this->apply($principal, $certificateId, false, null);
    }

    private function apply(Principal $principal, int $certificateId, bool $suspend, ?string $reason): Certificate
    {
        $this->guard->require($principal, Capability::MANAGE);

        $certificate = $this->certificates->findById($certificateId);

        if ($certificate === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }

        if ($certificate->storedStatus === CredentialStatus::Revoked) {
            throw new \DomainException('A revoked certificate cannot be suspended or reinstated.');
        }

        if ($certificate->storedStatus === CredentialStatus::Superseded) {
            throw new \DomainException('A superseded certificate cannot be suspended or reinstated.');
        }

        if ($suspend === $certificate->isSuspended()) {
            return $certificate;
        }

        $now = $this->clock->now();

        if ($suspend) {
            $this->certificates->markSuspended($certificateId, $reason, $now);
        } else {
            $this->certificates->markReinstated($certificateId, $now);
        }

        $this->statusLists?->markDirty(IssuanceRecord::SUBJECT_CERTIFICATE, $certificateId, $now);

        return $this->certificates->findById($certificateId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Certificate', $certificateId);
    }
}
