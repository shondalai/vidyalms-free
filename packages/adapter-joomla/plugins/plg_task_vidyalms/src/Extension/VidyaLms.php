<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_task_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Plugin\Task\VidyaLms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Shondalai\VidyaLMS\Core\Application\Platform\EnqueuePeriodicTasks;
use Shondalai\VidyaLMS\Core\Application\Platform\PruneImportSources;
use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Scheduler\UnresolvedTaskType;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

/**
 * The VidyaLMS scheduler runner. Three routines, all orchestration and no
 * learning rules: every decision is taken by a core use case reached through
 * the CoreKernel on the global container.
 *
 *   vidyalms.drain_jobs           drain the adapter job queue written by the
 *                                 JoomlaScheduler port
 *   vidyalms.expire_entitlements  run the core entitlement sweep
 *   vidyalms.prune                housekeeping over spent idempotency keys,
 *                                 finished job rows and uploaded import
 *                                 sources past their retention window
 *
 * The queue table this plugin drains is adapter-owned, not part of the neutral
 * core schema. Its contract is: id, job_type, payload (JSON object), dedupe_key,
 * status (pending|running|done|failed), attempts, last_error, run_at,
 * reserved_at, finished_at, created_at, updated_at, all datetimes UTC.
 *
 * @since 1.0.0
 */
