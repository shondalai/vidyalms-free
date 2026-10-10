<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgress;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

final class RecomputeEnrolmentProgressTask implements ScheduledTaskHandlerInterface
{
    public const KEY = 'vidyalms.progress.recompute';

    public function __construct(private readonly RecomputeCourseProgress $recompute)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $enrolmentId = $run->int('enrolment_id');
        $this->recompute->execute($run->principal, $enrolmentId);

        return 'enrolment=' . $enrolmentId;
    }
}
