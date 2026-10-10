<?php

declare(strict_types=1);

/**
 * VidyaLMS: the instructor workbench on the WordPress front end.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\WordPress\Admin\AdminApps;
use Shondalai\VidyaLMS\WordPress\Rest\RestController;
use Shondalai\VidyaLMS\WordPress\Rest\UploadController;

defined( 'ABSPATH' ) || exit;

/**
 * The same workbench bundle wp-admin mounts, on a public page, so a person who
 * teaches never needs a wp-admin login to build a course.
 *
 * Two ways in, one mount:
 *
 *   - /teach (the Rewrites::ROUTE_INSTRUCTOR rule), rendered by
 *     templates/instructor.php;
 *   - [vidyalms_instructor] on any page, for a site that prefers its own page
 *     structure.
 *
 * Both render the same template part and the same data-vy-config, so the two
 * cannot behave differently.
 *
 * This page decides two things and no more. A guest is sent to the login form
 * and comes straight back, because a mount holding a guest's nonce can answer
 * nothing. Everybody else gets the mount, and the app asks the API: a learner's
 * course list comes back 403 and the app renders the permission state that was
 * designed for it. There is no capability check here and no edition check
 * anywhere. Who may teach is the core's answer to a route, and a second copy of
 * that rule in this file could only ever be the wrong copy.
 *
 * The api block is the plugin's own REST namespace in "prefix" mode with the
 * wp_rest nonce, exactly as Content\LearnerApps builds it for the player.
 */
final class InstructorPage
{
    public const SHORTCODE = 'vidyalms_instructor';

    public const HANDLE = 'vidyalms-app-instructor';

    /**
     * The course the workbench opens, as a plain GET parameter. Not part of
     * WordPress routing: it is a mount-contract parameter, read from the raw
     * request the way the player's vy-lesson and vy-quiz are.
     */
    public const PARAM_COURSE = 'vy-course';

    /**
     * The bundle entry, which is also the mount name the extra-bundle filter
     * is asked with, so a package contributing a renderer names "instructor"
     * rather than a handle this class happens to have chosen.
     */
    private const ENTRY = 'instructor';

    /** Placeholders that survive add_query_arg's encoding. */
    private const COURSE_TOKEN = 'vycoursetoken';

    private const SLUG_TOKEN = 'vyslugtoken';

    /** Whether the bundle is already on this page. */
    private static bool $enqueued = false;

