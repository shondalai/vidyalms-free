<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RenderCertificateTask;

/**
 * The queue hop that turns an issued certificate into an artefact somebody
 * can download.
 *
 * The render task existed, was registered, and was queued by nothing: a
 * repo-wide search for its type found only the constant that declares it. So
 * artefact_path, artefact_checksum and rendered_at stayed null on every row
 * ever issued, and the learner's download answered 409 "not rendered yet" for
 * ever, which is exactly the dead column the prerequisite was written to
 * revive.
 *
 * The row that causes the work queues the work, and the schedule happens
 * after the commit for the reason the outbox already gives: a job queued
 * inside the transaction can be claimed by a runner in another process before
 * the row it names is visible.
 */
final class CertificateJobs
{
    public function __construct(
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * One job per certificate, keyed on the certificate, so a second wake-up
     * for a row already queued replaces rather than duplicates.
     */
    public static function renderDedupeKey(int $certificateId): string
    {
        return 'certificate.render:' . $certificateId;
    }

    public function queueRender(int $certificateId): void
    {
        if ($certificateId < 1) {
            return;
        }

        $this->scheduler->schedule(new ScheduledTask(
            RenderCertificateTask::KEY,
            ['certificate_id' => $certificateId],
            $this->clock->now(),
            self::renderDedupeKey($certificateId),
        ));
    }
}