final class VidyaLms extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * The routine ids this plugin advertises to com_scheduler. They are the
     * host's own task types and share no namespace with the core's scheduled
     * task registry keys, which name work queued into #__vidyalms_joomla_jobs
     * and are resolved by the core rather than here.
     *
     * ROUTINE_EXPIRE_ENTITLEMENTS is public because the component reads
     * #__scheduler_tasks for it to tell the Settings and Dashboard screens how
     * often the sweep runs. A drift between the two would not throw: both
     * screens would report no sweep at all.
     */
    public const ROUTINE_DRAIN_JOBS = 'vidyalms.drain_jobs';

    public const ROUTINE_EXPIRE_ENTITLEMENTS = 'vidyalms.expire_entitlements';

    public const ROUTINE_PRUNE = 'vidyalms.prune';

    /**
     * The turn of the clock every sweep needs.
     *
     * A sweep has no event behind it: nothing happens when a drip window
     * opens, when a digest period closes or when a learner fails to log in, so
     * something has to notice. This routine is that something, and it knows no
     * task type: EnqueuePeriodicTasks walks the core's scheduled task registry
     * for handlers declaring RunsPeriodically, and each answers its own bin
     * count, period bucket and dedupe key. A sweep a paid or third-party
     * package registers is started here the day it registers, with no change
     * to this plugin, which is the same property that lets the drain run a job
     * type this adapter has never heard of.
     *
     * Run it as often as the shortest period any registered sweep uses; the
     * enqueue is idempotent inside a period, so running it more often than
     * that costs one replaced row rather than a second send.
     */
    public const ROUTINE_SWEEPS = 'vidyalms.sweeps';

    protected const TASKS_MAP = [
        'vidyalms.easycommerce_sync' => [
            'langConstPrefix' => 'PLG_TASK_VIDYALMS_EASYCOMMERCE',
            'method' => 'reconcileEasyCommerce',
            'form' => 'easycommerce_sync',
        ],
        self::ROUTINE_DRAIN_JOBS => [
            'langConstPrefix' => 'PLG_TASK_VIDYALMS_DRAIN',
            'method'          => 'drainJobs',
            'form'            => 'drain_jobs',
        ],
        self::ROUTINE_EXPIRE_ENTITLEMENTS => [
            'langConstPrefix' => 'PLG_TASK_VIDYALMS_EXPIRE',
            'method'          => 'expireEntitlements',
            'form'            => 'expire_entitlements',
        ],
        self::ROUTINE_PRUNE => [
            'langConstPrefix' => 'PLG_TASK_VIDYALMS_PRUNE',
            'method'          => 'prune',
            'form'            => 'prune',
        ],
        self::ROUTINE_SWEEPS => [
            'langConstPrefix' => 'PLG_TASK_VIDYALMS_SWEEPS',
            'method'          => 'sweeps',
            'form'            => 'sweeps',
        ],
    ];

    private const JOB_TABLE = '#__vidyalms_joomla_jobs';

    private const IDEMPOTENCY_TABLE = '#__vidyalms_idempotency_keys';

    private const STATUS_PENDING = 'pending';

    private const STATUS_RUNNING = 'running';

    private const STATUS_DONE = 'done';

    private const STATUS_FAILED = 'failed';

    /**
     * The runner knows no job types of its own. A row's job_type is a key in
     * the core's scheduled task registry, which is what lets a package queue
     * work of its own and have both hosts run it without either adapter
     * changing, and what stops a type the core queues from reaching a runner
     * that has never heard of it. A payload key "actor_user_id" names the
     * principal a job runs as; use cases that demand a capability fail cleanly
     * and are recorded on the row when it is absent.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    // Routines

    protected function reconcileEasyCommerce(ExecuteTaskEvent $event): int
    {
        try {
            $result = \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\Integration::reconcile(
                (int) $this->param($event->getArgument('params'), 'batch_size', 100)
            );
            $this->logTask(Text::sprintf('PLG_TASK_VIDYALMS_EASYCOMMERCE_RESULT', $result['processed'], $result['failed']));
            return $result['failed'] > 0 ? TaskStatus::KNOCKOUT : TaskStatus::OK;
        } catch (\Throwable $failure) {
            \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\Integration::log($failure, 'reconciliation');
            return TaskStatus::KNOCKOUT;
        }
    }

    /**
     * Drain due jobs oldest first, one bounded batch per run. Each job is
     * claimed with a conditional update so two concurrent runners cannot take
     * the same row, and every job is dispatched inside its own try/catch: a
     * poisoned job records its error and the batch continues.
     */
    protected function drainJobs(ExecuteTaskEvent $event): int
    {
        // A live connection owns the drain, even beyond stuck_minutes. Another
        // worker may reclaim expired reservations only after that owner exits.
        try {
            $lock = new \VidyaLMS\Component\VidyaLMS\Administrator\Service\JobRunnerLock($this->getDatabase(), JPATH_ROOT);
            return $lock->run(fn (): int => $this->drainLockedJobs($event)) ?? TaskStatus::OK;
        } catch (\Throwable $failure) {
            $this->logTask($failure->getMessage(), 'error');
            return TaskStatus::KNOCKOUT;
        }
    }

    private function drainLockedJobs(ExecuteTaskEvent $event): int
    {
        $params      = $event->getArgument('params');
        $batch       = max(1, (int) $this->param($params, 'batch_size', 25));
        $maxAttempts = max(1, (int) $this->param($params, 'max_attempts', 3));
        $stuck       = max(0, (int) $this->param($params, 'stuck_minutes', 30));
        $backoff     = max(0, (int) $this->param($params, 'retry_backoff_minutes', 5));

        $kernel = $this->kernel();

        if ($kernel === null) {
            return $this->kernelMissing();
        }

        try {
            $reclaimed = $stuck > 0 ? $this->reclaimStuckJobs($stuck, $maxAttempts, $backoff) : 0;
            $jobs      = $this->dueJobs($batch);
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return TaskStatus::KNOCKOUT;
        }

        $done       = 0;
        $failed     = 0;
        $skipped    = 0;
        $unresolved = 0;
        $outcomes   = [];

        foreach ($jobs as $job) {
            $id = (int) $job['id'];

            try {
                if (!$this->claimJob($id, $maxAttempts)) {
                    $skipped++;

                    continue;
                }
            } catch (\Throwable $e) {
                $this->logTask($e->getMessage(), 'error');
                $skipped++;

                continue;
            }

            $type    = (string) $job['job_type'];
            $payload = $this->decodePayload($job['payload'] ?? null);

            try {
                $outcome = $this->dispatchJob($kernel, $id, $type, $payload);

                if ($outcome === null) {
                    // A type nothing answers to is a missing package, not
                    // failed work: the row is left pending, carries the
                    // reason, and runs on its own once the package is back.
                    // Never a reason to abort the batch either.
                    $this->logTask(
                        Text::sprintf('PLG_TASK_VIDYALMS_DRAIN_UNRESOLVED_TYPE', $type, $id),
                        'warning'
                    );
                    $this->leaveJobUnresolved($id, $type, (int) ($job['attempts'] ?? 0), $backoff);
                    $unresolved++;

                    continue;
                }

                $this->finishJob($id, self::STATUS_DONE, null);
                $outcomes[$id] = $type . ' ' . $outcome;
                $done++;
            } catch (\Throwable $e) {
                $attempts = (int) ($job['attempts'] ?? 0) + 1;

                if ($attempts < $maxAttempts) {
                    $this->requeueJob($id, $e->getMessage(), $backoff);
                } else {
                    $this->finishJob($id, self::STATUS_FAILED, $e->getMessage());
                    $failed++;
                }

                $this->logTask(
                    Text::sprintf('PLG_TASK_VIDYALMS_DRAIN_JOB_FAILED', $id, $type, $attempts, $e->getMessage()),
                    'warning'
                );
            }
        }

        $this->logTask(Text::sprintf('PLG_TASK_VIDYALMS_DRAIN_DONE', $done, $failed, $skipped, $reclaimed));

        // Its own line rather than a fifth number on the one above, so a
        // batch that resolved everything reads exactly as it always has and
        // the run that did not is the one that says something extra.
        if ($unresolved > 0) {
            $this->logTask(Text::sprintf('PLG_TASK_VIDYALMS_DRAIN_UNRESOLVED', $unresolved), 'warning');
        }

        $event->setArgument('snapshot', [
            'done'       => $done,
            'failed'     => $failed,
            'skipped'    => $skipped,
            'unresolved' => $unresolved,
            'reclaimed'  => $reclaimed,
            'outcomes'   => $outcomes,
        ]);

        return TaskStatus::OK;
    }

    /**
     * The core entitlement sweep: lapsed entitlements expire and their
     * enrolments suspend, future-dated entitlements activate. Idempotent, so
     * running it twice in a row changes nothing on the second pass.
     */
    protected function expireEntitlements(ExecuteTaskEvent $event): int
    {
        $kernel = $this->kernel();

        if ($kernel === null) {
            return $this->kernelMissing();
        }

        try {
            $result = $kernel->expireEntitlements->execute();
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return TaskStatus::KNOCKOUT;
        }

        $this->logTask(Text::sprintf(
            'PLG_TASK_VIDYALMS_EXPIRE_DONE',
            $result['expired'],
            $result['suspended'],
            $result['activated']
        ));
        $event->setArgument('snapshot', $result);

        return TaskStatus::OK;
    }

    /**
     * One turn of the clock for every registered sweep.
     *
     * It queues rows; it runs none. The drain routine picks them up and
     * resolves each type in the core's scheduled task registry, exactly as it
     * does for a certificate render or a queued import, so a sweep and an
     * event-driven job travel the same path and there is one retry regime over
     * the whole queue rather than two.
     */
    protected function sweeps(ExecuteTaskEvent $event): int
    {
        $kernel = $this->kernel();

        if ($kernel === null) {
            return $this->kernelMissing();
        }

        try {
            $ports = $kernel->ports();

            $result = (new EnqueuePeriodicTasks(
                $kernel->registries->scheduledTasks,
                $ports->scheduler,
                $ports->clock,
            ))->execute();
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return TaskStatus::KNOCKOUT;
        }

        $this->logTask(Text::sprintf(
            'PLG_TASK_VIDYALMS_SWEEPS_DONE',
            $result['enqueued'],
            \count($result['tasks']),
            \count($result['skipped'])
        ));

        if ($result['skipped'] !== []) {
            $this->logTask(
                Text::sprintf('PLG_TASK_VIDYALMS_SWEEPS_SKIPPED', implode(', ', $result['skipped'])),
                'warning'
            );
        }

        $event->setArgument('snapshot', $result);

        return TaskStatus::OK;
    }

    /**
     * Retention housekeeping. Idempotency keys carry their own expiry, so the
     * cutoff is that expiry plus an optional grace; finished job rows are cut
     * on a configurable age.
     */
    protected function prune(ExecuteTaskEvent $event): int
    {
        $params     = $event->getArgument('params');
        $graceDays  = max(0, (int) $this->param($params, 'idempotency_grace_days', 1));
        $jobDays    = max(1, (int) $this->param($params, 'job_retention_days', 30));
        $sourceDays = max(1, (int) $this->param(
            $params,
            'import_source_retention_days',
            \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::configuration()
                ->get('import_source_retention_days', PruneImportSources::DEFAULT_RETENTION_DAYS)
        ));

        $kernel = $this->kernel();

        if ($kernel === null) {
            return $this->kernelMissing();
        }

        try {
            $keys    = $this->pruneIdempotencyKeys($graceDays);
            $jobs    = $this->pruneFinishedJobs($jobDays);
            $sources = $kernel->pruneImportSources->execute($sourceDays);
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return TaskStatus::KNOCKOUT;
        }

        $this->logTask(Text::sprintf(
            'PLG_TASK_VIDYALMS_PRUNE_DONE',
            $keys,
            $jobs,
            $sources['deleted'],
            $graceDays,
            $jobDays,
            $sourceDays
        ));
        $event->setArgument('snapshot', [
            'idempotency_keys'             => $keys,
            'jobs'                         => $jobs,
            'import_sources'               => $sources['deleted'],
            'import_jobs_cleared'          => $sources['cleared'],
            'grace_days'                   => $graceDays,
            'job_retention_days'           => $jobDays,
            'import_source_retention_days' => $sourceDays,
        ]);

        return TaskStatus::OK;
    }

    // Job dispatch

    /**
     * Translate one queue row into a core use case call. Returns null, never
     * throws, when the type is not one this adapter knows.
     *
     * @param array<string, mixed> $payload
     */
    private function dispatchJob(CoreKernel $kernel, int $jobId, string $type, array $payload): ?string
    {
        return $kernel->runScheduledTask($type, $jobId, $payload, $this->principalFrom($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function principalFrom(array $payload): Principal
    {
        $actor = (int) ($payload['actor_user_id'] ?? 0);

        return $actor > 0 ? Principal::user($actor) : Principal::guest();
    }

    // Queue access

    /**
     * @return list<array<string, mixed>>
     */
    private function dueJobs(int $batch): array
    {
        $db      = $this->getDatabase();
        $now     = $this->now();
        $pending = self::STATUS_PENDING;

        $query = $db->createQuery()
            ->select($db->quoteName(['id', 'job_type', 'payload', 'attempts']))
            ->from($db->quoteName(self::JOB_TABLE))
            ->where($db->quoteName('status') . ' = :status')
            ->where($db->quoteName('run_at') . ' <= :now')
            ->order($db->quoteName('run_at') . ' ASC')
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':status', $pending)
            ->bind(':now', $now)
            ->setLimit($batch);

        return (array) $db->setQuery($query)->loadAssocList();
    }

    private function claimJob(int $id, int $maxAttempts): bool
    {
        $db      = $this->getDatabase();
        $now     = $this->now();
        $stamp   = $now;
        $running = self::STATUS_RUNNING;
        $pending = self::STATUS_PENDING;

        $query = $db->createQuery()
            ->update($db->quoteName(self::JOB_TABLE))
            ->set($db->quoteName('status') . ' = :running')
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->set($db->quoteName('reserved_at') . ' = :reserved')
            ->set($db->quoteName('updated_at') . ' = :updated')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('status') . ' = :pending')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER)
            ->bind(':running', $running)
            ->bind(':reserved', $now)
            ->bind(':updated', $stamp)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':pending', $pending);

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows() > 0;
    }

    /**
     * Only a row that is still running is stamped. A scheduled task may
     * reschedule itself under its own dedupe key while it runs, and the port's
     * upsert puts that row back to pending; stamping by id alone would erase
     * the work the task just queued.
     */
    private function finishJob(int $id, string $status, ?string $error): void
    {
        $db       = $this->getDatabase();
        $now      = $this->now();
        $stamp    = $now;
        $trimmed  = $error === null ? null : mb_substr($error, 0, 2000);
        $errType  = $trimmed === null ? ParameterType::NULL : ParameterType::STRING;
        $running  = self::STATUS_RUNNING;

        $query = $db->createQuery()
            ->update($db->quoteName(self::JOB_TABLE))
            ->set($db->quoteName('status') . ' = :status')
            ->set($db->quoteName('last_error') . ' = :error')
            ->set($db->quoteName('finished_at') . ' = :finished')
            ->set($db->quoteName('updated_at') . ' = :updated')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('status') . ' = :running')
            ->bind(':status', $status)
            ->bind(':error', $trimmed, $errType)
            ->bind(':finished', $now)
            ->bind(':updated', $stamp)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':running', $running);

        $db->setQuery($query)->execute();
    }

    private function requeueJob(int $id, string $error, int $backoffMinutes): void
    {
        $db      = $this->getDatabase();
        $pending = self::STATUS_PENDING;
        $runAt   = $this->clock()->modify('+' . $backoffMinutes . ' minutes')->format('Y-m-d H:i:s');
        $stamp   = $this->now();
        $trimmed = mb_substr($error, 0, 2000);

        $query = $db->createQuery()
            ->update($db->quoteName(self::JOB_TABLE))
            ->set($db->quoteName('status') . ' = :status')
            ->set($db->quoteName('last_error') . ' = :error')
            ->set($db->quoteName('run_at') . ' = :runat')
            ->set($db->quoteName('updated_at') . ' = :updated')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':status', $pending)
            ->bind(':error', $trimmed)
            ->bind(':runat', $runAt)
            ->bind(':updated', $stamp)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
    }

    /**
     * A job whose type nothing is registered for goes back to pending.
     *
     * This is the ScheduledTask miss direction the standards page publishes,
     * and it is the direction the Importer registry already follows: a stored
     * key that resolves to nothing means the package is missing, not that the
     * work failed, so the run has not happened yet, which is exactly what
     * pending means. Marking the row failed destroys work that reinstalling
     * the package would have completed, and a live row on this very site was
     * lost that way during a window when Pro was not registered.
     *
     * Three details carry the direction rather than merely restating it:
     *
     *   - attempts is put back to what it was before the claim, because a
     *     missing package is not a try. Otherwise a fortnight of drains eats
     *     the retry ladder the job will need on the day it can finally run.
     *   - run_at moves out by the ordinary retry backoff, so one absent
     *     package cannot fill every batch with the same rows and starve the
     *     work that can run. The row stays pending throughout.
     *   - finished_at is not touched. Nothing finished.
     *
     * The note is the shared one, so the operations screen recognises the row
     * and says why it has been sitting there; see UnresolvedTaskType.
     */
    private function leaveJobUnresolved(int $id, string $type, int $attemptsBefore, int $backoffMinutes): void
    {
        $db       = $this->getDatabase();
        $pending  = self::STATUS_PENDING;
        $running  = self::STATUS_RUNNING;
        $note     = mb_substr(UnresolvedTaskType::note($type), 0, 2000);
        $runAt    = $this->clock()->modify('+' . $backoffMinutes . ' minutes')->format('Y-m-d H:i:s');
        $stamp    = $this->now();
        $attempts = max(0, $attemptsBefore);

        $query = $db->createQuery()
            ->update($db->quoteName(self::JOB_TABLE))
            ->set($db->quoteName('status') . ' = :status')
            ->set($db->quoteName('last_error') . ' = :error')
            ->set($db->quoteName('attempts') . ' = :attempts')
            ->set($db->quoteName('reserved_at') . ' = NULL')
            ->set($db->quoteName('run_at') . ' = :runat')
            ->set($db->quoteName('updated_at') . ' = :updated')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('status') . ' = :running')
            ->bind(':status', $pending)
            ->bind(':error', $note)
            ->bind(':attempts', $attempts, ParameterType::INTEGER)
            ->bind(':runat', $runAt)
            ->bind(':updated', $stamp)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':running', $running);

        $db->setQuery($query)->execute();
    }

    /**
     * A run that dies mid-batch leaves rows stuck in "running". Return them to
     * the queue once they are older than the reservation window; the attempt
     * counter already recorded the try, so a genuinely poisoned job still
     * reaches its attempt limit.
     */
    private function reclaimStuckJobs(int $stuckMinutes, int $maxAttempts, int $backoff): int
    {
        $db      = $this->getDatabase();
        $pending = self::STATUS_PENDING;
        $running = self::STATUS_RUNNING;
        $cutoff  = $this->clock()->modify('-' . $stuckMinutes . ' minutes')->format('Y-m-d H:i:s');
        $stamp   = $this->now();

        $failed = self::STATUS_FAILED;
        $retry = $this->clock()->modify('+' . $backoff . ' minutes')->format('Y-m-d H:i:s');
        $error = 'The previous worker stopped before completing this job.';
        $query = $db->createQuery()
            ->update($db->quoteName(self::JOB_TABLE))
            ->set($db->quoteName('status') . ' = CASE WHEN attempts >= ' . (int) $maxAttempts . ' THEN :failed ELSE :pending END')
            ->set($db->quoteName('reserved_at') . ' = NULL')
            ->set($db->quoteName('finished_at') . ' = CASE WHEN attempts >= ' . (int) $maxAttempts . ' THEN :finished ELSE NULL END')
            ->set($db->quoteName('last_error') . ' = :error')
            ->set($db->quoteName('run_at') . ' = :retry')
            ->set($db->quoteName('updated_at') . ' = :updated')
            ->where($db->quoteName('status') . ' = :running')
            ->where($db->quoteName('reserved_at') . ' < :cutoff')
            ->bind(':pending', $pending)
            ->bind(':failed', $failed)
            ->bind(':finished', $stamp)
            ->bind(':error', $error)
            ->bind(':retry', $retry)
            ->bind(':updated', $stamp)
            ->bind(':running', $running)
            ->bind(':cutoff', $cutoff);

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * Range deletes are outside what the core's DatabasePort can express, so
     * retention runs against the driver. It removes rows only, never touching
     * learning state.
     */
    private function pruneIdempotencyKeys(int $graceDays): int
    {
        $db     = $this->getDatabase();
        $cutoff = $this->clock()->modify('-' . $graceDays . ' days')->format('Y-m-d H:i:s');

        $query = $db->createQuery()
            ->delete($db->quoteName(self::IDEMPOTENCY_TABLE))
            ->where($db->quoteName('expires_at') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows();
    }

    private function pruneFinishedJobs(int $retentionDays): int
    {
        $db     = $this->getDatabase();
        $cutoff = $this->clock()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');
        $done   = self::STATUS_DONE;
        $failed = self::STATUS_FAILED;

        $query = $db->createQuery()
            ->delete($db->quoteName(self::JOB_TABLE))
            ->whereIn($db->quoteName('status'), [$done, $failed, 'cancelled'], ParameterType::STRING)
            ->where($db->quoteName('finished_at') . ' IS NOT NULL')
            ->where($db->quoteName('finished_at') . ' < :cutoff')
            ->bind(':cutoff', $cutoff);

        $db->setQuery($query)->execute();

        return (int) $db->getAffectedRows();
    }

    // Helpers

    /**
     * The shared kernel, or null when this site cannot build one.
     *
     * Through KernelFactory rather than straight off the container, and the
     * reason is the console. A web request reaches com_vidyalms through
     * plg_system_vidyalms on onAfterInitialise, and the component's
     * provider.php is what puts the kernel on the container. ConsoleApplication
     * dispatches no onAfterInitialise at all, so on "php cli/joomla.php
     * scheduler:run", which is how a production site runs its scheduler, the
     * component is never booted and the container has never heard of the
     * kernel. Asking it directly answered "missing" on exactly those runs:
     * every routine here returned KNOCKOUT, the queue never drained, and the
     * only trace was one line in the task log.
     *
     * KernelFactory::kernel() boots the component when the service is absent,
     * which is the documented way in for a plugin, a module or a CLI command.
     */
    private function kernel(): ?CoreKernel
    {
        // The component ships in the same package as this plugin, but a site
        // can have the plugin enabled with the component removed, and a fatal
        // in a scheduled task is worse than a skipped run.
        if (!class_exists(KernelFactory::class)) {
            return null;
        }

        try {
            return KernelFactory::kernel();
        } catch (\Throwable) {
            return null;
        }
    }

    private function kernelMissing(): int
    {
        $this->logTask(Text::_('PLG_TASK_VIDYALMS_KERNEL_MISSING'), 'warning');

        return TaskStatus::KNOCKOUT;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The wall clock, read fresh every time.
     *
     * Deliberately not Factory::getDate('now', 'UTC'), which memoises: it
     * caches the constructed Date under the key "now-UTC" for the life of the
     * process and hands back a clone of the first one forever. In a web
     * request that is invisible, because the request is over in milliseconds.
     * In a CLI process it is not, and "php cli/joomla.php scheduler:run" is
     * exactly a CLI process: every routine in one run would share the instant
     * the process booted.
     *
     * Three things break on that. A row this run queues after boot is stamped
     * with the real clock and is then never due against a frozen "now", so the
     * sweeps routine and the drain routine cannot cooperate inside one run. A
     * retry backoff computed as frozen-now plus five minutes can already be in
     * the past, so a failing job burns its whole attempt budget in one pass
     * rather than backing off. And every reserved_at, finished_at and
     * updated_at written by a long run records the boot time rather than the
     * time anything happened, which is the evidence a support question needs.
     */
    private function now(): string
    {
        return $this->clock()->format('Y-m-d H:i:s');
    }

    private function clock(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Read a task param tolerantly (Registry get() or stdClass property). */
    private function param(mixed $params, string $key, mixed $default): mixed
    {
        if (\is_object($params)) {
            if (method_exists($params, 'get')) {
                return $params->get($key, $default);
            }

            if (isset($params->$key)) {
                return $params->$key;
            }
        }

        return $default;
    }
}
