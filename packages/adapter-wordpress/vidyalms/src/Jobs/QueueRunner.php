<?php

declare(strict_types=1);

/**
 * VidyaLMS: the runner that drains the adapter job queue.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Jobs;

use Shondalai\VidyaLMS\Core\Application\Platform\EnqueuePeriodicTasks;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Scheduler\UnresolvedTaskType;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\WpLogger;
use Shondalai\VidyaLMS\WordPress\Port\WpScheduler;
use Shondalai\VidyaLMS\WordPress\Setup\Activator;
use Shondalai\VidyaLMS\WordPress\Setup\Settings;

defined('ABSPATH') || exit;

/**
 * The other half of WpScheduler: the port writes rows, this runs them.
 *
 * It is the WordPress counterpart of plg_task_vidyalms on Joomla and behaves
 * the same way, because a job queued by the same core use case must produce the
 * same outcome on both platforms:
 *
 *   - a batch is claimed oldest first, each row with a conditional update, so
 *     two runners racing on the same site cannot take the same job
 *   - a job that throws is retried with a backoff until its attempt limit and
 *     then recorded as failed; a poisoned job never stops the batch
 *   - a row left "running" by a run that died is returned to the queue once its
 *     reservation is older than the stuck window, with its attempt already
 *     counted so a genuinely broken job still reaches the limit
 *   - a job type nothing is registered for leaves the row pending and reported
 *     as unresolvable, never failed. A stored key that resolves to nothing
 *     means the package is missing, not that the work failed, so the run has
 *     not happened yet, which is what pending means; marking it failed
 *     destroys work that reinstalling the package would have completed
 *
 * Two triggers reach this class. Plugin::HOOK_RUN_JOBS is the recurring
 * five-minute WP-Cron event and drains a batch. Plugin::HOOK_RUN_JOB is what
 * Action Scheduler fires per job where it is installed, which is punctual;
 * both end in runJob(), and the claim makes the overlap harmless.
 *
 * The queue table is adapter-owned rather than part of the neutral schema, so
 * the claim, the requeue and the retention sweep are written against $wpdb:
 * they need "attempts = attempts + 1" and range predicates, which the core's
 * DatabasePort deliberately cannot express. No LMS table is ever touched here:
 * the one piece of housekeeping that reaches core data, deleting uploaded
 * import archives past their retention window, goes through the core's
 * PruneImportSources use case like everything else.
 */
final class QueueRunner
{
    /**
     * Tries before a job is recorded as failed. Three covers a transient
     * database blip without hammering a genuinely broken handler.
     */
    private const MAX_ATTEMPTS = 3;

    private const RETRY_BACKOFF_MINUTES = 5;

    /**
     * How long a claimed job may stay "running" before another pass may take
     * it. Longer than any job this plugin queues, so a slow job is never
     * stolen from a runner that is still working on it.
     */
    private const STUCK_MINUTES = 30;

    /**
     * Finished rows are evidence for a support question, not forever.
     */
    private const RETENTION_DAYS = 30;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const ERROR_LIMIT = 2000;

    private const OUTCOME_DONE = 'done';

    private const OUTCOME_FAILED = 'failed';

    private const OUTCOME_UNRESOLVED = 'unresolved';

    /**
     * The runner knows no job types of its own. A row's job_type is a key in
     * the core's scheduled task registry, so a package that queues work of its
     * own runs on both hosts without either adapter changing, and a type the
     * core queues can never reach a runner that has never heard of it.
     */
    private function __construct()
    {
    }

    public static function register(): void
    {
        add_action(Plugin::HOOK_RUN_JOBS, [self::class, 'drain']);
        add_action(Plugin::HOOK_RUN_JOB, [self::class, 'runJob'], 10, 1);
        add_action(Plugin::HOOK_RUN_SWEEPS, [self::class, 'startSweeps']);
        add_action(Plugin::HOOK_RUN_EXPIRY, [self::class, 'runExpiry']);
    }

