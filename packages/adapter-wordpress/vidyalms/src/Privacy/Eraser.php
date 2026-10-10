<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress personal data eraser.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Privacy;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials\SqlCredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Erasure of a learner's VidyaLMS record, driven by WordPress Tools > Erase
 * Personal Data. Writes go through the core DatabasePort, never through $wpdb.
 *
 * What is deleted, and what is anonymised instead
 * -----------------------------------------------
 * Deleted outright, because the row exists only to describe this person:
 * entitlements, enrolments, lesson and course progress, video positions, quiz
 * attempts with their answers and grades, assignment submissions with their
 * file records, certificates, activity log entries, email log entries and the
 * person's own course instructor assignments.
 *
 * Anonymised, because the row belongs to somebody else and deleting it would
 * destroy that third party's record or a shared aggregate. In every case the
 * identifying column is set to 0, which the core reads as "nobody":
 *
 *   attempt_grades.grader_id  a grade the subject awarded on another learner's
 *                             attempt. That learner keeps their result; only
 *                             the grader's identity goes.
 *   submissions.grader_id     the same, for assignment grading.
 *   entitlements.granted_by   the subject enrolled somebody else. That person
 *                             keeps their access; the granting identity goes.
 *   courses.created_by        the subject authored a course other learners are
 *                             enrolled on. Deleting the course would erase
 *                             every one of their enrolments, attempts and
 *                             certificates, which is other people's data.
 *   import_jobs.created_by    an audit row for a bulk import that shaped
 *                             content other people still use.
 *
 * Aggregate integrity is the reason for the split: a course, its lessons and
 * everybody else's results survive the erasure of one participant, and no
 * counter or report changes value because a person left.
 *
 * Submission files and rendered certificates are recorded as rows here and
 * removed with them. The stored bytes live behind the Storage port and are
 * removed by the core privacy contributor through the Storage port. Failed
 * file deletions stay in the cleanup queue for retry after database erasure.
 *
 * Erasure runs in one page: every statement is keyed on a primary key or an
 * indexed user id, so the work is bounded by one learner's own records.
 */
final class Eraser
{
    /**
     * The key WordPress files this eraser under. Stable across releases: a
     * rename would strand in-flight erasure requests.
     */
    public const ERASER_KEY = 'vidyalms';

    private ?\Closure $portsResolver;
    private bool $lastErasureFailed = true;

    /**
     * @param callable(): Ports|null $portsResolver the plugin bootstrap's way to
     *                                              the one shared ports bundle
     */
    public function __construct(?callable $portsResolver = null)
    {
        $this->portsResolver = $portsResolver === null ? null : \Closure::fromCallable($portsResolver);
    }

    /**
     * @param callable(): Ports|null $portsResolver
     */
    public static function boot(?callable $portsResolver = null): self
    {
        $eraser = new self($portsResolver);

        add_filter('wp_privacy_personal_data_erasers', [$eraser, 'register']);
        if (!is_multisite()) {
            add_action('delete_user', [$eraser, 'deleteUser'], 10, 1);
        }
        add_action('wpmu_delete_user', [$eraser, 'deleteNetworkUser'], 10, 1);
        add_action('vidyalms_privacy_retry', [$eraser, 'retryDeletion'], 10, 1);
        add_action('admin_notices', [$eraser, 'pendingNotice']);

        return $eraser;
    }

    /**
     * @param mixed $erasers
     *
     * @return array<string, array{eraser_friendly_name: string, callback: callable}>
     */
    public function register($erasers): array
    {
        $registered = \is_array($erasers) ? $erasers : [];

        $registered[self::ERASER_KEY] = [
            'eraser_friendly_name' => __('VidyaLMS learning record', 'vidyalms'),
            'callback'             => [$this, 'erase'],
        ];

        return $registered;
    }

    /**
     * The eraser callback. WordPress checks the erase capability and the request
     * nonce before it reaches this point.
     *
     * @param mixed $emailAddress
     * @param mixed $page
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase($emailAddress, $page = 1): array
    {
        $email    = \is_string($emailAddress) ? $emailAddress : '';
        $user = $email === '' ? false : get_user_by('email', $email);
        return $this->eraseUser($user instanceof \WP_User ? (int) $user->ID : 0, $email);
    }

    /** Called before WordPress removes the identity needed to locate its records. */
    public function deleteUser($userId): void
    {
        $user = get_user_by('id', (int) $userId);
        if (!$user instanceof \WP_User) {
            return;
        }
        // Capture the identity before WordPress deletes it. Failures must not
        // abort core account deletion or lose the subject needed for a retry.
        update_option('vidyalms_privacy_pending_' . (int) $userId, (string) $user->user_email, false);
        $this->retryDeletion((int) $userId);
    }

