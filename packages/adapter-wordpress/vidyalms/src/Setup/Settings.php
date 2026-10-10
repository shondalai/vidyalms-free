<?php

declare(strict_types=1);

/**
 * VidyaLMS: the adapter's own settings, read from anywhere in the plugin.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\Core\Application\Platform\PruneImportSources;
use Shondalai\VidyaLMS\WordPress\Port\WpHttp;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;

defined('ABSPATH') || exit;

/**
 * Runtime configuration stored in the LMS settings table. Legacy WordPress
 * options are imported only when the configuration record does not exist.
 * Each site has one lazy snapshot for this request; saves refresh that snapshot.
 */
final class Settings
{
    public const OPTION = 'vidyalms_settings';

    public const VERIFICATION_BASE_URL = 'verification_base_url';

    public const CATALOGUE_PER_PAGE = 'catalogue_per_page';

    public const SELF_ENROLMENT = 'self_enrolment';

    public const PRIVATE_STORAGE_DIR = 'private_storage_dir';

    public const IMPORT_SOURCE_RETENTION_DAYS = 'import_source_retention_days';

    /**
     * The hosts this site will make an outbound call to at all.
     *
     * Empty allows nothing, which is the whole reason it is a setting rather
     * than a default: a site that has never opened this screen is a site no
     * installed package can post from, and a site owner naming the two
     * integration hosts they use is one text box.
     */
    public const HTTP_ALLOWLIST = 'http_allowlist';

    /** The longest any outbound call may run on this site. */
    public const HTTP_TIMEOUT_SECONDS = 'http_timeout_seconds';

    /**
     * The ceiling the settings screen clamps to. Ten years of Moodle backups
     * is not a retention policy, it is a disk that fills up quietly.
     */
    public const MAX_IMPORT_SOURCE_RETENTION_DAYS = 3650;

