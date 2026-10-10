<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateReissued;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * A correction, modelled as supersession rather than as an edit.
 *
 * A reissue writes a new row with a new snapshot and a new code, sets the old
 * row's status to superseded, clears its enrolment key and sets its
 * superseded_by_id. Nothing is ever mutated or deleted beyond those lifecycle
 * fields, so the old verification code keeps resolving to a page that says so
 * and links onward, and the verification trail stays intact. That is Credly's
 * replaced state, which is the nearest correct answer in the market.
 *
 * The carry-forward set is stated rather than implied, because a reissue that
 * quietly drops a field is an evidentiary defect. The completion date, the
 * validity window, the recipient identity pair, the caller's own idempotency
 * key and one copy of every credit row with its reporting date reset all move
 * across. Each has a failure behind it: uncopied credits vanish from a
 * compliance export that groups over active rows, a regenerated salt breaks
 * every relying party that pinned the old identity hash, and completed_at
 * would silently become the reissue date on a page that labels it
 * "Completed".
 */
final class ReissueCertificate
{
    private const CODE_LENGTH = 12;
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateCreditRepository $credits,
        private readonly IssuanceSnapshot $snapshot,
        private readonly AccessGuard $guard,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
        private readonly ?TransactionalOutbox $outbox = null,
        private readonly ?CredentialStatusRepository $statusLists = null,
        /** The expiry registry's one call site, shared with the issue path. */
        private readonly ?ResolveExpiry $expiry = null,
        private readonly ?DatabasePort $database = null,
        private readonly ?CertificateJobs $jobs = null,
        private readonly ?FreezeCredential $freezeCredential = null,
        private readonly ?CertificateStudio $studio = null,
    ) {
    }

    /**
     * @param bool $renew recompute the expiry from the template rather than carrying the old one forward
     */
    public function execute(Principal $principal, int $certificateId, bool $renew = false): Certificate
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->reissue($certificateId, $renew);
    }

    /**
     * The unguarded path, for the scheduled fan-out that already authorised
     * the run when it was queued. It is separate rather than a nullable
     * principal so nothing can reach it from a request by accident.
     */
    public function forScheduledTask(int $certificateId, int $publishedRevision): Certificate
    {
        return $this->reissue($certificateId, false, $publishedRevision);
    }

    private function reissue(int $certificateId, bool $renew, ?int $publishedRevision = null): Certificate
    {
        $previous = $this->certificates->findById($certificateId);

        if ($previous === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }

        if ($previous->storedStatus === CredentialStatus::Superseded) {
            // Already replaced. Reissuing it again would fork the chain and
            // produce two rows both claiming to supersede the same one.
            return $previous;
        }

        if ($previous->storedStatus === CredentialStatus::Revoked) {
            throw ConflictException::with('A revoked certificate cannot be reissued.', [
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'certificate_id' => $previous->id,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'credential_status' => CredentialStatus::Revoked->value,
            ]);
        }

        if ($previous->storedStatus === CredentialStatus::Suspended) {
            // A hold is lifted by the reinstate ceremony and never as a side
            // effect of a correction: supersession releases the enrolment key
            // and the replacement is issued active, so a reissue here would
            // quietly hand back a credential somebody deliberately held.
            throw ConflictException::with(
                'A suspended certificate is reinstated before it is reissued.',
                [
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'certificate_id' => $previous->id,
                    // RFC 9457 reserves "status" for the HTTP status code.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'credential_status' => CredentialStatus::Suspended->value,
                ],
            );
        }

        $template = $this->templates->findById($previous->templateId);

        if ($template === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template', $previous->templateId);
        }
        $template = $this->studio?->publishedForIssuance($previous->templateId) ?? $template;
        if ($publishedRevision !== null && (int) $template['revision'] !== $publishedRevision) {
            throw ConflictException::with('The queued published certificate revision is no longer available.', [
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'requested_revision' => $publishedRevision, 'published_revision' => (int) $template['revision'],
            ]);
        }

        $issuedAt = $this->clock->now();
        $expiresAt = $renew
            ? $this->expiry?->execute($template, $issuedAt, $previous->completedAt, $previous->validFrom)
            : $previous->expiresAt;
        $carried = $this->carriedCredits($previous->id);
        $supersededStatus = CredentialStatus::Superseded;
        $lastFailure = null;

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = $this->random->verificationCode(self::CODE_LENGTH);

            if ($this->certificates->codeExists($code)) {
                continue;
            }

            $draft = $this->snapshot->draftFor(
                $previous->userId,
                $previous->courseId,
                $previous->enrolmentId,
                $template,
                $code,
                $this->random->uuid(),
                $issuedAt,
                $previous->completedAt,
                $expiresAt,
                $carried,
                $previous->published,
                $previous->issuerEarnerId,
                $previous,
            );

            try {
                $reissued = $this->write(function () use ($draft, $previous, $issuedAt, $supersededStatus): array {
                    // The old row releases its enrolment key and its
                    // issuer-supplied earner id first, so the new row can
                    // take both: each carries a unique index, both are in the
                    // carry-forward set, and both writes are in one
                    // transaction, so a reader never sees neither and never
                    // sees two.
                    $this->certificates->markSuperseded($previous->id, 0, $issuedAt, $supersededStatus);

                    $issued = $this->certificates->insert($draft);

                    $this->certificates->markSuperseded($previous->id, $issued->id, $issuedAt, $supersededStatus);
                    $this->credits->insertAll($issued->id, $draft->credits);

                    foreach (CredentialStatusRepository::PURPOSES as $purpose) {
                        $this->statusLists?->allocate(IssuanceRecord::SUBJECT_CERTIFICATE, $issued->id, $purpose);
                    }

                    $this->statusLists?->markDirty(
                        IssuanceRecord::SUBJECT_CERTIFICATE,
                        $previous->id,
                        $issuedAt,
                    );

                    $this->freezeCredential?->execute($issued);

                    // The reference names the row that was replaced and never
                    // the row that was created: a key built from the new id
                    // is different on every run, so a job killed halfway and
                    // restarted would mail the first half of a cohort twice.
                    return [$issued, [new CertificateReissued(
                        $issuedAt,
                        $issued->id,
                        $previous->id,
                        $issued->code,
                        $issued->enrolmentId,
                        $issued->userId,
                        $issued->courseId,
                        $issued->templateRevision ?? 1,
                    )]];
                });
            } catch (\Throwable $failure) {
                // Only a code collision earns a retry. Anything else (a
                // unique index the draft violated, a port failure) is
                // reported as itself: retrying five times and then blaming
                // code allocation hides the real cause behind a message that
                // names the wrong column. The row is looked up rather than
                // counted, because this attempt's own insert has rolled back:
                // a row holding this code now belongs to somebody else.
                if ($this->certificates->findByCode($code) === null) {
                    throw $failure;
                }

                $lastFailure = $failure;
                continue;
            }

            $this->jobs?->queueRender($reissued->id);

            return $reissued;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \RuntimeException('Could not allocate a unique certificate verification code.', 0, $lastFailure);
    }

    /**
     * @param callable(): array{0: Certificate, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function write(callable $operation): Certificate
    {
        if ($this->outbox !== null) {
            return $this->outbox->commit($operation);
        }

        // The transaction is the invariant's, not the outbox's. Without it a
        // failed insert leaves the predecessor marked superseded by
        // certificate id 0 with its enrolment key released and no successor,
        // which strands the holder.
        /** @var array{0: Certificate, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $written */
        $written = $this->database === null
            ? $operation()
            : $this->database->transactional($operation);

        [$certificate, $events] = $written;
        $this->events->dispatchAll($events);

        return $certificate;
    }

    /**
     * @return list<CertificateCredit>
     */
    private function carriedCredits(int $certificateId): array
    {
        return array_map(
            static fn (CertificateCredit $credit): CertificateCredit => $credit->carriedForward(),
            $this->credits->forCertificate($certificateId),
        );
    }
}
