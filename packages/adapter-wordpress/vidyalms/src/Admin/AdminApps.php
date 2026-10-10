<?php

declare(strict_types=1);

/**
 * VidyaLMS: the React administrator apps (admin screens, instructor workbench)
 * inside wp-admin.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\WordPress\Assets\MountBundles;
use Shondalai\VidyaLMS\WordPress\Content\Rewrites;
use Shondalai\VidyaLMS\WordPress\Files\UploadLimits;
use Shondalai\VidyaLMS\WordPress\Files\UploadPolicy;
use Shondalai\VidyaLMS\WordPress\Rest\RestController;
use Shondalai\VidyaLMS\WordPress\Rest\UploadController;

defined('ABSPATH') || exit;

/**
 * The administrator half of the mount contract
 * (docs/technical/05-frontend-strategy.md), the counterpart of
 * Content\LearnerApps on the front end and of the Joomla adapter's
 * AdminMountConfig service.
 *
 * Each VidyaLMS admin page renders one mount div carrying the whole host
 * contract in data-vy-config and loads one ES-module entry from
 * assets/app/. The admin bundle serves the host's registered screens and the
 * config's "screen" says which menu entry rendered it; the instructor bundle
 * serves the course workbench.
 *
 * Authentication is the browser session exactly as it is for the learner
 * apps: /wp-json cookie auth needs the wp_rest nonce, so the api block is the
 * plugin's own REST namespace in "prefix" mode with X-WP-Nonce, and
 * Rest\RestController resolves the caller from get_current_user_id(). There
 * is no separate administrator credential on WordPress and nothing here
 * decides what the caller may do: the core answers every request.
 */
final class AdminApps
{
    public const ADMIN_MOUNT = 'vidyalms-admin';

    public const WORKBENCH_MOUNT = 'vidyalms-instructor';

    public const ADMIN_HANDLE = 'vidyalms-app-admin';

    public const WORKBENCH_HANDLE = 'vidyalms-app-instructor';

    /**
     * The version of the mount contract this adapter writes, asserted by
     * the bundle. Raise it with packages/ui's MOUNT_CONTRACT when the shape
     * changes in a way an older bundle cannot read: without it the failure
     * mode is a silently missing key rather than a sentence anybody can act
     * on.
     */
    public const CONTRACT = 1;

    /**
     * Filters the assembled config, per mount, so a plugin that needs one
     * value in a screen of its own does not have to edit the free build.
     * Mirrors vidyalms_upload_policy.
     *
     *     add_filter('vidyalms_mount_config', function (array $config, string $mount): array {
     *         if ($mount === AdminApps::ADMIN_MOUNT) {
     *             $config['seats'] = ['remaining' => 12];
     *         }
     *
     *         return $config;
     *     }, 10, 2);
     *
     * A filter that throws or answers something other than an array leaves
     * the config as the free build built it, because a broken screen is
     * worse than a missing extra.
     */
    public const FILTER_CONFIG = 'vidyalms_mount_config';

    /**
     * The token the workbench URL template carries. It travels through
     * admin_url() and add_query_arg(), which percent-encode braces, so the
     * template is assembled with a placeholder that survives encoding and the
     * braces are put back afterwards.
     */
    private const COURSE_TOKEN = 'vycoursetoken';

    private const SLUG_TOKEN = 'vyslugtoken';

    /**
     * Renders one admin screen inside the native notice boundary. Everything
     * a person sees inside the mount is the app's.
     */
    public static function renderScreen(string $screen): void
    {
        if (!self::supportsScreen($screen)) {
            wp_die(
                esc_html__('This VidyaLMS screen is not available on this site.', 'vidyalms'),
                '',
                ['response' => 404]
            );
        }

        self::renderMount(self::ADMIN_MOUNT, self::adminConfig($screen));
    }