    /**
     * The core's catalogue use case refuses more than this, so the settings
     * screen and every caller clamp to it rather than discovering it as an
     * exception on a front end request.
     */
    public const MAX_PER_PAGE = 50;

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            self::VERIFICATION_BASE_URL => '',
            self::CATALOGUE_PER_PAGE => 12,
            self::SELF_ENROLMENT => true,
            self::PRIVATE_STORAGE_DIR => 'vidyalms-private',
            self::IMPORT_SOURCE_RETENTION_DAYS => PruneImportSources::DEFAULT_RETENTION_DAYS,
            self::HTTP_ALLOWLIST => '',
            self::HTTP_TIMEOUT_SECONDS => WpHttp::DEFAULT_TIMEOUT_SECONDS,
        ];
    }

    /**
     * The request snapshot, with aliases for WordPress adapter consumers.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $values = self::configuration()->snapshot()['values'];
        return $values + [self::HTTP_ALLOWLIST => $values['integrations_http_allowlist'],
            self::HTTP_TIMEOUT_SECONDS => $values['integrations_http_timeout']];
    }

    /** @var array<int, \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration> */
    private static array $configurations = [];

    public static function configuration(): \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration
    {
        $siteId = (int) get_current_blog_id();
        return self::$configurations[$siteId] ??= new \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration(
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseConfigurationRepository(
                \Shondalai\VidyaLMS\WordPress\Plugin::database(), new \Shondalai\VidyaLMS\WordPress\Port\SystemClock()
            ),
            static function (): array {
                $stored = get_option(self::OPTION, []);
                $stored = \is_array($stored) ? $stored : [];
                $stored['integrations_http_allowlist'] = $stored[self::HTTP_ALLOWLIST] ?? '';
                $stored['integrations_http_timeout'] = $stored[self::HTTP_TIMEOUT_SECONDS] ?? 15;
                return $stored;
            }
        );
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $options = self::all();

        return $options[$key] ?? $fallback;
    }

    /**
     * An empty string means "derive from the certificate route", which the
     * composition root does; this only reports what was configured.
     */
    public static function verificationBaseUrl(): string
    {
        return trim((string) self::get(self::VERIFICATION_BASE_URL, ''));
    }

    public static function cataloguePerPage(): int
    {
        $value = (int) self::get(self::CATALOGUE_PER_PAGE, 12);

        return max(1, min(self::MAX_PER_PAGE, $value));
    }

    /**
     * A site policy switch, not a learning rule, and read in exactly one place:
     * Provider\SiteSelfEnrolmentRule wraps the core's "open" enrolment rule with
     * it, so every path that can enrol a learner is refused by the same rule.
     * Reading it anywhere else to decide whether an enrolment may happen puts a
     * second answer beside that one, free to drift from it.
     */
    public static function selfEnrolmentEnabled(): bool
    {
        return (bool) self::get(self::SELF_ENROLMENT, true);
    }

    /**
     * How long an uploaded import archive is kept after the last run that used
     * it finished. The window exists so the Import screen's Retry still has
     * its file: a finished import is exactly the one an operator re-runs.
     */
    public static function importSourceRetentionDays(): int
    {
        $days = (int) self::get(
            self::IMPORT_SOURCE_RETENTION_DAYS,
            PruneImportSources::DEFAULT_RETENTION_DAYS
        );

        return max(1, min(self::MAX_IMPORT_SOURCE_RETENTION_DAYS, $days));
    }

    /**
     * The raw allowlist as the site owner wrote it. WpHttp parses it, because
     * parsing it in two places is how two answers to "is this host allowed"
     * appear on one site.
     */
    public static function httpAllowlist(): string
    {
        return trim((string) self::get(self::HTTP_ALLOWLIST, ''));
    }

    /** Clamped here as well as in WpHttp, so a hand-edited database row cannot raise the ceiling. */
    public static function httpTimeoutSeconds(): int
    {
        $seconds = (int) self::get(self::HTTP_TIMEOUT_SECONDS, WpHttp::DEFAULT_TIMEOUT_SECONDS);

        return max(1, min(WpHttp::MAX_TIMEOUT_SECONDS, $seconds));
    }

    /** Server-owned private directory; database options never select its location. */
    public const PRIVATE_DIR_CONSTANT = 'VIDYALMS_PRIVATE_DIR';

    /**
     * An absolute server-configured path, or the historical default location.
     * The storage adapter refuses private I/O at that in-web-root default.
     */
    public static function privateStorageDir(): string
    {
        $constant = \defined(self::PRIVATE_DIR_CONSTANT) ? \constant(self::PRIVATE_DIR_CONSTANT) : null;

        if ($constant !== null) {
            if (!\is_string($constant)) {
                throw new \RuntimeException('VIDYALMS_PRIVATE_DIR must be an absolute local directory.');
            }
            $absolute = self::absolutePath($constant);

            if ($absolute !== null) {
                return $absolute;
            }
            throw new \RuntimeException('VIDYALMS_PRIVATE_DIR must be an absolute local directory.');
        }
        try {
            $class = \Shondalai\VidyaLMS\Core\Infrastructure\Storage\AutomaticPrivateStorage::class;
            $uploads = wp_upload_dir(null, false);
            $stored = get_option($class::SETTING, null);
            // Server-provided filesystem path, validated with realpath by the storage provisioner.
            // Do not apply HTML/text sanitization to filesystem identity.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? wp_unslash($_SERVER['DOCUMENT_ROOT']) : '';
            $identity = json_encode(\Shondalai\VidyaLMS\WordPress\Files\PrivateStorageOwnership::siteIdentity(), JSON_THROW_ON_ERROR);
            $record = $class::resolve(ABSPATH, [ABSPATH, (string) ($uploads['basedir'] ?? ''),
                defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '', defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
                defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '', $documentRoot],
                $identity, wp_salt('auth'), $stored, $documentRoot !== '');
            if ($record !== null) {
                if ($stored === null) {
                    add_option($class::SETTING, $record, '', false);
                    // A concurrent request must agree before any private I/O.
                    if (get_option($class::SETTING, null) !== $record) {
                        throw new \RuntimeException('Private storage setup changed; retry the request.');
                    }
                }
                return $record['path'];
            }
        } catch (\Throwable) {
            // Keep public pages/uploads working; the private storage notice
            // explains explicit setup when an external root cannot be selected.
        }
        return WpStorage::PRIVATE_FOLDER;
    }

    /**
     * An absolute path with no traversal and no null byte, or null. Invalid constants fail closed; no fallback directory is selected.
     */
    private static function absolutePath(string $path): ?string
    {
        $candidate = rtrim(str_replace('\\', '/', trim($path)), '/');

        if ($candidate === '' || str_contains($candidate, "\0")) {
            return null;
        }

        if (\in_array('..', explode('/', $candidate), true)) {
            return null;
        }

        $isAbsolute = str_starts_with($candidate, '/') || preg_match('#^[A-Za-z]:/#', $candidate) === 1;

        return $isAbsolute ? $candidate : null;
    }
}
