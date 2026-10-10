<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificatesForTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * One certificate of a bulk reissue.
 *
 * It is a separate task type from the fan-out so a restart resumes at the
 * certificate rather than at the template, and so one failure is one row
 * rather than a whole cohort. The use case is idempotent for a row that has
 * already been replaced: a superseded certificate is returned untouched
 * rather than forked into a second chain.
 */
final class ReissueCertificateTask implements ScheduledTaskHandlerInterface
{
    public function __construct(private readonly ReissueCertificate $reissue)
    {
    }

    public function key(): string
    {
        return ReissueCertificatesForTemplate::CERTIFICATE_TASK_TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $certificateId = $run->int('certificate_id');
        $issued = $this->reissue->forScheduledTask($certificateId, $run->int('revision'));

        return 'superseded=' . $certificateId . ' issued=' . $issued->id . ' code=' . $issued->code;
    }
}