    public function retryDeletion($userId): void
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            return;
        }
        $email = get_option('vidyalms_privacy_pending_' . $userId, null);
        if (!is_string($email)) {
            return;
        }
        try {
            $this->eraseUser($userId, $email);
        } catch (\Throwable $failure) {
            $this->lastErasureFailed = true;
            $this->log('retry deletion', $failure);
        }
        if (!$this->lastErasureFailed) {
            delete_option('vidyalms_privacy_pending_' . $userId);
            wp_clear_scheduled_hook('vidyalms_privacy_retry', [$userId]);
            return;
        }
        if (!wp_next_scheduled('vidyalms_privacy_retry', [$userId])) {
            wp_schedule_single_event(time() + DAY_IN_SECONDS, 'vidyalms_privacy_retry', [$userId]);
        }
    }

    public function pendingNotice(): void
    {
        if (!current_user_can('erase_others_personal_data')) {
            return;
        }
        global $wpdb;
        // Read current erasure work, including retries that have completed since the last request.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
            $wpdb->esc_like('vidyalms_privacy_pending_') . '%',
        ));
        if ($pending !== null) {
            echo '<div class="notice notice-warning"><p>' . esc_html__(
                'VidyaLMS is retrying learning-record erasure for a deleted account. Check organization privacy controls and the site error log if this notice persists.',
                'vidyalms',
            ) . '</p></div>';
        }
    }

    public function deleteNetworkUser($userId): void
    {
        \Shondalai\VidyaLMS\WordPress\Setup\Sites::each(function () use ($userId): void {
            $this->deleteInstalledSiteUser((int) $userId);
        });
    }

    private function deleteInstalledSiteUser(int $userId): void
    {
        if (get_option(\Shondalai\VidyaLMS\WordPress\Setup\Activator::OPTION_SCHEMA_VERSION, false) !== false) {
            // Plugin::resetSiteServices rebuilds ports after each blog switch.
            // Sites without VidyaLMS tables must not boot the learning engine.
            $this->deleteUser($userId);
        }
    }

    /** Also used by host lifecycle code before a site's membership is removed. */
    public function eraseUser(int $userId, string $email): array
    {
        $this->lastErasureFailed = true;
        $lock = null;
        try {
            $lock = \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::acquireCustomerLock($userId);
            return $this->eraseLockedUser($userId, $email);
        } catch (\Throwable $error) {
            $this->log('erase', $error);
            return $this->result(false, true, [__('VidyaLMS could not finish erasure. Run the erasure request again.', 'vidyalms')]);
        } finally {
            \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::releaseCustomerLock($lock);
        }
    }

    private function eraseLockedUser(int $userId, string $email): array
    {
        $this->lastErasureFailed = true;
        $messages = [];

        $database = $this->database();

        if ($database === null) {
            return $this->result(false, true, [__('VidyaLMS could not reach its database, so nothing was erased.', 'vidyalms')]);
        }

        if (Contributors::resolve($database)->requiresReview($userId, $email)) {
            return $this->result(false, true, [__(
                'VidyaLMS organization relationships or retained ledger records require review. Use organization privacy controls first; no learning records were erased by this request.',
                'vidyalms'
            )]);
        }

        $failed = false;

        $suppressed = 0;

        if ($userId > 0) {
            // A status list slot outlives the credential it was allocated to,
            // and it must: the index is printed inside a document somebody may
            // still hold, so reusing it would make one holder's revocation
            // answer for another's. Retiring the entries before the rows go is
            // the only way a publisher can tell the difference between a
            // credential in force and one that no longer exists; without it a
            // deleted credential reads as valid in every wallet for ever.
            //
            // It runs before the erasure because it reads the certificate rows
            // the erasure is about to remove. It is the one place this file
            // still names a learning table, and it names it to publish a
            // revocation rather than to decide what gets erased.
            $this->retireStatusEntries(
                $database,
                $this->idsOf($database, 'certificates', 'user_id', $userId, $failed),
                $failed
            );
        }
        if ($failed) {
            return $this->result(false, true, [__('VidyaLMS could not retire credential status entries. Run the erasure request again.', 'vidyalms')]);
        }

        // Every table, from the registry, in one call. This file used to hold
        // its own list of ten and its own list of five pairs beside it, so
        // adding a table to the core meant editing this host and the other one.
        // Persist a barrier across crashes between learning-record erasure and
        // purchase suppression. A retry clears it only after both have finished.
        $commerce = $userId > 0 && \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::available();
        if ($commerce) {
            update_option('vidyalms_commerce_erasure_pending_' . $userId, true, false);
            if (!get_option('vidyalms_commerce_erasure_pending_' . $userId, false)) {
                throw new \RuntimeException('Could not suspend purchase synchronization during erasure.');
            }
        }
        $outcome    = Contributors::resolve($database)->eraseSubjectRecords($userId, $email);
        $removed    = array_sum($outcome['erased']);
        $anonymised = array_sum($outcome['anonymised']);

        foreach ($outcome['warnings'] as $warning) {
            $failed = true;
            $this->log('erase', new \RuntimeException($warning));
        }

        if (!$failed) {
            try {
                $suppressed = \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::eraseCustomerOrders($userId);
            } catch (\Throwable $error) {
                $failed = true;
                $this->log('suppress shop access', $error);
            }
        }

        if ($anonymised > 0) {
            $messages[] = __(
                'VidyaLMS kept records that belong to other learners (courses this person authored, and grades this person awarded) and removed their identity from them, so no other learner lost their own results.',
                'vidyalms'
            );
        }

        if ($failed) {
            $messages[] = __('VidyaLMS could not erase part of the learning record. Check the site error log and run the request again.', 'vidyalms');
        }

        if ($suppressed > 0) {
            $messages[] = __('VidyaLMS retained only an order-ID suppression marker for old shop purchases to prevent erased access being restored. Purchase snapshots and payment markers were removed.', 'vidyalms');
        }

        // Anonymised records no longer retain the subject's personal data.
        if (!$failed && $commerce) {
            delete_option('vidyalms_commerce_erasure_pending_' . $userId);
        }
        $this->lastErasureFailed = $failed;
        return $this->result($removed > 0 || $anonymised > 0 || $suppressed > 0, $failed || $suppressed > 0, $messages);
    }

    /**
     * @param list<string> $messages
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    private function result(bool $removed, bool $retained, array $messages): array
    {
        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        ];
    }

    /**
     * @return list<int>
     */
    private function idsOf(DatabasePort $database, string $table, string $column, int $value, bool &$failed): array
    {
        $ids = [];

        try {
            $rows = $database->selectAll(Query::from($table)->select(['id'])->where($column, '=', $value));
        } catch (\Throwable $error) {
            $failed = true;
            $this->log('read(' . $table . ')', $error);

            return [];
        }

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $certificateIds
     */
    private function retireStatusEntries(DatabasePort $database, array $certificateIds, bool &$failed): void
    {
        if ($certificateIds === []) {
            return;
        }

        $ports = $this->ports();

        if ($ports === null) {
            return;
        }

        $statusLists = new SqlCredentialStatusRepository($database, $ports->clock, $ports->random);
        $now         = $ports->clock->now();

        foreach ($certificateIds as $certificateId) {
            try {
                $statusLists->retire(IssuanceRecord::SUBJECT_CERTIFICATE, $certificateId, $now);
            } catch (\Throwable $error) {
                $failed = true;
                $this->log('retire(credential_status_entries)', $error);
            }
        }
    }

    private function database(): ?DatabasePort
    {
        $ports = $this->ports();

        return $ports === null ? null : $ports->database;
    }

    private function ports(): ?Ports
    {
        if ($this->portsResolver !== null) {
            $ports = ($this->portsResolver)();

            return $ports instanceof Ports ? $ports : null;
        }

        if (function_exists('vidyalms_ports')) {
            $ports = vidyalms_ports();

            return $ports instanceof Ports ? $ports : null;
        }

        return null;
    }

    /**
     * A failed erasure has to be recoverable from a debug log, and nothing
     * about it may reach the requester. Silent unless the site owner asked for
     * debugging.
     */
    private function log(string $operation, \Throwable $error): void
    {
        if (!\defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log('VidyaLMS privacy eraser ' . $operation . ' failed: ' . $error->getMessage());
    }
}
