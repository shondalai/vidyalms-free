<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgressForCourse;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * What an archive queues. The run itself schedules its own continuation when
 * it filled a batch, so the handler is bounded whatever the course size.
 */
final class RecomputeCourseProgressTask implements ScheduledTaskHandlerInterface
{
    public function __construct(private readonly RecomputeCourseProgressForCourse $recompute)
    {
    }

    public function key(): string
    {
        return RecomputeCourseProgressForCourse::TASK_TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $courseId = $run->int('course_id');
        $after = $run->int('after_enrolment_id');
        $count = $this->recompute->execute($courseId, $after);

        return 'course=' . $courseId . ' after=' . $after . ' recomputed=' . $count;
    }
}
