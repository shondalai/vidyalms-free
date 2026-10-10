<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks;

/**
 * Bulk reissue after a template correction: one fan-out task per template
 * revision, fanning out one task per certificate.
 *
 * It follows the course recompute fan-out exactly, including the bug that
 * pattern records. Sharing one dedupe key across batches made the adapter
 * upsert on the key, reset the running row to pending and stop after one
 * batch, so the continuation key carries the point it resumes after and can
 * never collide with the row the runner is draining.
 *
 * Two levels of key, because they answer different questions. The run key,
 * reissue:template:{templateId}:{revision}, is what a second correction
 * replaces and what a cancel names. The per-certificate key,
 * reissue:certificate:{certificateId}:{revision}, is what stops one
 * certificate being reissued twice by two overlapping runs. The two families
 * are namespaced because unprefixed they were the same string for the same
 * numbers, and both adapters dedupe globally on the key.
 *
 * The selection predicate is part of the design and not an implementation
 * detail: template, active, and a revision below the target. It is idempotent
 * under restart because a reissued row already carries the new revision and
 * is skipped. Enumerating every certificate for the template instead would
 * re-select the superseded rows a previous run produced, so a second
 * correction across five hundred learners would mint two revision-three
 * certificates and two supersession chains each, every one of them verifying
 * as active.
 */
final class ReissueCertificatesForTemplate
{
    public const TASK_TYPE = 'vidyalms.certificate.reissue';
    public const CERTIFICATE_TASK_TYPE = 'vidyalms.certificate.reissue_one';

    /** How many certificates one run of the fan-out queues. */
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateTemplateRepository $templates,
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
        private readonly AccessGuard $guard,
        private readonly ?CertificateStudio $studio = null,
    ) {
    }

    /**
     * The run key. A second correction while the first run is still going
     * replaces the pending run from the start, which is correct rather than
     * wasteful: the second correction changed the answer for everyone.
     */
    public static function dedupeKeyFor(int $templateId, int $revision, int $afterCertificateId = 0): string
    {
        $key = 'reissue:template:' . $templateId . ':' . $revision;

        return $afterCertificateId > 0 ? $key . ':' . $afterCertificateId : $key;
    }

    /**
     * The two families are namespaced, because "reissue:1:2" was both the run
     * for template 1 at revision 2 and the fan-out target for certificate 1
     * at revision 2. Both adapters dedupe globally on the key and overwrite
     * the job type, so the fan-out replaced its own run row and the whole
     * bulk reissue collapsed into a single per-certificate task; cancelling
     * template 5's run also deleted certificate 5's pending reissue. The
     * course recompute fan-out this pattern is copied from avoids it by
     * prefixing, and this had dropped the prefix.
     */
    public static function certificateDedupeKeyFor(int $certificateId, int $revision): string
    {
        return 'reissue:certificate:' . $certificateId . ':' . $revision;
    }

    /**
     * Queues the observed published design. A correction is published first;
     * requesting reissue never creates a new source revision.
     *
     * @return array{revision: int, dedupe_key: string}
     */
    public function queue(Principal $principal, int $templateId, ?int $publishedRevision = null): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($this->templates->findById($templateId) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template', $templateId);
        }

        // A run does not publish a draft or manufacture a design revision.
        // Production targets the immutable publication; only the isolated
        // legacy collaborator remains on the pre-publication reservation.
        $revision = $this->studio === null
            ? $this->templates->bumpRevision($templateId, $this->clock->now())
            : (int) $this->studio->publishedForIssuance($templateId)['revision'];
        if ($publishedRevision !== null && $publishedRevision !== $revision) {
            throw new ConflictException('The published certificate changed. Refresh before starting a new run.');
        }
        $key = self::dedupeKeyFor($templateId, $revision);
        if ($this->scheduler instanceof InspectsScheduledTasks) {
            $existing = $this->scheduler->taskGroup($key);
            if ($existing !== null && \in_array($existing['state'], ['queued', 'running'], true)) {
                throw new ConflictException('This certificate revision already has an active reissue run. Open its status.');
            }
            if ($existing !== null && \in_array($existing['state'], ['failed', 'cancelled'], true)) {
                $this->scheduler->retryTaskGroup($key);
                return ['revision' => $revision, 'dedupe_key' => $key];
            }
        }

        $this->scheduler->schedule(new ScheduledTask(
            self::TASK_TYPE,
            ['template_id' => $templateId, 'revision' => $revision, 'after_certificate_id' => 0],
            $this->clock->now(),
            $key,
            $key,
        ));

        return ['revision' => $revision, 'dedupe_key' => $key];
    }

    /**
     * Cancels a run mid-flight by its fan-out key. The per-certificate tasks
     * already queued are not cancelled by this, and that is deliberate: each
     * is idempotent under its own key and cancelling a half-written
     * supersession chain is worse than letting it finish.
     */
    public function cancel(Principal $principal, int $templateId, int $revision): void
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($this->scheduler instanceof InspectsScheduledTasks) {
            $this->scheduler->cancelTaskGroup(self::dedupeKeyFor($templateId, $revision));
        } else {
            $this->scheduler->cancelByDedupeKey(self::dedupeKeyFor($templateId, $revision));
        }
    }

    /**
     * What the host's task runner calls. No caller principal: cron has no
     * session, and the run was authorised when it was queued.
     *
     * @return int how many certificates this run queued
     */
    public function fanOut(int $templateId, int $revision, int $afterCertificateId = 0): int
    {
        $ids = $this->certificates->idsForReissue($templateId, $revision, self::BATCH_SIZE, $afterCertificateId);

        foreach ($ids as $certificateId) {
            $this->scheduler->schedule(new ScheduledTask(
                self::CERTIFICATE_TASK_TYPE,
                ['certificate_id' => $certificateId, 'revision' => $revision],
                $this->clock->now(),
                self::certificateDedupeKeyFor($certificateId, $revision),
                self::dedupeKeyFor($templateId, $revision),
            ));
        }

        if (\count($ids) === self::BATCH_SIZE) {
            $last = $ids[self::BATCH_SIZE - 1];

            $this->scheduler->schedule(new ScheduledTask(
                self::TASK_TYPE,
                ['template_id' => $templateId, 'revision' => $revision, 'after_certificate_id' => $last],
                $this->clock->now(),
                self::dedupeKeyFor($templateId, $revision, $last),
                self::dedupeKeyFor($templateId, $revision),
            ));
        }

        return \count($ids);
    }
}
