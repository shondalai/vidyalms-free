<?php

declare(strict_types=1);

/**
 * VidyaLMS: activation, schema install and the default option set.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\SystemClock;
use Shondalai\VidyaLMS\WordPress\Port\WpDatabase;
use Shondalai\VidyaLMS\WordPress\Port\WpLogger;
use Shondalai\VidyaLMS\WordPress\Port\WpScheduler;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;

defined('ABSPATH') || exit;

/**
 * What has to be true before the plugin can serve a request.
 *
 * The tables come from the neutral schema (packages/core/schema/tables.php)
 * compiled to dbDelta statements by tooling/schema/compile.php and shipped
 * inside the plugin; the job queue table is appended here because it belongs to
 * this adapter, not to the core. dbDelta is the only installer: it creates what
 * is missing and alters what has drifted, so activation after an upgrade is the
 * upgrade path.
 *
 * dbDelta stops at the shape. Everything a version has to say about existing
 * rows lives in Migrations, which runs straight after it and is the reason the
 * version is recorded by this class rather than by dbDelta's own success.
 *
 * The stored schema version is what makes an upgrade detectable. It is written
 * last, and only when the migrations for that version ran cleanly, so a run
 * that dies half way leaves the old version behind and the next activation
 * repeats the install rather than skipping it.
 */
final class Activator
{
    /**
     * Bumped whenever the compiled schema changes. Compared against the stored
     * option to decide whether dbDelta has work to do.
     *
     * tooling/schema/version.php holds the same number for the whole
     * repository, and both packagers refuse to build when the two disagree: a
     * schema version with no migration on one of the two hosts is exactly the
     * kind of silent failure this constant exists to prevent.
     *
     * At tooling/schema/floor.php, the version of the first release, this
     * constant is the whole of the recording: dbDelta creates the tables at
     * the declared shape and there is no older site to migrate, so
     * schema/migrations holds no file and needs none. The packager refuses a
     * migration file below the floor for that reason and one above this
     * constant because pending() would never reach it.
     */
    public const SCHEMA_VERSION = '1.0.1';

    public const OPTION_SCHEMA_VERSION = 'vidyalms_schema_version';

    public const OPTION_PLUGIN_VERSION = 'vidyalms_version';

    public const OPTION_ACTIVATED_AT = 'vidyalms_activated_at';

    /**
     * The one-shot marker for the seeded-data reconciliation below.
     *
     * A migration is keyed on the schema version and a data repair is not:
     * this one has to reach a site that is already current on the schema and
     * carries the wrong row anyway. The marker is what makes it run once
     * rather than on every admin request.
     */
    public const OPTION_SEEDED_DATA_VERSION = 'vidyalms_seeded_data_version';

    /**
     * Bumped when a new one-shot data reconciliation is added below.
     */
    public const SEEDED_DATA_VERSION = '1';

    /**
     * The stable key EnsureDefaultTemplate seeds the shipped certificate
     * template under, and looks it up by afterwards.
     */
    private const DEFAULT_TEMPLATE_KEY = 'vidyalms.default';

    /**
     * How long a signed private download URL stays valid, in seconds.
     */
    public const OPTION_SIGNED_URL_TTL = 'vidyalms_signed_url_ttl';

    /**
     * Whether a course a learner may not access is hidden from the catalogue or
     * shown with a purchase call to action. The catalogue screens read it.
     */
    public const OPTION_CATALOGUE_SHOW_LOCKED = 'vidyalms_catalogue_show_locked';

    /**
     * Rows a single queue drain may claim. Kept low so a shared host running
     * WP-Cron in a page request is not held open.
     */
    public const OPTION_JOB_BATCH_SIZE = 'vidyalms_job_batch_size';

    /**
     * The plugin's own scalar options and their defaults. add_option() never
     * overwrites, so reactivating a configured site changes nothing.
     *
     * The four settings an administrator edits are not here: they live in the
     * single vidyalms_settings row Setup\Settings reads and Admin\SettingsPage
     * writes, because the WordPress Settings API owns one option per screen.
     *
     * @var array<string, string>
     */
    private const DEFAULT_OPTIONS = [
        self::OPTION_JOB_BATCH_SIZE => '20',
    ];

    /**
     * The build copies tooling/schema/out/wordpress/schema.php here.
     */
    public const SCHEMA_FILE = 'schema/schema.php';