    /**
     * Renders the workbench of one course, or the caller's course list when
     * the URL names no course.
     */
    public static function renderWorkbench(int $courseId): void
    {
        self::renderMount(self::WORKBENCH_MOUNT, self::workbenchConfig($courseId));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function renderMount(string $mountId, array $config): void
    {
        $json = wp_json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        // WordPress common.js moves native notices after this anchor. Without
        // it, the first React heading becomes the destination and host notices
        // are inserted into the studio toolbar, outside React's ownership.
        echo '<hr class="wp-header-end">';

        printf(
            '<div id="%1$s" data-vy-config="%2$s"><noscript><p>%3$s</p></noscript></div>',
            esc_attr($mountId),
            esc_attr(\is_string($json) ? $json : '{}'),
            esc_html__('This screen needs JavaScript. Turn it on and reload the page.', 'vidyalms')
        );
    }

    /**
     * The admin app's contract for one screen.
     *
     * @return array<string, mixed>
     */
    public static function adminConfig(string $screen): array
    {
        $screens = AdminScreens::urls();

        if (!isset($screens[$screen])) {
            throw new \InvalidArgumentException('The requested VidyaLMS admin screen is not registered.');
        }

        return self::extended(self::ADMIN_MOUNT, self::shared() + [
            'screen' => $screen,
            'shop' => self::shopSetup(),
            'importTransport' => [
                'url' => admin_url('admin-ajax.php?action=vidyalms_import_workspace'),
                'nonceName' => ImportPage::NONCE_FIELD,
                'nonce' => wp_create_nonce(ImportPage::NONCE_ACTION),
            ],
            'routes' => self::servedRoutes(),
            'uploadPurposes' => self::uploadPurposes(),
            'urls' => [
                'workbench' => self::workbenchTemplate(),
                'nativeImport' => Menu::url(Menu::SLUG_IMPORT),
                'preview' => self::previewTemplate(),
                // GET /wp-json/vidyalms/v1/admin/settings is read only by
                // contract, so everything editable is the WordPress form on
                // the settings page itself.
                'settings' => Menu::url(Menu::SLUG_SETTINGS),
                'upload' => self::uploadUrl(),
                'screens' => $screens,
            ],
        ]);
    }

    /**
     * Whether the current edition publishes this screen into wp-admin.
     *
     * Kept as a query rather than inferred from a URL supplied by a config
     * filter: the server-side registry owns reachability, and a disabled
     * provider must not be resurrected by stale client configuration.
     */
    public static function supportsScreen(string $screen): bool
    {
        return AdminScreens::find($screen) !== null;
    }

    /**
     * The workbench's contract.
     *
     * @return array<string, mixed>
     */
    public static function workbenchConfig(int $courseId): array
    {
        return self::extended(self::WORKBENCH_MOUNT, self::shared() + [
            'shop' => self::shopSetup(),
            'course' => $courseId > 0 ? ['id' => $courseId, 'slug' => self::slugOf($courseId)] : null,
            'uploadPurposes' => self::uploadPurposes(),
            'routes' => self::servedRoutes(),
            'urls' => [
                'courses' => Menu::url(Menu::SLUG_COURSES),
                'emailTemplates' => Menu::url(Menu::SLUG_EMAIL_TEMPLATES),
                ...(current_user_can(Menu::CAP_MANAGE)
                    ? ['certificateTemplates' => Menu::url(Menu::SLUG_CERTIFICATES)] : []),
                'workbench' => self::workbenchTemplate(),
                'preview' => self::previewTemplate(),
                'upload' => self::uploadUrl(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public static function shopSetup(): array
    {
        $installed = \class_exists('WooCommerce');
        $manageShop = $installed && current_user_can('manage_woocommerce');
        $products = $installed && current_user_can('edit_products')
            ? admin_url('edit.php?post_type=product') : null;

        return [
            'name' => 'WooCommerce',
            'installed' => $installed,
            'integrationEnabled' => $installed,
            'productsUrl' => $products,
            'settingsUrl' => $manageShop ? admin_url('admin.php?page=wc-settings') : null,
            'mappingUrl' => $products,
        ];
    }

    /**
     * Loads one app bundle: the entry module plus its stylesheet. All dist
     * files sit flat in assets/app/, so the entry's relative imports
     * (./shared.js, ./vendor.js) resolve against the same directory.
     *
     * $entry is also the mount name the extra-bundle filter is asked with, so
     * a package contributing a renderer names "instructor" rather than a
     * handle this class happens to have chosen.
     */
    public static function enqueue(string $handle, string $entry): void
    {
        $directory = self::pluginDir() . 'assets/app/';

        if (!is_file($directory . $entry . '.js')) {
            // A build without node ships no bundles; a broken script tag
            // would hide that, an absent one surfaces it in the page.
            return;
        }

        $url = self::pluginUrl() . 'assets/app/';
        $version = self::assetVersion();

        // Refresh rebuilt styles even when the plugin version has not changed.
        $styleFile = $directory . $entry . '.css';
        $styleVersion = is_file($styleFile) ? $version . '.' . filemtime($styleFile) : $version;
        wp_enqueue_style($handle, $url . $entry . '.css', [], $styleVersion);
        $scriptVersion = $version . '.' . filemtime($directory . $entry . '.js');
        wp_enqueue_script('react');
        wp_enqueue_script('react-dom');
        wp_enqueue_script('react-jsx-runtime');

        if (\function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module($handle, $url . $entry . '.js', [], $scriptVersion);
            MountBundles::enqueueFor($entry, $handle);

            return;
        }

        wp_enqueue_script($handle, $url . $entry . '.js', [], $scriptVersion, true);
        add_filter(
            'script_loader_tag',
            static function (string $tag, string $forHandle) use ($handle): string {
                if ($forHandle !== $handle) {
                    return $tag;
                }

                return (string) str_replace(' src=', ' type="module" src=', $tag);
            },
            10,
            2,
        );

        MountBundles::enqueueFor($entry, $handle);
    }

    /**
     * The half every app shares: how to reach the API, who is asking, and in
     * which language. Identical in shape to Content\LearnerApps::mountConfig()
     * and to the Joomla adapter's AdminMountConfig.
     *
     * Public and parameterised because the front end instructor page mounts
     * the same bundle and needs the same three answers. Restating them there
     * meant a key added to the workbench contract could be added to one
     * builder and not the other, and only a live smoke run comparing key sets
     * would notice. The URL half legitimately differs per surface and is what
     * the caller passes; the contract, user and locale half has one
     * implementation per host.
     *
     * @param array<string, string>|null $headers
     *
     * @return array<string, mixed>
     */
    public static function shared(?string $apiBase = null, ?string $mode = null, ?array $headers = null): array
    {
        $user = wp_get_current_user();

        return [
            'contract' => self::CONTRACT,
            'api' => [
                'base' => $apiBase ?? untrailingslashit(rest_url(RestController::REST_NAMESPACE)),
                'mode' => $mode ?? 'prefix',
                'headers' => $headers ?? ['X-WP-Nonce' => wp_create_nonce('wp_rest')],
            ],
            'user' => [
                'id' => (int) $user->ID,
                'name' => (int) $user->ID > 0 ? (string) $user->display_name : '',
            ],
            'locale' => str_replace('_', '-', (string) get_user_locale()),
            'messages' => self::messages(),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        static $messages;
        return $messages ??= \Shondalai\VidyaLMS\Core\I18n\UiMessages::translated(
            [\Shondalai\VidyaLMS\WordPress\Port\WpTranslator::class, 'text'],
        );
    }

    /**
     * What this site actually accepts, from the policy the upload endpoint
     * will use. The picker had a copy of the free build's ceilings, so a
     * site that raised one through vidyalms_upload_max_mb could not use the
     * extra capacity: the control refused before sending. Extension hooks
     * have already run, so a purpose a plugin registered is offered too.
     *
     * Public because the front end instructor page writes the same workbench
     * contract, and two copies of this list would be two answers to "what does
     * this site accept".
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Every contract path this install actually serves, as its template.
     *
     * The workbench bundle and this plugin ship separately, and a paid or
     * third-party package adds routes from a kernel-ready listener, so which
     * of the contract's paths exist here is decided at boot and nowhere else.
     * Without this key the bundle had to guess, and it guessed wrong in the
     * one direction that matters: the quiz export control was on the contract,
     * in the generated SDK and in the shipped workbench, and answered 404 on
     * every site without the package that serves it. A control that would 404
     * is worse than no control.
     *
     * It is a **discovery** key and never a permission key. Who may export is
     * still the core's answer to the request, which the app renders as a
     * permission state; this only says whether there is anything there to ask.
     *
     * Public because the front-end instructor page mounts the same bundle and
     * has to answer the same question; two copies would be two answers.
     *
     * @return list<string>
     */
    public static function servedRoutes(): array
    {
        try {
            $kernel = vidyalms_kernel();
            return array_values(array_filter($kernel->apiKernel()->servedTemplates(), static function (string $path) use ($kernel): bool {
                if ($path === '/manage/courses/{id}/reports/item-analysis') {
                    return in_array('item_analysis', $kernel->registries->reports->keys(), true);
                }
                if ($path === '/admin/users') {
                    return current_user_can(Menu::CAP_MANAGE) || current_user_can('manage_options');
                }
                return true;
            }));
        } catch (\Throwable) {
            // A site that cannot build a kernel has a larger problem than a
            // missing button, and the mount still renders with the app's
            // "nothing is offered" default.
            return [];
        }
    }

    public static function uploadPurposes(): array
    {
        try {
            $policy = UploadPolicy::forSite();
        } catch (\Throwable) {
            return [];
        }

        $purposes = [];

        foreach ($policy->keys() as $key) {
            $purpose = $policy->purpose($key);

            if ($purpose === null) {
                continue;
            }

            $purposes[] = [
                'key' => $purpose->key,
                // The smaller of the purpose's own ceiling and the site's,
                // which is the number the endpoint will actually enforce.
                'max_bytes' => UploadLimits::effectiveCeilingBytes($purpose->maxBytes),
                'extensions' => $purpose->extensions(),
            ];
        }

        return $purposes;
    }

    /**
     * The same filter, for a mount this class did not assemble. The front end
     * instructor page writes the workbench contract from a public request, and
     * a filter that extends the workbench must reach it there too: a seam that
     * fires on one of two pages rendering the same mount is a seam nobody can
     * rely on.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function extendedFor(string $mount, array $config): array
    {
        return self::extended($mount, $config);
    }

    /**
     * One filter over the assembled config, per mount.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function extended(string $mount, array $config): array
    {
        try {
            $extended = apply_filters('vidyalms_mount_config', $config, $mount);
        } catch (\Throwable) {
            return $config;
        }

        if (!\is_array($extended)) {
            return $config;
        }

        // The contract and the api block are this adapter's to state; a
        // filter that dropped either would break the mount it was trying to
        // extend.
        /** @var array<string, mixed> $extended */
        return $extended + $config;
    }

    /**
     * Where the file picker posts a multipart form. It is the plugin's own
     * REST namespace, not the contract's: no bytes travel through the
     * contract, so the SDK's api base cannot reach it and the apps are told
     * the URL outright. The same X-WP-Nonce the api block carries guards it.
     */
    private static function uploadUrl(): string
    {
        return rest_url(UploadController::REST_NAMESPACE . UploadController::ROUTE);
    }

    private static function workbenchTemplate(): string
    {
        $url = Menu::url(Menu::SLUG_COURSE, ['course' => self::COURSE_TOKEN]);

        return str_replace(self::COURSE_TOKEN, '{course_id}', $url);
    }

    /**
     * Preview as learner opens the player, which is the /courses/{slug}/learn
     * rewrite. Rewrites::learnUrl() sanitises the slug it is given, so the
     * token is one sanitize_title() leaves alone and is swapped for the brace
     * form afterwards.
     */
    private static function previewTemplate(): string
    {
        return str_replace(self::SLUG_TOKEN, '{slug}', Rewrites::learnUrl(self::SLUG_TOKEN));
    }

    /**
     * The course slug, or an empty string when the caller may not read this
     * course. The app asks the API anyway and renders the refusal the core
     * gives it, so no rule is decided twice.
     */
    private static function slugOf(int $courseId): string
    {
        $kernel = Menu::kernel();

        if (!$kernel instanceof CoreKernel) {
            return '';
        }

        try {
            return $kernel->getManagedCourse->execute(Menu::principal(), $courseId)->managed->course->slug->value;
        } catch (DomainException) {
            return '';
        }
    }

    private static function assetVersion(): string
    {
        return \defined('VIDYALMS_VERSION') ? (string) \constant('VIDYALMS_VERSION') : '1.0.0';
    }

    private static function pluginDir(): string
    {
        if (\defined('VIDYALMS_PLUGIN_DIR')) {
            return trailingslashit((string) \constant('VIDYALMS_PLUGIN_DIR'));
        }

        return trailingslashit(\dirname(__DIR__, 2));
    }

    private static function pluginUrl(): string
    {
        if (\defined('VIDYALMS_PLUGIN_URL')) {
            return trailingslashit((string) \constant('VIDYALMS_PLUGIN_URL'));
        }

        return trailingslashit(plugins_url('', self::pluginDir() . 'vidyalms.php'));
    }
}
