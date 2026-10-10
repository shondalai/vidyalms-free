<?php

declare(strict_types=1);

/**
 * VidyaLMS: the scheduler port, an adapter-owned queue drained by cron.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\WordPress\Plugin;

defined('ABSPATH') || exit;

/**
 * Deferred work on WordPress.
 *
 * Neither WP-Cron nor Action Scheduler is a payload store this port can rely
 * on, so the queue is a table this adapter owns: {prefix}vidyalms_wp_jobs. It
 * sits deliberately outside the neutral core schema, exactly as the Joomla
 * adapter owns #__vidyalms_joomla_jobs, because the queue is a property of the
 * host and not of the learning domain. Setup\Activator creates it alongside the
 * core tables.
 *
 * The row is always written here. What differs is the trigger:
 *
 *   - by default the recurring WP-Cron event Plugin::HOOK_RUN_JOBS drains due
 *     rows on its next pass, which is as timely as WP-Cron gets (it only fires
 *     when someone visits the site)
 *   - when Action Scheduler is present (any WooCommerce site has it), the job
 *     is additionally handed to it as a single action at run_at, which fires on
 *     its own queue runner and is both punctual and retried. The recurring
 *     event stays registered as the safety net for rows queued while Action
 *     Scheduler was absent
 *
 * A task with a dedupe key upserts on that key, so rescheduling a drip release
 * replaces the pending row instead of adding a second one, and the matching
 * Action Scheduler action is unscheduled first so the same job cannot fire
 * twice from two triggers.
 *
 * Draining is not this class's job: the runner reserves a row, dispatches it to
 * the registered task handler and stamps the outcome, the same split as
 * plg_task_vidyalms on Joomla.
 */
final class WpScheduler implements
    SchedulerPort,
    \Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks,
    // Answered by the same shared trait and the same table. It is what stops a
    // site whose WP-Cron only runs on a visited page adding a period's sweeps
    // to a queue that runs none of them, every turn, without bound.
    \Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks
{
    use \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\InspectsDatabaseQueue;

    private function queueInspector(): \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseScheduledTaskInspector
    {
        return new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseScheduledTaskInspector($this->database, $this->clock, self::TABLE, 30);
    }
    /**
     * Logical table name; WpDatabase prefixes it.
     */
    public const TABLE = 'wp_jobs';

    /**
     * The queue vocabulary. Only "pending" is written from here; the runner
     * owns running, done and failed.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Rescheduling a keyed task returns the row to a clean pending state, so
     * the reservation and completion stamps of the previous run are cleared
     * along with its error and attempt count.
     *
     * @var list<string>
     */
    private const RESCHEDULE_COLUMNS = [
        'group_key',
        'job_type',
        'payload',
        'run_at',
        'status',
        'attempts',
        'last_error',
        'reserved_at',
        'finished_at',
        'updated_at',
    ];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function schedule(ScheduledTask $task): void
    {
        if ($task->groupKey !== null && $task->dedupeKey !== $task->groupKey && $this->queueInspector()->cancelled($task->groupKey)) {
            return;
        }
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $row = [
            'job_type' => $task->type,
            'payload' => $task->payload === [] ? null : json_encode($task->payload, \JSON_THROW_ON_ERROR),
            'run_at' => $task->runAt->format(self::DATETIME_FORMAT),
            'dedupe_key' => $task->dedupeKey,
            'group_key' => $task->groupKey,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'reserved_at' => null,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // A null dedupe key never collides on the unique index, so an unkeyed
        // task is always a fresh row.
        if ($task->dedupeKey === null) {
            $jobId = $this->database->insert(self::TABLE, $row);
        } elseif ($task->oncePerKey) {
            try {
                $jobId = $this->database->insert(self::TABLE, $row);
            } catch (\Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation) {
                return;
            }
        } else {
            $this->database->upsert(self::TABLE, $row, ['dedupe_key'], self::RESCHEDULE_COLUMNS);
            $jobId = $this->jobIdForDedupeKey($task->dedupeKey);
        }

        $this->handOff($jobId, $task->runAt);
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
        $jobId = $this->jobIdForDedupeKey($dedupeKey);

        if ($jobId > 0) {
            $this->unschedule($jobId);
        }

        $this->database->delete(self::TABLE, [
            'dedupe_key' => $dedupeKey,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * True when Action Scheduler is on the site. The runner uses it to decide
     * whether a missed job will be retried for it or has to be picked up by the
     * next cron pass.
     */
    public static function hasActionScheduler(): bool
    {
        return \function_exists('as_schedule_single_action') && \function_exists('as_unschedule_all_actions');
    }

    private function jobIdForDedupeKey(string $dedupeKey): int
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->select(['id'])->where('dedupe_key', '=', $dedupeKey),
        );

        return $row === null ? 0 : (int) ($row['id'] ?? 0);
    }

    private function handOff(int $jobId, \DateTimeImmutable $runAt): void
    {
        if ($jobId <= 0 || !self::hasActionScheduler()) {
            return;
        }

        $this->unschedule($jobId);

        // The timestamp is UTC on both sides: the clock port is UTC and Action
        // Scheduler stores schedules in UTC.
        as_schedule_single_action($runAt->getTimestamp(), Plugin::HOOK_RUN_JOB, [$jobId], Plugin::JOB_GROUP);
    }

    private function unschedule(int $jobId): void
    {
        if (!self::hasActionScheduler()) {
            return;
        }

        as_unschedule_all_actions(Plugin::HOOK_RUN_JOB, [$jobId], Plugin::JOB_GROUP);
    }
}
