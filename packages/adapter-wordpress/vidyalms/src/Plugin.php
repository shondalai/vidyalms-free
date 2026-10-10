<?php

declare(strict_types=1);

/**
 * VidyaLMS: the plugin bootstrap and the one composition root.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Application\Commerce\BackfillEntitlementDigests;
use Shondalai\VidyaLMS\Core\Application\Organisation\BackfillAssignmentReferences;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\KernelReady;
use Shondalai\VidyaLMS\Core\Boot\PortsReady;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\WordPress\Admin\Menu;
use Shondalai\VidyaLMS\WordPress\Admin\SettingsPage;
use Shondalai\VidyaLMS\WordPress\Port\HostGroupIdentitySource;
use Shondalai\VidyaLMS\WordPress\Port\SystemClock;
use Shondalai\VidyaLMS\WordPress\Port\WpAuthorization;
use Shondalai\VidyaLMS\WordPress\Port\WpCache;
use Shondalai\VidyaLMS\WordPress\Port\WpCommerce;
use Shondalai\VidyaLMS\WordPress\Port\WpCourseCategories;
use Shondalai\VidyaLMS\WordPress\Port\WpDatabase;
use Shondalai\VidyaLMS\WordPress\Port\WpEventBridge;
use Shondalai\VidyaLMS\WordPress\Port\WpHttp;
use Shondalai\VidyaLMS\WordPress\Port\WpLocale;
use Shondalai\VidyaLMS\WordPress\Port\WpLogger;
use Shondalai\VidyaLMS\WordPress\Port\WpMailer;
use Shondalai\VidyaLMS\WordPress\Port\WpRandom;
use Shondalai\VidyaLMS\WordPress\Port\WpRecipientProfile;
use Shondalai\VidyaLMS\WordPress\Port\WpScheduler;
use Shondalai\VidyaLMS\WordPress\Port\WpSecrets;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;
use Shondalai\VidyaLMS\WordPress\Port\WpTranslator;
use Shondalai\VidyaLMS\WordPress\Port\WpUsers;
use Shondalai\VidyaLMS\WordPress\Provider\SiteSelfEnrolmentRule;
use Shondalai\VidyaLMS\WordPress\Rest\RestController;
use Shondalai\VidyaLMS\WordPress\Setup\Activator;
use Shondalai\VidyaLMS\WordPress\Setup\Capabilities;
use Shondalai\VidyaLMS\WordPress\Setup\Migrations;
use Shondalai\VidyaLMS\WordPress\Setup\Settings;
use Shondalai\VidyaLMS\WordPress\Setup\SchemaLock;
use Shondalai\VidyaLMS\WordPress\Setup\Sites;

/**
 * The single entry point into the learning engine from WordPress.
 *
 * Everything else in the plugin reaches the core through Plugin::kernel() (or
 * the global vidyalms_kernel(), which is the same object) and then uses the
 * kernel's use cases. Nothing else constructs a CoreKernel and nothing
 * anywhere re-implements a rule the kernel already owns.
 *
 * One kernel per request, built on first use and never twice. A second kernel
 * would mean a second event bus and a second idempotency store, which is how a
 * completion gets announced twice and a replayed request stops being a replay.
 * It is built lazily rather than at plugin load because constructing it writes
 * the provider ledger, and a front end page that never asks a learning question
 * should not pay for that.
 *
 * The port implementations hold no request state, so the bundle is safe to
 * share across everything running in the request.
 */
final class Plugin
{
    public const VERSION = '1.0.1';

    public const TEXT_DOMAIN = 'vidyalms';

    /**
     * The recurring WP-Cron event that drains the job queue. Registered on
     * activation, healed on init, cleared on deactivation.
     */
    public const HOOK_RUN_JOBS = 'vidyalms_run_jobs';

    /**
     * One queued job, by id. Action Scheduler fires it where it is installed;
     * the queue drain fires it otherwise. Signature: (int $jobId).
     */
    public const HOOK_RUN_JOB = 'vidyalms_run_job';

    /**
     * The recurring event that starts every registered sweep.
     *
     * Its own event rather than a step inside the drain, and hourly rather
     * than every five minutes, because the two do different work on different
     * cadences: the drain empties a bounded slice of the queue and has to run
     * often, while a turn of the clock queues one row per bin for every
     * periodic handler and only needs to happen once per period. Folding it
     * into the drain made every five-minute tick queue ninety-six rows and
     * turned "a finished job is not claimed again" into a false alarm, which
     * is the shape of the mistake rather than an incidental symptom of it.
     *
     * Registered on activation, healed on init, cleared on deactivation, the
     * same way the drain is.
     */
    public const HOOK_RUN_SWEEPS = 'vidyalms_run_sweeps';

    /**
     * The recurring event that runs the entitlement expiry sweep.
     *
     * Its own event because the sweep has no way of being started otherwise.
     * A handler is queued by the clock only when it declares RunsPeriodically,
     * and the entitlement sweep deliberately does not: it is a whole-table
     * pass that cannot be sharded and has to run once a day rather than once a
     * period. The Joomla adapter runs it from its own Scheduled Task routine,
     * vidyalms.expire_entitlements, for exactly the same reason, so this is
     * the WordPress half of one decision rather than a second one.
     *
     * Without it lapsed entitlements never expire on WordPress and their
     * enrolments never suspend, which is invisible until a learner keeps
     * access to a course they stopped paying for.
     */
    public const HOOK_RUN_EXPIRY = 'vidyalms_run_expiry';

