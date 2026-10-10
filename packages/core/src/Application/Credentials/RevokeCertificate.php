<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Withdraws a certificate, permanently, with a reason on the row.
 *
 * The reason is part of the ceremony rather than an optional extra. Open
 * Badges 2.0 documents the vocabulary the admin dialog offers (issued in
 * error, violation of conditions, award reconsidered), and a revocation with
 * no recorded reason is an answer a relying party cannot act on and an
 * auditor cannot reconstruct.
 *
 * Revocation releases the enrolment key, so the learner can be issued a
 * replacement, and marks the status lists holding this credential as needing
 * republication. It never deletes: the code keeps resolving to a page that
 * says the certificate was withdrawn.
 *
 * Idempotent: an already revoked certificate is returned unchanged, keeping
 * its original revocation timestamp and reason.
 */
final class RevokeCertificate
{
    /**
     * The Open Badges 2.0 vocabulary, seeded so the dialog offers something
     * rather than a free text box nobody fills in consistently.
     *
     * @var list<string>
     */
    public const REASONS = ['issued_in_error', 'violation_of_conditions', 'award_reconsidered'];

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly ?CredentialStatusRepository $statusLists = null,
    ) {
    }

    public function execute(Principal $principal, int $certificateId, ?string $reason = null): Certificate
    {
        $this->guard->require($principal, Capability::MANAGE);

        $certificate = $this->certificates->findById($certificateId);

        if ($certificate === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }

        if ($certificate->isRevoked()) {
            return $certificate;
        }

        $now = $this->clock->now();

        $this->certificates->markRevoked($certificateId, $now, $reason);
        $this->statusLists?->markDirty(IssuanceRecord::SUBJECT_CERTIFICATE, $certificateId, $now);

        return $this->certificates->findById($certificateId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Certificate', $certificateId);
    }
}
