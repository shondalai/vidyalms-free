<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduledTask;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What a host's queue runner calls for one job type.
 *
 * The type string a use case queues (RecomputeCourseProgressForCourse::TASK_TYPE
 * and its siblings) is this provider's key, so a task type that ships without a
 * handler is a registry miss at boot rather than a failed queue row at 3am, and
 * a package that queues a type of its own is run by both adapters without either
 * adapter changing.
 */
interface ScheduledTaskHandlerInterface extends ProviderInterface
{
    /**
     * One line describing what the run did, for the host's task log.
     *
     * The exception path is reserved for programmer error. A handler that
     * owns its own retry must not also throw: two backoff regimes over one
     * unit of work compose differently under each host's runner, and the
     * runner's retries then defeat the handler's attempt cap and its jitter
     * curve never actually runs. The notification send handler is the case
     * this rule exists for; it records the outcome on its own row, sets the
     * next attempt instant, and returns a summary line, so the runner has
     * nothing to retry.
     *
     * A handler with no retry of its own may still throw to report a failure
     * the runner should retry, which is what the earlier contract said and
     * what every handler written before this sentence does.
     */
    public function run(ScheduledTaskRun $run): string;
}
