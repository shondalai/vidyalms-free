<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Scheduler;

/**
 * A scheduler that can say how much unstarted work it is already holding, per
 * task type.
 *
 * A capability interface rather than a method on SchedulerPort, for the reason
 * the whole port layer states about its own additions: a required new member
 * turns a core upgrade into a fatal on every adapter that has not been
 * rebuilt. An adapter that does not declare this keeps the behaviour it has
 * today, which is correct and merely noisy.
 *
 * **What it is for.** A periodic sweep is enqueued once per period per bin,
 * deduped on a key that carries the period bucket. Two turns of the clock in
 * one period therefore collapse to one row, which is what the dedupe key was
 * built for. Two turns in two *different* periods do not, and must not: they
 * are two windows. But on a site whose queue is not being drained (a broken
 * cron, a disabled task plugin, a host that only runs WP-Cron on a visited
 * page) every turn adds another period's rows to a queue that runs none of
 * them, and both development sites' job tables grew exactly that way, by one
 * job per missed window per burst.
 *
 * The rows are not wrong; there is simply no point queueing a fourth period of
 * work behind three that have not started, and a table that grows without
 * bound on an unattended site is a support call whose cause is invisible.
 *
 * **What it must count.** Only work that has not started: status pending, in
 * whatever vocabulary the host's own queue uses. Running, done, failed and
 * cancelled rows are history and must not hold a sweep back.
 */
interface CountsPendingTasks
{
    /**
     * @return array<string, int> task type to the number of jobs of that type
     *                            waiting to start, types with none omitted
     */
    public function pendingCountsByType(): array;
}
