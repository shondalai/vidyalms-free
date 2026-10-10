<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificatesForTemplate;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The fan-out half of a bulk reissue. The run schedules its own continuation
 * when it filled a batch, so one task is bounded whatever the cohort size.
 */
final class ReissueCertificatesTask implements ScheduledTaskHandlerInterface
{
    public function __construct(private readonly ReissueCertificatesForTemplate $reissue)
    {
    }

    public function key(): string
    {
        return ReissueCertificatesForTemplate::TASK_TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $templateId = $run->int('template_id');
        $revision = $run->int('revision');
        $after = $run->int('after_certificate_id');

        $queued = $this->reissue->fanOut($templateId, $revision, $after);

        return 'template=' . $templateId . ' revision=' . $revision . ' after=' . $after . ' queued=' . $queued;
    }
}
