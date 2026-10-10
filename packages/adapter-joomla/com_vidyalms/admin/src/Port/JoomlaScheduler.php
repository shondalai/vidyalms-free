<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/**
 * Deferred work on Joomla.
 *
 * Joomla's Scheduled Tasks run plugin-defined routines on a timer; they are not
 * a queue and cannot carry a payload, so this adapter owns one:
 * #__vidyalms_joomla_jobs. The table is deliberately outside the neutral core
 * schema because the same port on WordPress is served by Action Scheduler,
 * which brings its own storage. plg_task_vidyalms drains the queue.
 *
 * A task with a dedupe key upserts on that key, so rescheduling a drip release
 * replaces the pending row instead of adding a second one.
 *
 * @since 1.0.0
 */
final class JoomlaScheduler implements
    SchedulerPort,
    \Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks,
    // Answered by the same shared trait and the same table. It is what stops a
    // site whose task plugin is disabled adding a period's sweeps to a queue
    // that runs none of them, every hour, without bound.
    \Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks
{
    use \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\InspectsDatabaseQueue;

    private function queueInspector(): \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseScheduledTaskInspector
    {
        return new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseScheduledTaskInspector($this->database, $this->clock, self::TABLE);
    }
    public const TABLE = 'joomla_jobs';

    /**
     * The queue vocabulary. plg_task_vidyalms owns the other three
     * (running, done, failed); only "pending" is written from here.
     */
    public const STATUS_PENDING = 'pending';

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

        // A null dedupe key never collides on the unique index, so an
        // unkeyed task is always a fresh row.
        if ($task->dedupeKey === null) {
            $this->database->insert(self::TABLE, $row);

            return;
        }

        if ($task->oncePerKey) {
            try {
                $this->database->insert(self::TABLE, $row);
            } catch (\Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation) {
                // This periodic bucket already ran or is in progress.
            }
            return;
        }
        $this->database->upsert(self::TABLE, $row, ['dedupe_key'], self::RESCHEDULE_COLUMNS);
    }

    public function cancelByDedupeKey(string $dedupeKey): void
    {
        $this->database->delete(self::TABLE, [
            'dedupe_key' => $dedupeKey,
            'status' => self::STATUS_PENDING,
        ]);
    }
}
