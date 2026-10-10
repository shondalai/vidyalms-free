<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunQueuedImport;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

final class RunImportTask implements ScheduledTaskHandlerInterface
{
    public function __construct(private readonly RunQueuedImport $import)
    {
    }

    public function key(): string
    {
        return QueueImportJob::TASK_TYPE;
    }

    /**
     * The job row already carries the importer, the source, the options and the
     * person who asked for it, so the payload is only its id. RunQueuedImport
     * takes no principal: it runs as the job's creator and re-checks MANAGE at
     * this moment, and it refuses a job that is not pending, so a redelivery
     * cannot restart an import already halfway.
     */
    public function run(ScheduledTaskRun $run): string
    {
        $job = $this->import->execute($run->int('job_id'));

        return 'import_job=' . $job->id . ' status=' . $job->status;
    }
}