    /**
     * One bounded pass over the queue. Returns what it did, so a WP-CLI command
     * or a test can report it; WP-Cron discards the value.
     *
     * @return array{claimed: int, done: int, failed: int, unresolved: int, reclaimed: int, pruned: int, sources_pruned: int}
     */
    public static function drain(): array
    {
        $result = [
            'claimed' => 0,
            'done' => 0,
            'failed' => 0,
            // Counted apart from failed on purpose: nothing went wrong with
            // these and nobody should go looking for a broken handler.
            'unresolved' => 0,
            'reclaimed' => 0,
            'pruned' => 0,
            'sources_pruned' => 0,
        ];

        $kernel = self::kernel();

        if ($kernel === null) {
            // Nothing is claimed, so no job burns an attempt on a request where
            // the engine could not be built. The next pass runs them.
            return $result;
        }

        $result['reclaimed'] = self::reclaimStuckJobs();

        foreach (self::dueJobs(self::batchSize()) as $job) {
            $id = (int) ($job['id'] ?? 0);

            if ($id <= 0 || !self::claim($id)) {
                continue;
            }

            $result['claimed']++;

            $outcome = self::dispatch(
                $kernel,
                $id,
                (string) ($job['job_type'] ?? ''),
                self::payload($job),
                (int) ($job['attempts'] ?? 0),
            );

            if ($outcome === self::OUTCOME_DONE) {
                $result['done']++;
            } elseif ($outcome === self::OUTCOME_UNRESOLVED) {
                $result['unresolved']++;
            } else {
                $result['failed']++;
            }
        }

        $result['pruned'] = self::pruneFinished();
        self::pruneIdempotency();

        // Uploaded import archives are the only queue leftovers that are not
        // rows: without this they would sit in private storage for ever, one
        // Moodle backup at a time.
        try {
            $result['sources_pruned'] = $kernel->pruneImportSources->execute(
                Settings::importSourceRetentionDays()
            )['deleted'];
        } catch (\Throwable $failure) {
            // Housekeeping never costs the pass its results: the jobs above
            // already ran, and the next pass tries the sweep again.
            (new WpLogger())->error('VidyaLMS could not prune import sources: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }

        return $result;
    }

    /**
     * One turn of the clock for every registered sweep, on its own recurring
     * event rather than inside the drain.
     *
     * A sweep has no event behind it: nothing happens when a drip window
     * opens, when a digest period closes or when a learner fails to log in, so
     * something has to notice. This runner knows no task type here either.
     * EnqueuePeriodicTasks walks the core's scheduled task registry for
     * handlers declaring RunsPeriodically, and each answers its own bin count,
     * period bucket and dedupe key, so a sweep a paid or third-party package
     * registers is started the day it registers. The Joomla adapter calls the
     * same use case from its own Scheduled Task routine.
     *
     * **Its own event and its own cadence, hourly against the drain's five
     * minutes**, because the two do different work. The drain empties a
     * bounded slice of the queue and has to run often; a turn queues one row
     * per bin for every periodic handler and is idempotent over a period. Run
     * from inside the drain it queued ninety-six rows on every five-minute
     * tick and left the queue permanently full of work the same tick could
     * not finish.
     *
     * It is idempotent inside a period, because the scheduler port replaces a
     * pending row carrying the same dedupe key. That matters more here than on
     * Joomla: WP-Cron fires on page load, so a busy site can reach this twice
     * inside one hour.
     *
     * A failure is logged and answered with zero rather than thrown: WP-Cron
     * has nobody to tell, and the next hour tries again.
     */
    public static function startSweeps(): int
    {
        $kernel = self::kernel();

        if ($kernel === null) {
            return 0;
        }

        try {
            $ports = $kernel->ports();

            return (new EnqueuePeriodicTasks(
                $kernel->registries->scheduledTasks,
                $ports->scheduler,
                $ports->clock,
            ))->execute()['enqueued'];
        } catch (\Throwable $failure) {
            (new WpLogger())->error('VidyaLMS could not start the sweeps: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);

            return 0;
        }
    }

    /**
     * The entitlement expiry sweep, on its own daily event.
     *
     * It is not a periodic handler and never will be: it is a whole-table pass
     * with nothing to shard and a daily rather than a per-period cadence, so
     * EnqueuePeriodicTasks passes it over and something has to start it. The
     * Joomla adapter starts the same use case from its own Scheduled Task
     * routine. Without this event lapsed entitlements never expire on
     * WordPress and their enrolments never suspend.
     *
     * Idempotent, so a WP-Cron double fire changes nothing on the second pass.
     * A failure is logged and answered with zeroes rather than thrown, because
     * WP-Cron has nobody to tell and tomorrow tries again.
     *
     * @return array{expired: int, suspended: int, activated: int}
     */
    public static function runExpiry(): array
    {
        $empty = ['expired' => 0, 'suspended' => 0, 'activated' => 0];
        $kernel = self::kernel();

        if ($kernel === null) {
            return $empty;
        }

        try {
            return $kernel->expireEntitlements->execute();
        } catch (\Throwable $failure) {
            (new WpLogger())->error('VidyaLMS could not run the entitlement sweep: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);

            return $empty;
        }
    }

    /**
     * One job by id, which is what Action Scheduler fires. The claim is the
     * same conditional update the batch uses, so a job the recurring drain has
     * already taken is silently skipped rather than run twice.
     */
    public static function runJob(mixed $jobId = 0): void
    {
        $id = (int) $jobId;

        if ($id <= 0) {
            return;
        }

        $kernel = self::kernel();
        $row = self::jobById($id);

        if ($kernel === null || $row === null || !self::claim($id)) {
            return;
        }

        self::dispatch(
            $kernel,
            $id,
            (string) ($row['job_type'] ?? ''),
            self::payload($row),
            (int) ($row['attempts'] ?? 0),
        );
    }

    /**
     * What became of one claimed row.
     *
     * Three answers rather than a boolean, because "did not finish" now
     * covers two different things and only one of them is anybody's problem:
     * a job that failed or was requeued, and a job left waiting for a package
     * that is not installed.
     *
     * @param array<string, mixed> $payload
     *
     * @return self::OUTCOME_*
     */
    private static function dispatch(
        CoreKernel $kernel,
        int $id,
        string $type,
        array $payload,
        int $attemptsBefore
    ): string {
        try {
            $outcome = self::run($kernel, $id, $type, $payload);

            if ($outcome === null) {
                self::leaveUnresolved($id, $type, $attemptsBefore);

                return self::OUTCOME_UNRESOLVED;
            }

            self::finish($id, WpScheduler::STATUS_DONE, null);

            return self::OUTCOME_DONE;
        } catch (\Throwable $failure) {
            if ($attemptsBefore + 1 < self::MAX_ATTEMPTS) {
                self::requeue($id, $failure->getMessage());

                return self::OUTCOME_FAILED;
            }

            self::finish($id, WpScheduler::STATUS_FAILED, $failure->getMessage());

            return self::OUTCOME_FAILED;
        }
    }

    /**
     * Translates one queue row into a core use case call. Null, never an
     * exception, for a type this adapter does not know.
     *
     * @param array<string, mixed> $payload
     */
    private static function run(CoreKernel $kernel, int $id, string $type, array $payload): ?string
    {
        return $kernel->runScheduledTask($type, $id, $payload, self::principalFrom($payload));
    }

    /**
     * A payload names the actor a job runs as. Without one the job is a guest,
     * and a use case that demands a capability refuses it cleanly rather than
     * running as whoever happened to trigger cron.
     *
     * @param array<string, mixed> $payload
     */
    private static function principalFrom(array $payload): Principal
    {
        $actor = (int) ($payload['actor_user_id'] ?? 0);

        if ($actor > 0) {
            return Principal::user($actor);
        }

        return Principal::guest();
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private static function payload(array $job): array
    {
        $raw = $job['payload'] ?? null;

        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private static function batchSize(): int
    {
        $configured = (int) get_option(Activator::OPTION_JOB_BATCH_SIZE, 20);

        return max(1, min(200, $configured));
    }

    private static function kernel(): ?CoreKernel
    {
        try {
            return Plugin::kernel();
        } catch (\Throwable) {
            return null;
        }
    }

    // Queue access. $wpdb rather than the core port: see the class docblock.

    /**
     * @return list<array<string, mixed>>
     */
    private static function dueJobs(int $batch): array
    {
        global $wpdb;

        $table = self::table();

        /** @var mixed $rows */
        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT `id`, `job_type`, `payload`, `attempts` FROM %i"
                . ' WHERE `status` = %s AND `run_at` <= %s ORDER BY `run_at` ASC, `id` ASC LIMIT %d',
                $table,
                WpScheduler::STATUS_PENDING,
                self::now(),
                $batch
            ),
            ARRAY_A
        );

        /** @var list<array<string, mixed>> */
        return \is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jobById(int $id): ?array
    {
        global $wpdb;

        $table = self::table();

        /** @var mixed $row */
        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT `id`, `job_type`, `payload`, `attempts` FROM %i WHERE `id` = %d",
                $table,
                $id
            ),
            ARRAY_A
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * The conditional update is the whole concurrency story: only the runner
     * whose UPDATE affected a row owns the job. MySQL counts changed rows, and
     * this changes status, attempts and the stamps, so the count is real.
     */
    private static function claim(int $id): bool
    {
        global $wpdb;

        $table = self::table();
        $now = self::now();

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET `status` = %s, `attempts` = `attempts` + 1,"
                . ' `reserved_at` = %s, `updated_at` = %s WHERE `id` = %d AND `status` = %s AND `attempts` < %d',
                $table,
                WpScheduler::STATUS_RUNNING,
                $now,
                $now,
                $id,
                WpScheduler::STATUS_PENDING,
                self::MAX_ATTEMPTS
            )
        );

        return (int) $affected > 0;
    }

    /**
     * A finished job clears its error rather than storing an empty string:
     * prepare() has no NULL placeholder, so the successful case is its own
     * statement.
     */
    /**
     * Only a row that is still running is stamped. A scheduled task may
     * reschedule itself under its own dedupe key while it runs, and the
     * scheduler port's upsert puts that row back to pending; stamping by id
     * alone would erase the work the task just queued.
     */
    private static function finish(int $id, string $status, ?string $error): void
    {
        global $wpdb;

        $table = self::table();
        $now = self::now();
        $running = WpScheduler::STATUS_RUNNING;

        if ($error === null) {
            // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET `status` = %s, `last_error` = NULL, `finished_at` = %s,"
                    . ' `updated_at` = %s WHERE `id` = %d AND `status` = %s',
                    $table,
                    $status,
                    $now,
                    $now,
                    $id,
                    $running
                )
            );

            return;
        }

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET `status` = %s, `last_error` = %s, `finished_at` = %s,"
                . ' `updated_at` = %s WHERE `id` = %d AND `status` = %s',
                $table,
                $status,
                mb_substr($error, 0, self::ERROR_LIMIT),
                $now,
                $now,
                $id,
                $running
            )
        );
    }

    /**
     * A job whose type nothing is registered for goes back to pending.
     *
     * The Joomla adapter's drain does exactly this, for exactly these
     * reasons, and the two must not drift: a job queued by the same core use
     * case has to end the same way on both hosts.
     *
     *   - attempts is put back to what it was before the claim, because a
     *     missing package is not a try, and the job will want its full retry
     *     ladder on the day it can run
     *   - run_at moves out by the ordinary backoff, so one absent package
     *     cannot fill every batch with the same rows; the row stays pending
     *   - finished_at is left alone, because nothing finished
     *
     * The note is the shared one, so the operations screen recognises the row
     * and says why it has been sitting there rather than showing a job that
     * is merely queued; see UnresolvedTaskType.
     */
    private static function leaveUnresolved(int $id, string $type, int $attemptsBefore): void
    {
        global $wpdb;

        $table = self::table();

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET `status` = %s, `last_error` = %s, `attempts` = %d,"
                . ' `reserved_at` = NULL, `run_at` = %s, `updated_at` = %s'
                . ' WHERE `id` = %d AND `status` = %s',
                $table,
                WpScheduler::STATUS_PENDING,
                mb_substr(UnresolvedTaskType::note($type), 0, self::ERROR_LIMIT),
                max(0, $attemptsBefore),
                self::stamp('+' . self::RETRY_BACKOFF_MINUTES . ' minutes'),
                self::now(),
                $id,
                WpScheduler::STATUS_RUNNING
            )
        );
    }

    private static function requeue(int $id, string $error): void
    {
        global $wpdb;

        $table = self::table();

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET `status` = %s, `last_error` = %s, `run_at` = %s,"
                . ' `reserved_at` = NULL, `updated_at` = %s WHERE `id` = %d',
                $table,
                WpScheduler::STATUS_PENDING,
                mb_substr($error, 0, self::ERROR_LIMIT),
                self::stamp('+' . self::RETRY_BACKOFF_MINUTES . ' minutes'),
                self::now(),
                $id
            )
        );
    }

    private static function reclaimStuckJobs(): int
    {
        global $wpdb;

        $table = self::table();

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET `status` = CASE WHEN `attempts` >= %d THEN %s ELSE %s END,"
                . ' `last_error` = %s, `reserved_at` = NULL, `run_at` = %s, `finished_at` = CASE WHEN `attempts` >= %d THEN %s ELSE NULL END, `updated_at` = %s'
                . ' WHERE `status` = %s AND `reserved_at` IS NOT NULL AND `reserved_at` < %s',
                $table,
                self::MAX_ATTEMPTS,
                WpScheduler::STATUS_FAILED,
                WpScheduler::STATUS_PENDING,
                'The previous worker stopped before completing this job.',
                self::stamp('+' . self::RETRY_BACKOFF_MINUTES . ' minutes'),
                self::MAX_ATTEMPTS,
                self::now(),
                self::now(),
                WpScheduler::STATUS_RUNNING,
                self::stamp('-' . self::STUCK_MINUTES . ' minutes')
            )
        );

        return max(0, (int) $affected);
    }

    private static function pruneIdempotency(): void
    {
        global $wpdb;
        // Bounded expiry deletion from our own table; caching a mutation is not applicable.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE expires_at <= %s LIMIT 1000',
            $wpdb->prefix . 'vidyalms_idempotency_keys', self::stamp('now'),
        ));
    }

    private static function pruneFinished(): int
    {
        global $wpdb;

        $table = self::table();

        // Atomic claiming and state changes use the private queue table; cached reads would duplicate or lose work.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE `status` IN (%s, %s, %s)"
                . ' AND `finished_at` IS NOT NULL AND `finished_at` < %s',
                $table,
                WpScheduler::STATUS_DONE,
                WpScheduler::STATUS_FAILED,
                'cancelled',
                self::stamp('-' . self::RETENTION_DAYS . ' days')
            )
        );

        return max(0, (int) $affected);
    }

    /**
     * Bound through the %i identifier placeholder: the name uses the site prefix
     * and two constants, never from a request.
     */
    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vidyalms_' . WpScheduler::TABLE;
    }

    private static function now(): string
    {
        return gmdate(self::DATETIME_FORMAT);
    }

    private static function stamp(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify($modifier)
            ->format(self::DATETIME_FORMAT);
    }
}