    private function __construct()
    {
    }

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            Sites::each([self::class, 'activateSite'], get_current_network_id());
            return;
        }
        self::activateSite();
    }

    public static function initializeSite(\WP_Site $site): void
    {
        if (Sites::networkActive((int) $site->network_id)) {
            Sites::on((int) $site->blog_id, [self::class, 'activateSite']);
        }
    }

    public static function activateSite(): void
    {
        try {
            if (!SchemaLock::run(static fn () => self::installSite())) {
                throw new \RuntimeException(__('VidyaLMS setup is already running for this site. Please try again.', 'vidyalms'));
            }
        } catch (\RuntimeException $failure) {
            wp_die(esc_html($failure->getMessage()));
        }
    }

    private static function installSite(): void
    {
        self::requireEnvironment();
        $from = self::recordedVersion();
        self::installSchema();

        $migrated = Migrations::run($from, self::SCHEMA_VERSION);

        Capabilities::register();
        self::installOptions();
        self::ensurePrivateStorage();
        self::scheduleQueueDrain();
        self::installRewriteRules();

        // A failed migration deliberately leaves the recorded version behind:
        // the tables are already at the new shape, and the next pass repeats
        // the statements that say what the rows in them mean. Recording the
        // version here would mean they never ran and nothing ever said so.
        if ($migrated['failed'] === []) {
            update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
            self::recordSchemaVersion();
        }

        update_option(self::OPTION_PLUGIN_VERSION, Plugin::VERSION, false);
        update_option(self::OPTION_ACTIVATED_AT, gmdate('Y-m-d H:i:s'), false);

        self::reconcileSeededData();
    }

    /** Refuse unsupported hosts before creating tables or scheduling work. */
    private static function requireEnvironment(): void
    {
        $missing = array_values(array_filter(['mbstring', 'dom', 'gd', 'iconv', 'zlib'],
            static fn (string $extension): bool => !extension_loaded($extension)));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                /* translators: %s: comma-separated PHP extension names. */
                esc_html__('VidyaLMS requires these PHP extensions: %s. Ask your host to enable them.', 'vidyalms'),
                esc_html(implode(', ', $missing)),
            ));
        }
        global $wpdb;
        $server = (string) $wpdb->db_server_info();
        $maria = stripos($server, 'MariaDB') !== false;
        // Some MariaDB installations retain the 5.5.5 compatibility prefix.
        $server = preg_replace('/^5\.5\.5-(?=\d)/', '', $server) ?? $server;
        if (preg_match('/\d+\.\d+\.\d+/', $server, $match) !== 1
            || version_compare($match[0], $maria ? '10.2.7' : '5.7.8', '<')) {
            throw new \RuntimeException(esc_html__('VidyaLMS requires MySQL 5.7.8 or MariaDB 10.2.7 or newer.', 'vidyalms'));
        }
    }

    /**
     * One-shot repairs to rows an earlier build seeded wrongly.
     *
     * Separate from Migrations because a migration is keyed on the schema
     * version and only runs when the shape changes. This has to reach a site
     * whose shape is already current: the defect is in the data, and a schema
     * drift check cannot see it because both sides match structurally.
     *
     * Cheap enough to ask on every admin request, because the marker is an
     * autoloaded option and the work behind it runs once.
     */
    public static function reconcileSeededData(): void
    {
        if (get_option(self::OPTION_SEEDED_DATA_VERSION) === self::SEEDED_DATA_VERSION) {
            return;
        }

        self::backfillDefaultTemplateKey();

        update_option(self::OPTION_SEEDED_DATA_VERSION, self::SEEDED_DATA_VERSION, true);
    }

    /**
     * Give the seeded default certificate template its stable key on a site
     * installed before the column existed.
     *
     * EnsureDefaultTemplate seeds the row with template_key
     * "vidyalms.default" and finds it by that key afterwards. Schema 1.7.0
     * added the column and did not fill it, so on every upgraded site the
     * shipped default carries NULL: a lookup by the shipped key misses where
     * a fresh install would find it, and the two builds disagree about which
     * row is the shipped template.
     *
     * template_key is unique, so this claims exactly one row: the oldest
     * unkeyed default, and only when nothing already holds the key. It is
     * narrow on purpose, so a template an administrator created and made the
     * default is never renamed under them.
     */
    private static function backfillDefaultTemplateKey(): void
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            return;
        }

        $table = $wpdb->prefix . 'vidyalms_certificate_templates';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($exists !== $table) {
            return;
        }

        $taken = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE template_key = %s',
            $table,
            self::DEFAULT_TEMPLATE_KEY
        ));

        if ($taken > 0) {
            return;
        }

        $candidate = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i'
            . " WHERE is_default = 1 AND (template_key IS NULL OR template_key = '')"
            . ' ORDER BY id ASC LIMIT 1',
            $table
        ));

        if ($candidate <= 0) {
            return;
        }

        $wpdb->update(
            $table,
            ['template_key' => self::DEFAULT_TEMPLATE_KEY],
            ['id' => $candidate],
            ['%s'],
            ['%d']
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
    }

    /**
     * True when the tables were installed by an older build than this one, so a
     * bootstrap can run the installer without waiting for a manual reactivation
     * (an updated plugin is never reactivated).
     */
    public static function needsUpgrade(): bool
    {
        return get_option(self::OPTION_SCHEMA_VERSION) !== self::SCHEMA_VERSION;
    }

    /**
     * The schema version this site has actually applied, or null when it has
     * never recorded one. Null is a site whose tables predate the option, and
     * it means every migration is pending rather than none.
     */
    /**
     * Write the applied schema version into the neutral schema_version table.
     *
     * The option above stays: it is what pending() compares against and it is
     * an autoloaded read on every request. This is the other half, and it is
     * the half both hosts share: the table the neutral schema declares is
     * where "which schema is this site at" is answered, through the core use
     * case, so the question has one shape on Joomla and WordPress alike rather
     * than an option on one host and nothing on the other.
     *
     * RecordSchemaVersion writes the row once, so reactivating a current site
     * adds nothing. A failure is swallowed: the schema is already installed and
     * the migrations already ran, and the next activation records the row.
     */
    public static function recordSchemaVersion(): void
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            return;
        }

        try {
            $recorder = new RecordSchemaVersion(
                new DatabaseSchemaVersionRepository(new WpDatabase($wpdb), new SystemClock())
            );

            $recorder->execute(self::SCHEMA_VERSION);
        } catch (\Throwable $failure) {
            (new WpLogger())->error('VidyaLMS cannot record schema version {version}: {error}', [
                'version' => self::SCHEMA_VERSION,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    public static function recordedVersion(): ?string
    {
        /** @var mixed $recorded */
        $recorded = get_option(self::OPTION_SCHEMA_VERSION);

        return \is_string($recorded) && $recorded !== '' ? $recorded : null;
    }

    /**
     * Run dbDelta, then make the database say whether it worked.
     *
     * dbDelta reports the changes it planned rather than the ones the server
     * accepted, so the second line is the load-bearing one: it reads SHOW
     * COLUMNS and SHOW INDEX per compiled table and throws when the shape it
     * finds is not the shape the compiler declared. Without it a rejected ADD
     * KEY is invisible, the migrations run happily over the columns that did
     * land, and activate() records a version whose paging indexes do not
     * exist.
     *
     * The throw is what callers already expect. activate() turns a
     * RuntimeException into wp_die, so a broken activation says so instead of
     * half succeeding, and Plugin::upgradeSchemaIfNeeded logs and leaves the
     * recorded version behind, so the next admin request tries again.
     *
     * @throws \RuntimeException when dbDelta did not reach the compiled shape
     */
    public static function installSchema(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $statements = self::statements();

        dbDelta(SchemaShape::forComparison($statements));
        SchemaShape::assertInstalled($statements);
    }

    /**
     * The compiled statements, adapted for dbDelta.
     *
     * @return list<string>
     */
    public static function statements(): array
    {
        global $wpdb;

        $file = Plugin::path(self::SCHEMA_FILE);

        /** @var mixed $compiled */
        $compiled = is_readable($file) ? require $file : null;

        if (!\is_array($compiled) || $compiled === []) {
            throw new \RuntimeException(
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                __('The VidyaLMS database schema is missing from the plugin files. Reinstall the plugin.', 'vidyalms')
            );
        }

        $statements = [];

        foreach ($compiled as $statement) {
            if (\is_string($statement) && $statement !== '') {
                $statements[] = self::forDbDelta(str_replace('{prefix}', $wpdb->prefix, $statement));
            }
        }

        $statements[] = self::forDbDelta(self::jobTable($wpdb->prefix));

        return $statements;
    }

    /**
     * dbDelta parses the statement rather than executing it blind, and it is
     * fussy about the shape:
     *
     *   - the table name on the CREATE line must not be backquoted, because the
     *     name it keys the statement by is the raw token after "CREATE TABLE"
     *   - the index type must be KEY, never INDEX
     *   - PRIMARY KEY takes two spaces before its columns
     *   - column types must be lower case, because dbDelta compares them
     *     against what SHOW COLUMNS returns with a case sensitive string
     *     comparison, and "VARCHAR(255)" against "varchar(255)" reads as a
     *     changed column: left upper case, every run rewrites every column
     *   - one field per line, and no trailing semicolon to confuse the parser
     *
     * The compiler emits everything else correctly; these are the differences
     * between its Joomla output and what dbDelta wants.
     *
     * SchemaShape::forComparison also normalises existing integer display
     * widths and MariaDB's JSON alias before dbDelta compares column types.
     */
    private static function forDbDelta(string $statement): string
    {
        $statement = preg_replace('/^(\s*CREATE TABLE\s+)`([^`]+)`/i', '$1$2', $statement) ?? $statement;
        $statement = str_replace('PRIMARY KEY (', 'PRIMARY KEY  (', $statement);

        // Only the type of a column line: the line starts with the backquoted
        // name, so the KEY lines and the table options are left alone.
        $statement = preg_replace_callback(
            '/^(\s*`[^`]+`\s+)([A-Za-z0-9_]+(?:\([^)]*\))?(?:\s+unsigned)?)/im',
            static fn (array $parts): string => $parts[1] . strtolower($parts[2]),
            $statement,
        ) ?? $statement;

        return rtrim(trim($statement), ';');
    }

    /**
     * The adapter-owned queue. It is not in the neutral schema because the core
     * has no queue: the port is served by this table plus WP-Cron here, and by
     * #__vidyalms_joomla_jobs plus Scheduled Tasks on Joomla.
     *
     * Public because it is part of what this host installs rather than an
     * implementation detail of statements(). tooling/schema/verify.php builds
     * its WordPress reference from the compiled schema plus this, which is what
     * an install actually runs; without it the drift check held a reference one
     * table short and never compared the queue at all, while its Joomla twin
     * was compared because the packager concatenates it into the install SQL.
     */
    public static function jobTable(string $prefix): string
    {
        return sprintf(
            'CREATE TABLE `%s%s` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_type` VARCHAR(100) NOT NULL,
  `payload` TEXT NULL,
  `dedupe_key` VARCHAR(190) NULL,
  `group_key` VARCHAR(190) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT \'pending\',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `run_at` DATETIME NOT NULL,
  `reserved_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wp_jobs_dedupe_key_uq` (`dedupe_key`),
  KEY `wp_jobs_group_status_ix` (`group_key`, `status`),
  KEY `wp_jobs_status_run_at_ix` (`status`, `run_at`),
  KEY `wp_jobs_status_finished_at_ix` (`status`, `finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
            $prefix,
            'vidyalms_' . WpScheduler::TABLE,
        );
    }

    private static function installOptions(): void
    {
        // Autoloaded on purpose: every one of them is read on ordinary front
        // end requests, so a separate query each would be the worse trade.
        foreach (self::DEFAULT_OPTIONS as $name => $value) {
            add_option($name, $value, '', false);
        }
    }

    /**
     * The private area and its deny rules exist from activation, not from the
     * first upload: a directory that appears later can appear unguarded if the
     * write that created it failed half way.
     */
    public static function ensurePrivateStorage(): void
    {
        $uploads = wp_upload_dir();

        if (!empty($uploads['error']) || !\is_string($uploads['basedir'] ?? null)) {
            return;
        }

        try {
            (new WpStorage(
                $uploads['basedir'],
                (string) ($uploads['baseurl'] ?? ''),
                WpStorage::PUBLIC_FOLDER,
                Settings::privateStorageDir(),
            ))->ensurePrivateRoot();
        } catch (\RuntimeException) {
            // An unwritable uploads directory is the site owner's to fix; it
            // must not block activation, because everything else still works.
            return;
        }
    }

    private static function scheduleQueueDrain(): void
    {
        if (wp_next_scheduled(Plugin::HOOK_RUN_JOBS) === false) {
            wp_schedule_event(time() + 60, Plugin::CRON_SCHEDULE, Plugin::HOOK_RUN_JOBS);
        }

        // The turn of the clock that starts every registered sweep. Its own
        // event and its own cadence, because a period is the unit it is
        // idempotent over; the drain empties the queue it fills.
        if (wp_next_scheduled(Plugin::HOOK_RUN_SWEEPS) === false) {
            wp_schedule_event(time() + 120, 'hourly', Plugin::HOOK_RUN_SWEEPS);
        }

        // The entitlement sweep, which no periodic declaration can start: it
        // is a whole-table pass on a daily cadence, and the Joomla adapter
        // runs it from its own Scheduled Task routine for the same reason.
        if (wp_next_scheduled(Plugin::HOOK_RUN_EXPIRY) === false) {
            wp_schedule_event(time() + 180, 'daily', Plugin::HOOK_RUN_EXPIRY);
        }
    }

    /**
     * Rewrite rules have to be registered before they are flushed, and the
     * activation request never fired the init hook that registers them, so the
     * routing piece exposes its own activation entry point.
     */
    private static function installRewriteRules(): void
    {
        $rewrites = 'Shondalai\\VidyaLMS\\WordPress\\Content\\Rewrites';

        if (class_exists($rewrites) && method_exists($rewrites, 'activate')) {
            $rewrites::activate();
        }
    }
}
