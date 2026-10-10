<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Domain\Credentials\NewCertificate;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateIssued;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * Issues the certificate for a completed enrolment, exactly once, and freezes
 * what it issued.
 *
 * The exactly-once guard is the unique active_enrolment_key, not a unique
 * enrolment_id. The old index made supersession structurally impossible: a
 * reissue writes a second row for the same enrolment, so a bulk reissue after
 * a template correction would have thrown on every per-certificate task while
 * the fan-out reported success and issued nothing. Dropping the index without
 * a replacement would have been worse, because two concurrent replays would
 * each insert and the learner's download would resolve to an arbitrary row.
 *
 * Everything one issuance owns commits together: the row, its credit rows
 * from the course's authored credit, its status list entries, the template
 * lock, and the notification intent. A credit row without its certificate is
 * a compliance defect under regimes that retain records for five and seven
 * years, and a status index that was allocated for a row that rolled back is
 * a bit nobody can ever reclaim.
 */
final class IssueCertificate
{
    private const CODE_LENGTH = 12;
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateTemplateRepository $templates,
        private readonly EnsureDefaultTemplate $ensureDefaultTemplate,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
        private readonly DatabasePort $database,
        /**
         * Optional so a harness with no notification engine still builds this
         * use case; absent, the row is written and dispatched exactly as
         * before and the listener is the only enqueue path.
         */
        private readonly ?TransactionalOutbox $outbox = null,
        /**
         * The freeze. Absent, a row is issued with no snapshot, which is what
         * every row issued before this existed looks like: the render falls
         * back to the live template and says that it did.
         */
        private readonly ?IssuanceSnapshot $snapshot = null,
        private readonly ?CourseCreditRepository $courseCredits = null,
        private readonly ?CertificateCreditRepository $certificateCredits = null,
        private readonly ?CredentialStatusRepository $statusLists = null,
        /**
         * The site's default for whether a learner's name appears on the
         * public verification page. It is a per-certificate column defaulting
         * to the site setting, which is Credly's accepted-and-public gate
         * expressed for a self-hosted product.
         */
        private readonly bool $publishByDefault = false,
        /**
         * The expiry registry's one call site, shared with the reissue path.
         * Absent, a certificate never expires, which is the registry's
         * published miss direction rather than a second if-chain.
         */
        private readonly ?ResolveExpiry $expiry = null,
        /**
         * The render queue. A certificate with no artefact answers 409 on
         * every download for ever, so the task that produces one is queued
         * by the code that creates the credential.
         */
        private readonly ?CertificateJobs $jobs = null,
        /**
         * The credential as issued, frozen beside the artefact. Absent, the
         * columns stay null and GetCredential rebuilds from live state and
         * says that it did.
         */
        private readonly ?FreezeCredential $freezeCredential = null,
        private readonly ?CourseCertificatePolicy $coursePolicy = null,
    ) {
    }

    /**
     * @param callable(): array{0: Certificate, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function write(callable $operation): Certificate
    {
        if ($this->outbox !== null) {
            return $this->outbox->commit($operation);
        }

        // The transaction belongs to the invariant, not to the notification
        // collaborator: the row, its credits, its status entries and its
        // frozen credential commit together or not at all.
        /** @var array{0: Certificate, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $written */
        $written = $this->database->transactional($operation);

        [$certificate, $events] = $written;
        $this->events->dispatchAll($events);

        return $certificate;
    }

    public function execute(
        int $enrolmentId,
        int $userId,
        int $courseId,
        ?\DateTimeImmutable $completedAt = null,
        ?string $issuerEarnerId = null,
    ): Certificate {
        return $this->executeOutcome($enrolmentId, $userId, $courseId, $completedAt, $issuerEarnerId)->requireCertificate();
    }

    /** The production completion and reconciliation entry point. */
    public function executeOutcome(
        int $enrolmentId,
        int $userId,
        int $courseId,
        ?\DateTimeImmutable $completedAt = null,
        ?string $issuerEarnerId = null,
    ): CertificateIssuanceOutcome {
        \Shondalai\VidyaLMS\Core\Application\Organisation\RequireCurrentOrganisationAccess::check(
            $this->database,
            $this->clock,
            $userId,
            $courseId,
        );
        $existing = $this->certificates->findActiveByEnrolmentId($enrolmentId);

        if ($existing !== null) {
            return new CertificateIssuanceOutcome('existing', $existing);
        }

        $template = $this->coursePolicy?->resolve($courseId);
        if ($this->coursePolicy !== null && $template === null) {
            return new CertificateIssuanceOutcome('skipped', null, 'course_policy_disabled');
        }
        $template ??= $this->templates->findPublishedDefault();
        $templateId = $template !== null ? (int) $template['id'] : $this->ensureDefaultTemplate->execute();
        $template ??= $this->templates->findById($templateId);

        $issuedAt = $this->clock->now();
        $expiresAt = $this->expiry?->execute($template, $issuedAt, $completedAt);
        $credits = $this->creditsFor($courseId);
        $lastFailure = null;

        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = $this->random->verificationCode(self::CODE_LENGTH);

            if ($this->certificates->codeExists($code)) {
                continue;
            }

            $uuid = $this->random->uuid();

            $draft = $this->snapshot === null
                ? $this->unfrozenDraft(
                    $userId,
                    $courseId,
                    $enrolmentId,
                    $templateId,
                    $code,
                    $uuid,
                    $issuedAt,
                    $completedAt,
                    $expiresAt,
                    $issuerEarnerId,
                    $credits,
                )
                : $this->snapshot->draftFor(
                    $userId,
                    $courseId,
                    $enrolmentId,
                    $template ?? [],
                    $code,
                    $uuid,
                    $issuedAt,
                    $completedAt,
                    $expiresAt,
                    $credits,
                    $this->publishByDefault,
                    $issuerEarnerId,
                );

            try {
                $certificate = $this->write(function () use ($draft, $templateId, $issuedAt, $enrolmentId): array {
                    // A lapsed predecessor still holding the exactly-once key
                    // would take the unique index with it: expiry is derived,
                    // so nothing released the key when the clock passed.
                    $this->certificates->releaseLapsedEnrolmentKey($enrolmentId, $issuedAt);

                    $issued = $this->certificates->insert($draft);

                    $this->certificateCredits?->insertAll($issued->id, $draft->credits);

                    // One entry per purpose this build supports, allocated by
                    // the code that creates the credential: they cannot be
                    // back-filled deterministically afterwards, and a signed
                    // credential cannot be edited once it sits in a wallet.
                    foreach (CredentialStatusRepository::PURPOSES as $purpose) {
                        $this->statusLists?->allocate(IssuanceRecord::SUBJECT_CERTIFICATE, $issued->id, $purpose);
                    }

                    // Moodle's badge lock rule, applied to templates: once a
                    // credential has been awarded under a criteria set, that
                    // set and its expiry freeze and a change makes a new
                    // version.
                    $this->templates->lock($templateId, $issuedAt);

                    // The credential is frozen with the row it belongs to.
                    // Rebuilt at read time it would follow a course rename or
                    // an issuer edit, which is the very failure the template
                    // snapshot exists to prevent, one layer up.
                    $this->freezeCredential?->execute($issued);

                    return [$issued, [new CertificateIssued(
                        $issuedAt,
                        $issued->id,
                        $issued->code,
                        $draft->enrolmentId,
                        $draft->userId,
                        $draft->courseId,
                    )]];
                });
            } catch (\Throwable $failure) {
                // A concurrent issuance can win the unique active enrolment
                // key between the lookup and this insert; their row is the
                // answer.
                $existing = $this->certificates->findActiveByEnrolmentId($enrolmentId);

                if ($existing !== null) {
                    return new CertificateIssuanceOutcome('existing', $existing);
                }

                // Only a code collision earns another turn. Anything else is
                // reported as itself rather than five turns later under a
                // message naming the wrong column. The row is looked up
                // rather than counted, because this attempt's own insert has
                // rolled back: a row holding this code now belongs to
                // somebody else.
                if ($this->certificates->findByCode($code) === null) {
                    throw $failure;
                }

                $lastFailure = $failure;
                continue;
            }

            // After the commit, for the reason the events are: a runner in
            // another process can claim the job before the row it names is
            // visible.
            $this->jobs?->queueRender($certificate->id);

            return new CertificateIssuanceOutcome('issued', $certificate);
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \RuntimeException('Could not allocate a unique certificate verification code.', 0, $lastFailure);
    }

    /**
     * @param list<CertificateCredit> $credits
     */
    private function unfrozenDraft(
        int $userId,
        int $courseId,
        int $enrolmentId,
        int $templateId,
        string $code,
        string $uuid,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $expiresAt,
        ?string $issuerEarnerId,
        array $credits,
    ): NewCertificate {
        return new NewCertificate(
            $userId,
            $courseId,
            $enrolmentId,
            $templateId,
            $code,
            $uuid,
            $issuedAt,
            $completedAt,
            null,
            $expiresAt,
            null,
            null,
            null,
            'html',
            null,
            $this->publishByDefault,
            null,
            null,
            null,
            $issuerEarnerId,
            null,
            null,
            $credits,
        );
    }

    /**
     * The course's authored credit becomes the certificate's credit rows, in
     * the certificate's own transaction.
     *
     * Without this path the free contact hours scheme, the free credit
     * statement element and the free credit tokens all read an empty table,
     * which is the empty-seam failure the free-provider rule exists to
     * prevent.
     *
     * @return list<CertificateCredit>
     */
    private function creditsFor(int $courseId): array
    {
        if ($this->courseCredits === null) {
            return [];
        }

        $credits = [];

        foreach ($this->courseCredits->forCourse($courseId) as $authored) {
            $credits[] = $this->snapshot === null
                ? new CertificateCredit(
                    $authored->scheme,
                    $authored->statedAmount() ?? '0.00',
                    'hours',
                    $authored->fieldOfStudy(),
                    $authored->awardingBodyId(),
                    $authored->contactMinutes(),
                    $authored->params,
                    $authored->paramsVersion,
                )
                : $this->snapshot->creditFor($authored);
        }

        return $credits;
    }
}
