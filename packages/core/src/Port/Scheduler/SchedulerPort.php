<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Scheduler;

/**
 * Deferred and recurring work. Joomla implements it with Scheduled Tasks
 * (plg_task_vidyalms), WordPress with Action Scheduler and WP-Cron as
 * fallback. A task with a dedupe key replaces any pending task carrying the
 * same key, so rescheduling a drip release never double-fires.
 */
interface SchedulerPort
{
    public function schedule(ScheduledTask $task): void;

    public function cancelByDedupeKey(string $dedupeKey): void;
}