    /**
     * The Action Scheduler group everything this plugin queues belongs to, so a
     * site owner can see and cancel it in one place.
     */
    public const JOB_GROUP = 'vidyalms';

    public const CRON_SCHEDULE = 'vidyalms_five_minutes';

    /**
     * Fired once the plugin has registered its own hooks and booted its
     * subsystems. Signature: (Plugin $plugin).
     */
    public const HOOK_BOOTED = 'vidyalms_booted';

    /**
     * Fired once, immediately after the kernel exists, before anyone has used
     * it: the place for a Pro package or a third party to register providers
     * into $kernel->registries. Signature:
     * (CoreKernel $kernel, KernelReady $event).
     *
     * The second argument is the core's own seam contract
     * (Shondalai\VidyaLMS\Core\Boot\KernelReady), carrying the kernel, its
     * ports and its registries, so a listener that must build a repository
     * has one without reaching back into this plugin. A listener registered
     * with the default accepted_args of 1 keeps working unchanged, which is
     * why it is a second argument rather than a changed first.
     *
     * The adapter listens first and replaces the "open" enrolment rule with the
     * site's self enrolment switch. A listener that replaces the key again is
     * handed that wrapper back by replace() and has to delegate to it, or the
     * switch stops meaning anything.
     */
    public const HOOK_KERNEL_READY = KernelReady::WORDPRESS_HOOK;

    /**
     * The port-contribution moment, one step before the kernel exists.
     * Signature: (PortsReady $collector).
     *
     * `Ports` is final and readonly and this plugin builds it, so a paid
     * package holding an implementation of one of the optional ports had
     * nowhere to put it. A listener seats one on the collector this hook
     * carries, and the kernel is then built from the sealed bundle. It fires
     * before the kernel because everything the kernel builds eagerly is handed
     * the port it was given.
     */
    public const HOOK_PORTS_READY = PortsReady::WORDPRESS_HOOK;

    /**
     * Filter that supplies the PDF renderer. Signature: (null $port): ?PdfPort
     *
     * Applied once per request while this plugin builds its Ports, before the
     * kernel exists, so a listener has to be registered at plugin load. A
     * plugin that bundles a renderer returns an object implementing
     * Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort, and the core then registers
     * its PDF certificate renderer beside the HTML ones.
     *
     * This plugin ships no PDF renderer and answers null itself: certificates
     * render as HTML, with the verification code, and print from the browser.
     * Designs published as PDF under 1.0.0 keep issuing too, because without a
     * port the core registers a stand-in that draws them as HTML.
     * The VidyaLMS Pro add-on answers this filter with its own renderer.
     * Anything that is not a PdfPort is ignored and logged, so a mistaken
     * listener costs PDF output and never the site. A PdfPort seated on
     * vidyalms_ports_ready replaces whatever this filter answered.
     */
    public const FILTER_PDF_PORT = 'vidyalms_pdf_port';

    /**
     * Fallback accessors for code that cannot see this class. Both answer with
     * the shared instance. Signature: (mixed $value): CoreKernel|DatabasePort
     */
    public const FILTER_KERNEL = 'vidyalms_kernel';

    public const FILTER_DATABASE = 'vidyalms_database';

    /**
     * Filter over the certificate verification base a printed certificate
     * points at. Signature: (string $baseUrl): string
     */
    public const FILTER_VERIFICATION_BASE_URL = 'vidyalms_verification_base_url';

    /**
     * Filter over the base a machine-readable credential id is built on, and
     * over the base a Bitstring status list is published at. Signature:
     * (string $baseUrl): string.
     *
     * Both default to this site's own REST mount, because that is what serves
     * them. They are separate from the verification base on purpose: the
     * verification page is a human surface with a rewrite rule of its own,
     * and a wallet dereferencing a credential id is not asking for a page.
     */
    public const FILTER_CREDENTIAL_BASE_URL = 'vidyalms_credential_base_url';

    public const FILTER_STATUS_LIST_BASE_URL = 'vidyalms_status_list_base_url';

    /**
     * Filter over the RFC 8058 one-click unsubscribe base. Signature:
     * (string $baseUrl): string. It must answer an https address or the queue
     * holds non-transactional mail back rather than sending it headerless.
     */
    public const FILTER_UNSUBSCRIBE_BASE_URL = 'vidyalms_unsubscribe_base_url';

    /**
     * Filter over the domain a bounce is addressed back to. Signature:
     * (string $domain): string. The channel's provider settings row overrides
     * whatever it answers.
     */
    public const FILTER_ENVELOPE_DOMAIN = 'vidyalms_envelope_domain';

    /**
     * The user id background work acts as. Signature: (int $userId): int
     */
    public const FILTER_SYSTEM_USER = 'vidyalms_system_user';

    /**
     * tooling/i18n/compile.php writes packages/core/i18n/catalogue.php here,
     * beside the gettext.php the translator port asks for translations.
     */
    private const CATALOGUE_FILE = 'src/I18n/catalogue.php';