    private function __construct()
    {
    }

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'guard'], 4);
        add_action('template_redirect', [self::class, 'forbidPageCache'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets'], 6);
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
    }

    /**
     * The workbench is a signed-in surface, always: it has no free-preview
     * case the way the player does.
     */
    public static function guard(): void
    {
        if (is_user_logged_in() || Rewrites::currentRoute() !== Rewrites::ROUTE_INSTRUCTOR) {
            return;
        }

        $courseId = self::courseId();

        wp_safe_redirect(
            wp_login_url(Rewrites::instructorUrl($courseId > 0 ? (string) $courseId : '')),
        );
        exit;
    }

    /**
     * Keeps the page out of every page cache: the data-vy-config attribute
     * embeds this visitor's own wp_rest nonce, and a cached copy would replay
     * it to the next person. Same reason and same mechanism as the player.
     *
     * The route check belongs to the template_redirect hook and to nothing
     * else. The shortcode is documented as the way in for a site that prefers
     * its own page, which is exactly the case where currentRoute() answers
     * nothing, so the guard hooked on the route covered every render except
     * the one it was added for: the instructor's display name, their user id
     * and their nonce went into the cache and out again to whoever fetched the
     * page next.
     */
    public static function forbidPageCache(): void
    {
        if (Rewrites::currentRoute() === Rewrites::ROUTE_INSTRUCTOR || self::isShortcodePage()) {
            self::refuseCaching();
        }
    }

    /**
     * Whether the post about to be rendered carries the shortcode.
     *
     * Asked at template_redirect, which is before a byte of the page is sent,
     * so the headers are still ours to set. The shortcode's own render calls
     * the refusal again for the case a theme reaches the shortcode by another
     * route, where only the cache constant is still worth setting.
     */
    private static function isShortcodePage(): bool
    {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();

        return $post !== null && has_shortcode((string) $post->post_content, self::SHORTCODE);
    }

    /**
     * The refusal itself, which every render path calls.
     */
    private static function refuseCaching(): void
    {
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }

        // A shortcode renders inside the_content, by which point a theme has
        // usually sent its head. The constant is what a page cache reads at
        // shutdown and is the half that still works there; sending a header
        // after the body has started would be a warning and nothing else.
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    public static function enqueueAssets(): void
    {
        if (Rewrites::currentRoute() === Rewrites::ROUTE_INSTRUCTOR) {
            self::enqueueApp();
        }
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function shortcode($atts = []): string
    {
        unset($atts);

        // Unconditionally, because this is a render: the attribute this
        // returns carries the visitor's own nonce and identity whatever page
        // it was placed on.
        self::refuseCaching();

        // A shortcode renders during the_content, after wp_enqueue_scripts has
        // run. WordPress prints script modules and late styles in the footer,
        // so enqueueing here still puts the bundle on the page.
        self::enqueueApp();

        return Templates::render('part-instructor', self::context());
    }

    /**
     * The course the workbench opens, or 0 for the caller's course list.
     */
    public static function courseId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view selector, no state changes.
        return isset($_GET[self::PARAM_COURSE]) ? absint(wp_unslash($_GET[self::PARAM_COURSE])) : 0;
    }

    /**
     * The template context of templates/instructor.php and
     * templates/part-instructor.php.
     *
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        return [
            'mount_id' => AdminApps::WORKBENCH_MOUNT,
            'config_attribute' => self::configAttribute(),
            'signed_in' => is_user_logged_in(),
            'login_url' => wp_login_url(Rewrites::instructorUrl()),
            'my_learning_url' => Rewrites::myLearningUrl(),
        ];
    }

    /**
     * The mount contract, ready for a data-vy-config attribute.
     */
    public static function configAttribute(): string
    {
        $json = wp_json_encode(self::mountConfig(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return esc_attr(\is_string($json) ? $json : '{}');
    }

    /**
     * The workbench contract, key for key the shape Admin\AdminApps writes for
     * wp-admin, because it is the same bundle reading it. Only the URLs
     * differ: they point at front end routes.
     *
     * @return array<string, mixed>
     */
    public static function mountConfig(): array
    {
        $courseId = self::courseId();

        // The contract, user and locale half is wp-admin's own answer, asked
        // rather than restated: a key added to the workbench contract has to
        // reach both surfaces or the front end mounts a bundle missing it.
        $shared = AdminApps::shared(
            untrailingslashit(rest_url(RestController::REST_NAMESPACE)),
            'prefix',
            ['X-WP-Nonce' => wp_create_nonce('wp_rest')],
        );

        return AdminApps::extendedFor(AdminApps::WORKBENCH_MOUNT, $shared + [
            'shop' => AdminApps::shopSetup(),
            'course' => $courseId > 0 ? ['id' => $courseId, 'slug' => self::slugOf($courseId)] : null,
            'uploadPurposes' => AdminApps::uploadPurposes(),
            'routes' => AdminApps::servedRoutes(),
            'urls' => [
                'courses' => Rewrites::instructorUrl(),
                ...(current_user_can(\Shondalai\VidyaLMS\WordPress\Admin\Menu::CAP_MANAGE)
                    ? ['certificateTemplates' => \Shondalai\VidyaLMS\WordPress\Admin\Menu::url(
                        \Shondalai\VidyaLMS\WordPress\Admin\Menu::SLUG_CERTIFICATES,
                    )] : []),
                'emailTemplates' => \Shondalai\VidyaLMS\WordPress\Admin\Menu::url(
                    \Shondalai\VidyaLMS\WordPress\Admin\Menu::SLUG_EMAIL_TEMPLATES,
                ),
                'workbench' => str_replace(
                    self::COURSE_TOKEN,
                    '{course_id}',
                    Rewrites::instructorUrl(self::COURSE_TOKEN),
                ),
                'preview' => str_replace(self::SLUG_TOKEN, '{slug}', Rewrites::learnUrl(self::SLUG_TOKEN)),
                'upload' => rest_url(UploadController::REST_NAMESPACE . UploadController::ROUTE),
            ],
        ]);
    }

    /**
     * The course slug, or an empty string when the caller may not read this
     * course. The app asks the API anyway and renders the refusal the core
     * gives it, so no rule is decided twice.
     */
    private static function slugOf(int $courseId): string
    {
        $kernel = self::kernel();

        if (!$kernel instanceof CoreKernel) {
            return '';
        }

        try {
            return $kernel->getManagedCourse
                ->execute(self::principal(), $courseId)
                ->managed->course->slug->value;
        } catch (DomainException) {
            return '';
        }
    }

    /**
     * The workbench bundle: the entry module plus its stylesheet, from the
     * same assets/app/ directory the learner apps load from. AdminApps::enqueue
     * asks the extra-bundle filter itself, so a Pro renderer reaches this page
     * through the seam it already uses for the wp-admin workbench.
     *
     * The guard is a flag rather than wp_script_is(): a script module is not in
     * the classic script queue, so the queue cannot be asked about it, and the
     * route and the shortcode can both want the bundle on one page.
     */
    private static function enqueueApp(): void
    {
        if (self::$enqueued) {
            return;
        }

        self::$enqueued = true;

        AdminApps::enqueue(self::HANDLE, self::ENTRY);
    }

    private static function principal(): Principal
    {
        $userId = get_current_user_id();

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    private static function kernel(): ?CoreKernel
    {
        if (!\function_exists('vidyalms_kernel')) {
            return null;
        }

        $kernel = vidyalms_kernel();

        return $kernel instanceof CoreKernel ? $kernel : null;
    }
}
