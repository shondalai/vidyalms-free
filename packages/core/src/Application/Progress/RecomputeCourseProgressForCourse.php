<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/**
 * Recomputes every enrolment of one course, in batches, through the
 * scheduler.
 *
 * Completion is a pure function over progress, so a change to what the
 * course contains changes who has finished it. Archiving a lesson is the
 * case that matters: a learner who had done everything else is now finished,
 * and the certificate and the mail that go with it are only fired by a
 * recompute. Nothing else notices, because a heartbeat is what usually
 * triggers one and a finished learner sends no more heartbeats.
 *
 * It is queued rather than inline because a course may carry thousands of
 * enrolments and each recompute is several queries plus, on the transition,
 * an event fan-out. Doing that inside the request that archived one lesson
 * is how an authoring click times out halfway and leaves a course in which
 * some learners were told they finished and some were not.
 *
 * A run takes BATCH_SIZE enrolments in id order and schedules a continuation
 * when it filled the batch, so one task is bounded whatever the course size.
 * Each enrolment is recomputed as the learner who owns it, exactly as the
 * quiz and assignment listeners do: the recompute needs no administrator,
 * and a task runner has no session to borrow one from.
 */
final class RecomputeCourseProgressForCourse
{
    /** The scheduled task type both adapters register a handler for. */
    public const TASK_TYPE = 'vidyalms.course.recompute_progress';

    /** How many enrolments one run of the task recomputes. */
    public const BATCH_SIZE = 200;

    public function __construct(
        private readonly RecomputeCourseProgress $recompute,
        private readonly DatabasePort $database,
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * One pending recompute per course, and one pending continuation per
     * point reached. A second archive while the first run is still going
     * replaces the pending run from the start, which is correct rather than
     * wasteful: a recompute is idempotent and the second archive changed the
     * answer for everyone.
     *
     * The continuation carries the enrolment it resumes after, so it can
     * never collide with the row the runner is currently draining. Sharing
     * one key destroyed the batch: an adapter upserts on the key, which reset
     * the running row to pending, and the runner then stamped that same row
     * done, so a course with more than BATCH_SIZE enrolments recomputed the
     * first batch and stopped.
     */
    public static function dedupeKeyFor(int $courseId, int $afterEnrolmentId = 0): string
    {
        $key = self::TASK_TYPE . ':' . $courseId;

        return $afterEnrolmentId > 0 ? $key . ':' . $afterEnrolmentId : $key;
    }

    public function queue(int $courseId, int $afterEnrolmentId = 0): void
    {
        $this->scheduler->schedule(new ScheduledTask(
            self::TASK_TYPE,
            ['course_id' => $courseId, 'after_enrolment_id' => $afterEnrolmentId],
            $this->clock->now(),
            self::dedupeKeyFor($courseId, $afterEnrolmentId),
        ));
    }

    /**
     * What the host's task runner calls when the scheduled task fires.
     *
     * There is no caller principal: cron has no session, and none is needed
     * because every recompute acts as the owner of the enrolment it is
     * recomputing.
     *
     * @return int how many enrolments this run recomputed
     */
    public function execute(int $courseId, int $afterEnrolmentId = 0): int
    {
        // A cross-context read of the Access tables: the Progress context
        // never writes them, and an event would have to carry the whole
        // roster to say the same thing.
        $rows = $this->database->selectAll(
            Query::from('enrolments')
                ->select(['id', 'user_id'])
                ->where('course_id', '=', $courseId)
                ->where('id', '>', $afterEnrolmentId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE),
        );

        $lastId = $afterEnrolmentId;

        foreach ($rows as $row) {
            $enrolmentId = (int) $row['id'];
            $userId = (int) $row['user_id'];
            $lastId = $enrolmentId;

            if ($userId < 1) {
                continue;
            }

            $this->recompute->execute(Principal::user($userId), $enrolmentId);
        }

        if (\count($rows) === self::BATCH_SIZE) {
            $this->queue($courseId, $lastId);
        }

        return \count($rows);
    }
}
