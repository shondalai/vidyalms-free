<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob;
use Shondalai\VidyaLMS\Core\Application\Ai\RunQueuedAiJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The job drain: one pending task per AI job, keyed on the job id.
 *
 * The job row already carries the task or the recipe, the scope, the options and
 * the person who asked for it, so the payload is only its id.
 *
 * **The handler owns its own retry and therefore does not throw.** Two backoff
 * regimes over one unit of work compose differently under each host's runner, and
 * an AI run needs its own bounded retry: the route fallback chain, the
 * rate-limit defer and the discarded truncation are all decisions the pipeline
 * makes, and a host runner retrying on top of them would defeat every one. So a
 * job already claimed is logged as such and a job that vanished is logged as
 * such, and both return a summary line rather than an exception.
 *
 * That is also why a deferred job needs nothing here: RunQueuedAiJob re-opens the
 * parked row, so the same dedupe key covers the resume and there is never a
 * second job for one build.
 */
final class RunAiJobTask implements ScheduledTaskHandlerInterface
{
    public function __construct(private readonly RunQueuedAiJob $jobs)
    {
    }

    public function key(): string
    {
        return QueueAiJob::TASK_TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $jobId = $run->int('job_id');

        try {
            $job = $this->jobs->execute($jobId);
        } catch (ConflictException $claimed) {
            // A task runner that redelivers must not restart a build that is
            // already halfway. This is the ordinary shape of that, not a failure.
            return 'ai_job=' . $jobId . ' status=already_claimed';
        } catch (EntityNotFoundException $gone) {
            return 'ai_job=' . $jobId . ' status=missing';
        }

        return 'ai_job=' . $job->id . ' status=' . $job->status->value
            . ' spent=' . ($job->costCharged === null ? 'unknown' : (string) $job->costCharged)
            . ' unit=' . $job->costUnit;
    }
}