    /**
     * The subsystems this plugin boots, in order.
     *
     * An autoloaded class is never loaded until something names it, so the
     * bootstrap has to name them: an action alone would fire before any of
     * these files existed. Every entry is guarded by class_exists and
     * method_exists, so a piece that is not built yet, or that a build left
     * out, is skipped rather than fatal.
     *
     * The third element is the resolvers the entry point is handed, in order,
     * so a subsystem reaches the one shared kernel rather than building its
     * own. The fourth restricts it to wp-admin.
     *
     * @var list<array{0: string, 1: string, 2: list<string>, 3: bool}>
     */
    private const SUBSYSTEMS = [
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\CourseCpt', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\Rewrites', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\CourseSync', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\Templates', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\LearnerApps', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\Blocks', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\Shortcodes', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\InstructorPage', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\NotificationPages', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\OrganisationLanding', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\AchievementsLanding', 'register', [], false],
        // The learner's own AI activity. It mounts the credential bundle under
        // its own screen key rather than starting a fourth entry.
        ['Shondalai\\VidyaLMS\\WordPress\\Content\\AiActivityLanding', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Files\\DownloadEndpoint', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Jobs\\QueueRunner', 'register', [], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Rest\\RestController', 'boot', ['kernel'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Rest\\UploadController', 'boot', ['kernel', 'ports'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Rest\\FeedbackController', 'boot', ['kernel'], false],
        // The free identity clause's one call. It registers a listener and
        // builds nothing, so a request that logs nobody in pays for nothing.
        ['Shondalai\\VidyaLMS\\WordPress\\Identity\\HostAssertion', 'register', ['kernel'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Privacy\\Exporter', 'boot', ['ports'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Privacy\\Eraser', 'boot', ['ports'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Commerce\\WooCommerce', 'boot', ['kernel', 'ports'], false],
        ['Shondalai\\VidyaLMS\\WordPress\\Admin\\Menu', 'boot', [], true],
    ];

    private static ?self $instance = null;

    private ?Ports $ports = null;

    private ?CoreKernel $kernel = null;

    private ?WpDatabase $database = null;

    private ?WpStorage $storage = null;

    private ?WpScheduler $scheduler = null;

    private ?WpTranslator $translator = null;

    private ?WpLogger $logger = null;

    private ?int $administratorId = null;

    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * The shared kernel for this request. The documented way in for every
     * screen, hook, job and integration; vidyalms_kernel() is the same call for
     * code that would rather not name a class.
     */
    public static function kernel(): CoreKernel
    {
        return self::instance()->coreKernel();
    }

    /**
     * The shared ports bundle, for code that needs one service rather than the
     * whole engine (the privacy exporter reads through the database port).
     */
    public static function ports(): Ports
    {
        return self::instance()->portBundle();
    }

    /**
     * Read access to the LMS tables. Writes never come through here: they go
     * through the kernel's use cases, which is where the rules are.
     */
    public static function database(): DatabasePort
    {
        return self::instance()->databasePort();
    }

    /**
     * The actor a background grant runs as: a WooCommerce order completed by a
     * webhook or by cron has no logged in user, and granting an entitlement is
     * an authorized action that has to name someone who may perform it.
     *
     * Never the current user. On an ordinary checkout the current user is the
     * customer, who does not hold vidyalms_enrol, and the grant would be
     * refused. The site's first administrator stands in, and a site with a
     * dedicated service account names it through the filter.
     */
    public static function systemPrincipal(): ?Principal
    {
        $userId = (int) apply_filters('vidyalms_system_user', self::instance()->administratorId());

        return $userId > 0 ? Principal::user($userId) : null;
    }

    public static function storage(): WpStorage
    {
        return self::instance()->storagePort();
    }

    public static function scheduler(): WpScheduler
    {
        return self::instance()->schedulerPort();
    }

    public static function translator(): TranslatorPort
    {
        return self::instance()->translatorPort();
    }

    /**
     * An absolute path inside the plugin directory, with a trailing slash on
     * the base.
     */
    public static function path(string $relative = ''): string
    {
        $base = \defined('VIDYALMS_PLUGIN_DIR')
            ? (string) \constant('VIDYALMS_PLUGIN_DIR')
            : \dirname(__DIR__) . '/';

        return rtrim($base, '/\\') . '/' . ltrim($relative, '/');
    }

    /**
     * A URL to an asset inside the plugin directory.
     */
    public static function url(string $relative = ''): string
    {
        $base = \defined('VIDYALMS_PLUGIN_URL')
            ? (string) \constant('VIDYALMS_PLUGIN_URL')
            : plugin_dir_url(\dirname(__DIR__) . '/vidyalms.php');

        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    /**
     * Registers everything and boots the subsystems. Called once, from
     * vidyalms.php, while WordPress is loading plugins.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_filter(self::FILTER_KERNEL, [$this, 'filterKernel']);
        add_filter(self::FILTER_DATABASE, [$this, 'filterDatabase']);

        // Registered here rather than with the subsystems, which boot on
        // plugins_loaded: the kernel is built on first use, and a request that
        // asks for it earlier must still get the site policy installed.

        // WordPress.org translations load just in time from the plugin header.
        add_action('init', [$this, 'ensureQueueDrain'], 20);
        add_action('admin_init', [$this, 'upgradeSchemaIfNeeded']);
        add_action('init', [$this, 'scheduleSchemaUpgrade'], 21);
        add_action('vidyalms_upgrade_schema', [$this, 'backgroundSchemaUpgrade']);
        add_action('wp_initialize_site', [Activator::class, 'initializeSite'], 200);
        add_action('wp_uninitialize_site', [Setup\Uninstaller::class, 'uninitializeSite'], 5);
        add_filter('wpmu_drop_tables', [Setup\Uninstaller::class, 'siteTablesToDrop'], 10, 2);
        add_action('switch_blog', [$this, 'resetSiteServices']);

        // On plugins_loaded rather than here: a subsystem that asks whether
        // WooCommerce is installed has to be asking after every plugin file has
        // been included, and a listener on vidyalms_booted registered by another
        // plugin has to have had its chance to register.
        add_action('plugins_loaded', [$this, 'bootSubsystems']);
    }

    /**
     * WP-Cron has nothing between "hourly" and "twicedaily", and a drip release
     * or a reminder that is an hour late is a support ticket.
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public function cronSchedules(mixed $schedules): array
    {
        $registered = \is_array($schedules) ? $schedules : [];

        $registered[self::CRON_SCHEDULE] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every five minutes (VidyaLMS)', 'vidyalms'),
        ];

        return $registered;
    }

    public function resetSiteServices(): void
    {
        $this->ports = null;
        $this->kernel = null;
        $this->database = null;
        $this->storage = null;
        $this->scheduler = null;
        $this->translator = null;
        $this->administratorId = null;
    }

    /**
     * The event is registered on activation, but a site can lose it: a cron
     * plugin clearing everything, a database restored from before activation,
     * an import. Without it the queue silently stops.
     */
    public function ensureQueueDrain(): void
    {
        if (wp_next_scheduled(self::HOOK_RUN_JOBS) === false) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::HOOK_RUN_JOBS);
        }

        // Hourly, because a turn of the clock queues one row per bin for every
        // periodic handler and a period is the unit it is idempotent over.
        // Losing it is the same silent failure as losing the drain: the sweeps
        // stay registered and nothing ever starts them.
        if (wp_next_scheduled(self::HOOK_RUN_SWEEPS) === false) {
            wp_schedule_event(time() + (2 * MINUTE_IN_SECONDS), 'hourly', self::HOOK_RUN_SWEEPS);
        }

        // Daily, because entitlement windows are dated rather than timed and a
        // whole-table pass has no bins to spread.
        if (wp_next_scheduled(self::HOOK_RUN_EXPIRY) === false) {
            wp_schedule_event(time() + (3 * MINUTE_IN_SECONDS), 'daily', self::HOOK_RUN_EXPIRY);
        }
    }

    /**
     * An updated plugin is never reactivated, so the schema upgrade has to run
     * itself. It runs in wp-admin only, once per version: a front end request
     * must never find itself running DDL.
     *
     * dbDelta first, then the migrations for the versions this site has not
     * applied, then the recorded version. The order is the whole point: a
     * migration writes into columns dbDelta has just added, and the version is
     * recorded last so a failure anywhere means the next admin request tries
     * again rather than declaring the site upgraded.
     */
    public function upgradeSchemaIfNeeded(): void
    {
        // admin_init also runs for anonymous admin-post/admin-ajax requests.
        if (!current_user_can('manage_options')) {
            return;
        }
        // Each site upgrades in its own context; network-admin requests must
        // not synchronously walk an entire network.
        SchemaLock::run(fn () => $this->upgradeSiteSchema());
    }

    public function scheduleSchemaUpgrade(): void
    {
        if (get_option(Activator::OPTION_SCHEMA_VERSION, false) !== false && Activator::needsUpgrade()
            && !wp_next_scheduled('vidyalms_upgrade_schema')) {
            wp_schedule_single_event(time() + 60, 'vidyalms_upgrade_schema');
        }
    }

    public function backgroundSchemaUpgrade(): void
    {
        try {
            SchemaLock::run(fn () => $this->upgradeSiteSchema());
        } finally {
            if (Activator::needsUpgrade() && !wp_next_scheduled('vidyalms_upgrade_schema')) {
                wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'vidyalms_upgrade_schema');
            }
        }
    }

    private function upgradeSiteSchema(): void
    {
        // Ahead of the version gate, because a data repair has to reach a
        // site whose schema is already current and whose rows are not. It
        // costs one autoloaded option read once the repair has run.
        try {
            Activator::reconcileSeededData();
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS could not reconcile seeded data: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }

        // Ahead of the schema gate, because an updated plugin is never
        // reactivated and a release that changes no table still changes the
        // version. Without this the option kept whatever the last activation
        // wrote, which on both dev sites was 1.0.0 while the plugin on disk
        // was many releases past it: an update check and a support request
        // both read a number that was true once.
        if (get_option(Activator::OPTION_PLUGIN_VERSION) !== self::VERSION) {
            update_option(Activator::OPTION_PLUGIN_VERSION, self::VERSION, false);
        }

        if (!Activator::needsUpgrade()) {
            return;
        }

        try {
            $from = Activator::recordedVersion();

            Activator::installSchema();

            $migrated = Migrations::run($from, Activator::SCHEMA_VERSION);

            if ($migrated['failed'] !== []) {
                $this->logger()->error('VidyaLMS could not apply schema migration(s) {versions}.', [
                    'versions' => implode(', ', $migrated['failed']),
                ]);

                return;
            }

            // A build that adds a capability or a role adds it here too, not
            // only on activation: an updated plugin is never reactivated, so
            // a site that upgraded would otherwise be the only kind of site
            // where the role a feature needs does not exist. register() adds
            // no role it finds and grants no capability a role already holds,
            // so it is safe behind the version gate and safe to repeat.
            Capabilities::register();

            // Digests every entitlement that has none, in the window between
            // dbDelta reaching the declared shape and the version being
            // recorded: a failure here leaves the site trying again rather
            // than declaring itself upgraded with half its rows undigested. On
            // a clean install it finds nothing, because the compiled schema
            // brings the column and every row written since carries one.
            $this->backfillEntitlementDigests();

            // Mints the assignment reference for every seat that has none, on
            // the same terms and in the same window, and idempotent for the
            // same reason.
            $this->backfillAssignmentReferences();

            // The neutral table, on the upgrade path as well as on
            // activation. Activation was the only writer, and an updated
            // plugin is never reactivated, so a site that upgraded answered
            // "which schema am I at" from an option on WordPress and from
            // nothing on Joomla: the table the schema declares stayed empty
            // on both dev sites for eleven releases.
            Activator::recordSchemaVersion();

            update_option(Activator::OPTION_SCHEMA_VERSION, Activator::SCHEMA_VERSION, false);
            update_option(Activator::OPTION_PLUGIN_VERSION, self::VERSION, false);
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS could not upgrade the schema: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * Gives every entitlement the digest the unique index is carried on.
     *
     * It is the core's own use case over this adapter's database port rather
     * than SQL in the migration file, because the derivation has to be **the
     * same one** the running code uses: a row hashed by a migration and a row
     * hashed by the repository must be indistinguishable, or the index
     * refuses a legitimate grant on one host and accepts a duplicate on the
     * other.
     *
     * Idempotent by construction: it selects only rows whose digest is still
     * null, so a second run finds none and writes nothing. Rows with no source
     * reference keep a null digest, which is what preserves the manual and
     * import paths' reliance on nulls being distinct in a unique index.
     *
     * A duplicate repair is logged rather than done silently: it rewrites a
     * key and revokes a grant, and a site owner finding a revoked entitlement
     * with no explanation is worse than one who can read why.
     */
    private function backfillEntitlementDigests(): void
    {
        try {
            $backfill = new BackfillEntitlementDigests($this->databasePort(), new SystemClock());

            // Step 1 first and on its own, so the pre-change state is on the
            // record even when the repair itself goes wrong. A report written
            // after the write has already happened is not a report a person
            // can act on.
            foreach ($backfill->execute(true)['duplicates'] as $group) {
                $this->logger()->warning(
                    'VidyaLMS found a duplicate entitlement reference {source}:{ref} over {rows} row(s);'
                    . ' row {kept} keeps the key and the others will be rekeyed and revoked as {reason}.',
                    [
                        'source' => $group['source'],
                        'ref' => $group['source_ref'],
                        'rows' => \count($group['rows']),
                        'kept' => $group['kept_id'],
                        'reason' => BackfillEntitlementDigests::REPAIR_REASON,
                    ]
                );
            }

            $result = $backfill->execute();

            if ($result['digested'] > 0) {
                $this->logger()->info('VidyaLMS backfilled {count} entitlement digest(s).', [
                    'count' => $result['digested'],
                ]);
            }
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS could not backfill the entitlement digests: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * Gives every seat assignment written before assignment_ref existed the
     * opaque reference the unique index is carried on.
     *
     * The core's own use case over this adapter's database port rather than
     * SQL in the migration file, and for a sharper reason than the digest
     * backfill above. A digest is a function of the row, so SQL could in
     * principle recompute it. A reference is a **draw**, so SQL would have to
     * invent an alphabet, a length and a source of randomness, and the column
     * would then mean one thing on a site that installed clean and another on
     * a site that upgraded. There is exactly one mint and this runs it.
     *
     * Idempotent by construction, and twice over: the read asks only for rows
     * whose reference is still null, and the write repeats that predicate in
     * its own statement, so two administrators loading wp-admin at the same
     * moment cannot overwrite each other's mint.
     */
    private function backfillAssignmentReferences(): void
    {
        try {
            $store = new OrganisationLearningStore($this->databasePort(), new SystemClock(), new WpRandom());
            $result = (new BackfillAssignmentReferences($store))->execute();

            if ($result['minted'] > 0) {
                $this->logger()->info('VidyaLMS minted {count} seat assignment reference(s).', [
                    'count' => $result['minted'],
                ]);
            }
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS could not backfill the seat assignment references: {message}', [
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * The shared kernel, or the incoming value when it cannot be built (a site
     * whose tables are missing must still render wp-admin).
     */
    public function filterKernel(mixed $value): mixed
    {
        try {
            return $this->coreKernel();
        } catch (\Throwable) {
            return $value;
        }
    }

    public function filterDatabase(mixed $value): mixed
    {
        try {
            return $this->databasePort();
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Puts the site's self enrolment switch in front of every registered rule,
     * before and after the hook where Pro packages add their rules.
     *
     * The switch is enforced as a provider rather than as a check in this
     * adapter's controllers because only some of the callers are this adapter's
     * controllers: the course page, its form POST, POST /enrolments, WP-CLI and
     * any plugin calling SelfEnrol all go through the rule, and none of them
     * can go around it.
     */
    public function installSiteEnrolmentPolicy(CoreKernel $kernel): void
    {
        SiteSelfEnrolmentRule::install($kernel->registries->enrolmentRules);
    }

    private function coreKernel(): CoreKernel
    {
        if ($this->kernel !== null) {
            return $this->kernel;
        }

        // Assigned before the action fires: a listener registering a provider
        // may well ask for the kernel, and it must get this one rather than
        // recurse into building a second.
        $this->kernel = new CoreKernel($this->portBundle(), [
            'organisation_invitation_secret' => wp_salt('auth'),
            'organisation_invitation_url' => \Shondalai\VidyaLMS\WordPress\Content\OrganisationLanding::url(),
            'verification_base_url' => $this->verificationBaseUrl(),
            'unsubscribe_base_url' => $this->unsubscribeBaseUrl(),
            'envelope_domain' => $this->envelopeDomain(),
            'expiry_sweep_schedule' => $this->expirySweepSchedule(),
            // The native form lives below the React summary on the same
            // page. Preserve its fragment so the API link and the mount link
            // both take the reader to the editable controls, not the top of
            // the app again.
            'host_settings_url' => \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::available()
                ? admin_url('edit.php?post_type=product')
                : admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'),
            'self_enrolment' => Settings::selfEnrolmentEnabled(),
            // The three site facts the credential issuer and the {site.*}
            // tokens default from. WordPress knows all three about itself
            // and the core cannot know any of them, and while nothing
            // supplied them every credential this site issued named an
            // anonymous issuer with an empty id and the seeded template
            // rendered the literal string {site.name}.
            'site_name' => $this->siteName(),
            'site_url' => $this->siteUrl(),
            'site_logo_url' => $this->siteLogoUrl(),
            // The two machine-readable credential bases. The core would
            // otherwise derive them as siblings of the verification base,
            // which is a rewrite rule for a page this plugin renders:
            // /credentials matches no rule and a credential id has to be
            // dereferenceable by a wallet holding nothing but the document.
            'credential_base_url' => $this->credentialBaseUrl(),
            'status_list_base_url' => $this->statusListBaseUrl(),
        ], configuration: Settings::configuration());

        // The site owner's switches go on before anybody else's listener runs,
        // and never as a listener themselves: a third-party callback that threw
        // first would skip them while the kernel above is already cached, and
        // the switch would silently stop holding for the rest of the request.
        $this->installSiteEnrolmentPolicy($this->kernel);

        // A listener that throws must not take the request down at boot, and
        // must not leave callers with a kernel whose policies never installed.
        // Whatever it registered before throwing stays registered, which is the
        // same bargain the event bridge makes for domain events. One catch does
        // cancel the listeners queued behind it, which WordPress gives us no way
        // to isolate short of running the callbacks by hand.
        try {
            do_action(self::HOOK_KERNEL_READY, $this->kernel, new KernelReady($this->kernel));
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS kernel-ready listener failed.', ['exception' => $failure]);
        } finally {
            // Pro and other listeners may add or replace context-aware rules.
            // Keep the site policy even when a later listener failed.
            $this->installSiteEnrolmentPolicy($this->kernel);
        }

        return $this->kernel;
    }

    private function portBundle(): Ports
    {
        if ($this->ports !== null) {
            return $this->ports;
        }

        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('VidyaLMS was asked for its ports before WordPress opened the database.');
        }

        $database = $this->databasePort();
        $users = new WpUsers();
        $clock = new SystemClock();
        $logger = $this->logger();
        $translator = $this->translatorPort();

        $this->ports = new Ports(
            $database,
            $users,
            new WpAuthorization(),
            $this->storagePort(),
            new WpMailer($translator, $users),
            $this->schedulerPort(),
            new WpEventBridge($logger),
            new WpCommerce(),
            $translator,
            new WpCache(),
            $clock,
            new WpRandom(),
            $logger,
            // Its own port rather than a method on the translator: a paid
            // package implements TranslatorPort too, so a method added there
            // fatals an installed paid build at class load the moment this
            // one is upgraded.
            new WpLocale(),
            // The recipient's own timezone and language, so quiet hours run
            // in the person's zone and a multilingual site can select their
            // template row. The port had no implementer on either host, so
            // both answered the site defaults for every recipient and a
            // statutory quiet-hours channel refused every send.
            new WpRecipientProfile(),
            // Course authoring reads the host's native taxonomy rather than
            // maintaining a second category tree in the LMS tables.
            new WpCourseCategories($wpdb),
            new \Shondalai\VidyaLMS\WordPress\Port\WpCourseLinks(),
            // Null unless a plugin that bundles a renderer offers one; see
            // FILTER_PDF_PORT.
            $this->pdfPort(),
            // Named rather than positional, because the three identity ports
            // sit between these two and the members above them. This adapter
            // implements one of the three, and naming them says which.
            //
            // The asserted-identity port is seated here and not from the
            // kernel-ready hook, and it cannot be seated there: Ports is
            // readonly and is finished before the kernel exists, so
            // kernel-ready is where registries are extended and never where a
            // port arrives. The other two stay at the core's own defaults,
            // which are a refusal and an empty list, because this host
            // verifies no domain and holds no verified address list.
            assertedIdentity: new HostGroupIdentitySource($database, $clock, $users),
            http: WpHttp::fromSettings(),
            secrets: new WpSecrets(),
        );

        // One step before the kernel. A package with an implementation of one
        // of the optional ports seats it here or nowhere, because everything
        // the kernel builds eagerly is handed the port it was given. A
        // listener that throws is caught for the same reason a kernel-ready
        // listener is: a package must not stop the site booting.
        try {
            $collector = PortsReady::collect();
            do_action(self::HOOK_PORTS_READY, $collector);
            $this->ports = $collector->seal($this->ports);
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS ports-ready listener failed: {message}', [
                'message' => $failure->getMessage(),
            ]);
        }

        return $this->ports;
    }

    private function databasePort(): WpDatabase
    {
        if ($this->database !== null) {
            return $this->database;
        }

        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('VidyaLMS was asked for the database before WordPress opened it.');
        }

        return $this->database = new WpDatabase($wpdb);
    }

    private function storagePort(): WpStorage
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        $uploads = wp_upload_dir();

        return $this->storage = new WpStorage(
            \is_string($uploads['basedir'] ?? null) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
            \is_string($uploads['baseurl'] ?? null) ? $uploads['baseurl'] : content_url('uploads'),
            WpStorage::PUBLIC_FOLDER,
            Settings::privateStorageDir(),
        );
    }

    private function schedulerPort(): WpScheduler
    {
        return $this->scheduler ??= new WpScheduler($this->databasePort(), new SystemClock());
    }

    /**
     * The PDF renderer another plugin offers, or null.
     *
     * Caught for the same reason a ports-ready listener is: a package must not
     * stop the site booting, and without a renderer the site still issues
     * HTML certificates.
     */
    private function pdfPort(): ?\Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort
    {
        try {
            $offered = apply_filters(self::FILTER_PDF_PORT, null);
        } catch (\Throwable $failure) {
            $this->logger()->error('VidyaLMS PDF port listener failed: {message}', [
                'message' => $failure->getMessage(),
            ]);

            return null;
        }

        if ($offered === null || $offered instanceof \Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort) {
            return $offered;
        }

        $this->logger()->warning('VidyaLMS ignored a {filter} answer that is not a PdfPort: {type}', [
            'filter' => self::FILTER_PDF_PORT,
            'type' => get_debug_type($offered),
        ]);

        return null;
    }

    private function translatorPort(): WpTranslator
    {
        return $this->translator ??= new WpTranslator(self::path(self::CATALOGUE_FILE));
    }

    private function logger(): WpLogger
    {
        return $this->logger ??= new WpLogger();
    }

    /**
     * Cached for the request: it is a user query, and a busy order webhook
     * would otherwise repeat it per line item.
     */
    private function administratorId(): int
    {
        if ($this->administratorId !== null) {
            return $this->administratorId;
        }

        $found = get_users([
            'role' => Capabilities::ADMINISTRATOR_ROLE,
            'number' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ID',
        ]);

        return $this->administratorId = (int) ($found[0] ?? 0);
    }

    /**
     * Where a certificate verification URL points. The core composes this base
     * with the code via PublicLink's path or query value slot. The certificate route is the
     * default and the option only exists for a site that verifies elsewhere.
     */
    private function verificationBaseUrl(): string
    {
        $configured = Settings::verificationBaseUrl();

        if ($configured === '') {
            $rewrites = 'Shondalai\\VidyaLMS\\WordPress\\Content\\Rewrites';

            $configured = class_exists($rewrites) && method_exists($rewrites, 'certificateBaseUrl')
                ? (string) $rewrites::certificateBaseUrl()
                : home_url('/certificate/');
        }

        return rtrim((string) apply_filters('vidyalms_verification_base_url', $configured), '/');
    }

    /**
     * The public base a credential id is built on: this site's own REST
     * mount, which is what GET /credentials/{uuid} is served from.
     *
     * rest_url() composes from the stored home option rather than from the
     * request, which is what this needs: issuance runs from WP-Cron and from
     * WP-CLI, neither of which has a request, and an id frozen into a signed
     * document can never be corrected afterwards.
     */
    private function credentialBaseUrl(): string
    {
        $configured = trim((string) Settings::get('credential_base_url', ''));
        if ($configured !== '') {
            return $configured;
        }
        $base = rest_url(RestController::REST_NAMESPACE . '/credentials');

        return rtrim((string) apply_filters('vidyalms_credential_base_url', $base), '/');
    }

    /**
     * Where a Bitstring status list is published. The credential base plus
     * the core's own status segment, because that is the route that serves
     * it; a site fronting the REST mount with a CDN filters it.
     */
    private function statusListBaseUrl(): string
    {
        $configured = trim((string) Settings::get('status_list_base_url', ''));
        if ($configured !== '') {
            return $configured;
        }
        $credentials = $this->credentialBaseUrl();
        $base = $credentials === '' ? '' : $credentials . '/status';

        return rtrim((string) apply_filters('vidyalms_status_list_base_url', $base), '/');
    }

    /**
     * The public base of the unsubscribe landing page, handed to the kernel as
     * a host fact.
     *
     * The core read this from a settings row that nothing on either host ever
     * wrote, so every shipped install sent every non-transactional message
     * with no List-Unsubscribe header at all. The rewrite already owns the
     * route; only the answer was missing.
     *
     * RFC 8058 admits exactly one HTTPS URI, so a site served over plain HTTP
     * answers an empty string and the queue holds its bulk mail back with a
     * named reason rather than sending an unusable header.
     */
    private function unsubscribeBaseUrl(): string
    {
        $configured = trim((string) Settings::get('unsubscribe_base_url', ''));
        if ($configured !== '') {
            return $configured;
        }
        $rewrites = 'Shondalai\\VidyaLMS\\WordPress\\Content\\Rewrites';

        $base = class_exists($rewrites) && method_exists($rewrites, 'unsubscribeBaseUrl')
            ? (string) $rewrites::unsubscribeBaseUrl()
            : home_url('/unsubscribe/');

        $base = rtrim((string) apply_filters('vidyalms_unsubscribe_base_url', $base), '/');

        return str_starts_with($base, 'https://') ? $base : '';
    }

    /**
     * The domain an asynchronous bounce is addressed back to.
     *
     * It is the domain of the address wp_mail sends from, which WordPress
     * composes from the stored home option and any SMTP plugin overrides
     * through wp_mail_from. That domain is the one whose SPF record a
     * receiver checks against the reverse path, so aligning the two is what
     * keeps a tagged return address deliverable.
     *
     * The stored option and not the request: the queue is drained by cron,
     * which has no host header, and a WP-CLI run would otherwise compose a
     * different domain from the same site.
     */
    /**
     * The site's own name, which WordPress has always known and the core
     * never could. It defaults the credential issuer's name and the
     * {site.name} token; an administrator overrides it through the settings
     * route.
     */
    private function siteName(): string
    {
        $configured = trim((string) Settings::get('academy_name', ''));
        return $configured !== '' ? $configured : trim((string) get_bloginfo('name'));
    }

    /**
     * The site's own public address, from the stored option rather than from
     * the request: cron has no request, and a credential minted there would
     * otherwise carry a wrong host for ever inside a document nobody can
     * correct.
     */
    private function siteUrl(): string
    {
        $configured = trim((string) Settings::get('site_base_url', ''));
        return rtrim($configured !== '' ? $configured : trim((string) get_option('home')), '/');
    }

    /**
     * The site's custom logo, for the seeded certificate template's logo slot
     * and the credential issuer image. Empty when the theme has none, which
     * the token layer reports by leaving the placeholder unresolved rather
     * than by drawing a broken image.
     */
    private function siteLogoUrl(): string
    {
        $configured = trim((string) Settings::get('site_logo_url', ''));
        if ($configured !== '') {
            return $configured;
        }
        $logoId = get_theme_mod('custom_logo');

        if (!\is_int($logoId) && !\is_string($logoId)) {
            return '';
        }

        $source = wp_get_attachment_image_url((int) $logoId, 'full');

        return \is_string($source) ? $source : '';
    }

    private function envelopeDomain(): string
    {
        $configured = trim((string) Settings::get('envelope_domain', ''));
        if ($configured !== '') {
            return $configured;
        }
        $host = wp_parse_url((string) get_option('home'), PHP_URL_HOST);
        $host = \is_string($host) ? (string) preg_replace('/^www\./i', '', $host) : '';
        $from = 'wordpress@' . $host;

        // A boot fact must never be able to fail a request, and this one asks
        // a filter every SMTP plugin on the site owns.
        try {
            /** @var mixed $filtered */
            $filtered = apply_filters('wp_mail_from', $from);
            $from = \is_string($filtered) && $filtered !== '' ? $filtered : $from;
        } catch (\Throwable) {
            $from = 'wordpress@' . $host;
        }

        /** @var mixed $domain */
        $domain = apply_filters('vidyalms_envelope_domain', $from);

        return \is_string($domain) ? trim($domain) : '';
    }

    /**
     * How often the expiry sweep actually runs, which on WordPress this plugin
     * owns: it is its own recurring event, HOOK_RUN_EXPIRY, because nothing
     * else would ever start it. No event, no sweep, and the Settings and
     * Dashboard screens then say so rather than quoting a cadence nothing is
     * keeping.
     *
     * The cadence is read from the event's own registered schedule rather than
     * assumed, so a site owner who rescheduled it sees the truth. An event
     * scheduled with no recognised recurrence is reported as running rather
     * than as absent, because it is.
     */
    private function expirySweepSchedule(): ?string
    {
        $next = wp_next_scheduled(self::HOOK_RUN_EXPIRY);

        if ($next === false) {
            return null;
        }

        $recurrence = wp_get_schedule(self::HOOK_RUN_EXPIRY);
        $schedules = wp_get_schedules();
        $interval = \is_string($recurrence)
            ? (int) ($schedules[$recurrence]['interval'] ?? 0)
            : 0;

        // The three units and their wording are the Joomla adapter's, from
        // COM_VIDYALMS_SWEEP_EVERY_MINUTES, _HOURS and _DAYS. The contract
        // promises both hosts answer the Settings screen identically, and a
        // daily sweep reading "every 24 hours" here and "every day" there is a
        // parity failure on a field a person reads.
        if ($interval >= DAY_IN_SECONDS) {
            $days = (int) round($interval / DAY_IN_SECONDS);

            /* translators: %d: number of days between expiry sweeps. */
            return sprintf(_n('every %d day', 'every %d days', $days, 'vidyalms'), $days);
        }

        if ($interval >= HOUR_IN_SECONDS) {
            $hours = (int) round($interval / HOUR_IN_SECONDS);

            /* translators: %d: number of hours between expiry sweeps. */
            return sprintf(_n('every %d hour', 'every %d hours', $hours, 'vidyalms'), $hours);
        }

        $minutes = max(1, (int) round(max($interval, MINUTE_IN_SECONDS) / MINUTE_IN_SECONDS));

        /* translators: %d: number of minutes between expiry sweeps. */
        return sprintf(_n('every %d minute', 'every %d minutes', $minutes, 'vidyalms'), $minutes);
    }

    public function bootSubsystems(): void
    {
        foreach (self::SUBSYSTEMS as [$class, $method, $resolvers, $adminOnly]) {
            if ($adminOnly && !is_admin()) {
                continue;
            }

            if (!class_exists($class) || !method_exists($class, $method)) {
                continue;
            }

            $arguments = array_map(
                static fn (string $resolver): callable => match ($resolver) {
                    'kernel' => [self::class, 'kernel'],
                    'ports' => [self::class, 'ports'],
                    // Exception arguments are data; escape at the HTTP/template boundary.
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    default => throw new \LogicException(sprintf('Unknown resolver "%s".', $resolver)),
                },
                $resolvers,
            );

            try {
                $class::$method(...$arguments);
            } catch (\Throwable $failure) {
                // One broken subsystem takes itself down, not the site: a fatal
                // here would lock an administrator out of wp-admin entirely.
                $this->logger()->error('VidyaLMS could not boot {subsystem}: {message}', [
                    'subsystem' => $class,
                    'message' => $failure->getMessage(),
                    'exception' => $failure,
                ]);
            }
        }

        do_action('vidyalms_booted', $this);
    }
}
