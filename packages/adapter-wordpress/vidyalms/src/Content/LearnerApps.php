<?php

declare(strict_types=1);

/**
 * VidyaLMS: the React learner apps (player, quiz runner) on the WordPress front end.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\WordPress\Admin\AdminApps;
use Shondalai\VidyaLMS\WordPress\Assets\MountBundles;
use Shondalai\VidyaLMS\WordPress\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the two learner app mounts:
 *
 *   - /courses/{slug}/learn renders <div id="vidyalms-player" ...> and loads
 *     the player bundle (templates/learn.php);
 *   - the single course permalink with ?vy-quiz=<lessonId> renders
 *     <div id="vidyalms-quiz-runner" ...> instead of the course page, which is
 *     the handoff URL the player builds from the mount contract's urls.course.
 *
 * The bundles are the build-time copy of packages/ui/dist under assets/app/
 * (unhashed file names, cache-busted by the plugin version). The entries are
 * ES modules, loaded through the script modules API; everything the apps know
 * about the host travels in the data-vy-config attribute, whose shape is the
 * fixed mount contract shared with the Joomla adapter.
 *
 * The api block uses the "prefix" URL mode against the plugin's own REST
 * namespace: logged-in cookie auth on /wp-json needs the wp_rest nonce, so the
 * config's headers carry X-WP-Nonce and the RestController resolves the caller
 * from get_current_user_id() as it always has.
 */
final class LearnerApps
{
    public const PLAYER_HANDLE = 'vidyalms-app-player';
    public const RUNNER_HANDLE = 'vidyalms-app-quiz-runner';

    /**
     * The lesson deep link and the quiz handoff parameters of the mount
     * contract. Plain GET parameters, identical on both platforms, which is
     * what lets the player build the handoff URL host-agnostically.
     */
    private const PARAM_LESSON = 'vy-lesson';
    private const PARAM_QUIZ = 'vy-quiz';

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'guard'], 4);
        add_action('template_redirect', [self::class, 'forbidPageCache'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets'], 6);
    }

    /**
     * Keeps the two mount pages out of every page cache. The data-vy-config
     * attribute embeds the visitor's own wp_rest nonce, so a cached copy would
     * replay one visitor's nonce to the next (the learn page is guest-reachable
     * on free preview). WordPress core does not page-cache, but the common
     * cache plugins (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed
     * Cache) honour the DONOTCACHEPAGE constant and the no-cache headers.
     */
    public static function forbidPageCache(): void
    {
        $isLearn = Rewrites::currentRoute() === Rewrites::ROUTE_LEARN;
        $isQuiz = self::quizLessonId() > 0 && is_singular(CourseCpt::POST_TYPE);

        if (!$isLearn && !$isQuiz) {
            return;
        }

        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }

        nocache_headers();
    }

    /**
     * The login guard, before any template renders. The player needs a
     * signed-in learner unless the course previews something for free, and
     * taking a quiz is always a signed-in act; both redirect through the
     * login form and come straight back.
     */
    public static function guard(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        if (Rewrites::currentRoute() === Rewrites::ROUTE_LEARN) {
            $course = self::learnCourse();

            if ($course !== null && !self::hasFreePreview($course)) {
                wp_safe_redirect(wp_login_url(Rewrites::learnUrl($course->slug->value, self::lessonId())));
                exit;
            }

            return;
        }

        if (self::quizLessonId() > 0 && is_singular(CourseCpt::POST_TYPE)) {
            $post = get_queried_object();
            $slug = $post instanceof \WP_Post ? (string) $post->post_name : '';

            if ($slug !== '') {
                wp_safe_redirect(wp_login_url(
                    add_query_arg(self::PARAM_QUIZ, (string) self::quizLessonId(), Rewrites::courseUrl($slug)),
                ));
                exit;
            }
        }
    }

    /**
     * App assets load only on the two pages that mount an app.
     */
    public static function enqueueAssets(): void
    {
        if (Rewrites::currentRoute() === Rewrites::ROUTE_LEARN) {
            self::enqueueApp(self::PLAYER_HANDLE, 'player');

            return;
        }

        if (self::quizLessonId() > 0 && is_singular(CourseCpt::POST_TYPE)) {
            self::enqueueApp(self::RUNNER_HANDLE, 'quiz-runner');
        }
    }

    /**
     * Whether this request is the quiz-runner handoff on a course permalink.
     * Read from the raw request rather than a query var: the parameter is not
     * part of WordPress routing, only of the mount contract.
     */
    public static function quizLessonId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch, no state changes.
        return isset($_GET[self::PARAM_QUIZ]) ? absint(wp_unslash($_GET[self::PARAM_QUIZ])) : 0;
    }

    /**
     * The lesson the player should deep-link to, or null to let it resume.
     */
    public static function lessonId(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view switch, no state changes.
        $lessonId = isset($_GET[self::PARAM_LESSON]) ? absint(wp_unslash($_GET[self::PARAM_LESSON])) : 0;

        return $lessonId > 0 ? $lessonId : null;
    }

    /**
     * The course of the /courses/{slug}/learn request, resolved through the
     * core so visibility rules hold; null when the slug is unknown or hidden.
     */
    public static function learnCourse(): ?Course
    {
        $slug = sanitize_title((string) get_query_var(Rewrites::QUERY_LEARN_SLUG, ''));

        return $slug === '' ? null : self::courseBySlug($slug);
    }

    public static function courseBySlug(string $slug): ?Course
    {
        $kernel = self::kernel();

        if ($kernel === null) {
            return null;
        }

        try {
            return $kernel->getCourseBySlug->execute(self::principal(), $slug);
        } catch (DomainException) {
            return null;
        }
    }

    /**
     * The template context of templates/learn.php.
     *
     * @return array<string, mixed>
     */
    public static function learnContext(): array
    {
        $course = self::learnCourse();

        $context = [
            'found' => $course !== null,
            'course' => $course,
            'title' => $course !== null ? (string) $course->title : '',
            'config_attribute' => '',
            'course_url' => Rewrites::catalogueUrl(),
        ];

        if ($course === null) {
            return $context;
        }

        $context['course_url'] = CourseCpt::permalink($course->id, $course->slug->value);
        $context['config_attribute'] = self::configAttribute($course, self::lessonId());

        return $context;
    }

    /**
     * The quiz-runner context the single course template renders on the
     * handoff URL.
     *
     * @return array<string, mixed>
     */
    public static function quizContext(Course $course): array
    {
        return [
            'course' => $course,
            'title' => (string) $course->title,
            'config_attribute' => self::configAttribute($course, self::quizLessonId()),
            'course_url' => CourseCpt::permalink($course->id, $course->slug->value),
        ];
    }

    /**
     * The mount contract for one course, ready for a data-vy-config attribute.
     */
    public static function configAttribute(Course $course, ?int $lessonId): string
    {
        $config = self::mountConfig($course, $lessonId);
        $json = wp_json_encode($config, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return esc_attr(\is_string($json) ? $json : '{}');
    }

    /**
     * The fixed mount contract, identical in shape to the Joomla adapter's
     * MountConfig service.
     *
     * @return array<string, mixed>
     */
    public static function mountConfig(Course $course, ?int $lessonId): array
    {
        $user = wp_get_current_user();

        return [
            'contract' => AdminApps::CONTRACT,
            'api' => [
                'base' => untrailingslashit(rest_url(RestController::REST_NAMESPACE)),
                'mode' => 'prefix',
                'headers' => ['X-WP-Nonce' => wp_create_nonce('wp_rest')],
            ],
            'course' => [
                'id' => $course->id,
                'slug' => $course->slug->value,
            ],
            'lessonId' => $lessonId !== null && $lessonId > 0 ? $lessonId : null,
            'user' => [
                'id' => (int) $user->ID,
                'name' => (int) $user->ID > 0 ? (string) $user->display_name : '',
            ],
            'locale' => str_replace('_', '-', (string) get_user_locale()),
            'messages' => \Shondalai\VidyaLMS\WordPress\Admin\AdminApps::messages(),
            'idleTimeoutSeconds' => (int) \Shondalai\VidyaLMS\WordPress\Setup\Settings::configuration()->get('learning_idle_timeout_seconds', 300),
            'urls' => [
                'course' => CourseCpt::permalink($course->id, $course->slug->value),
                'myLearning' => Rewrites::myLearningUrl(),
                'upload' => rest_url(\Shondalai\VidyaLMS\WordPress\Rest\UploadController::REST_NAMESPACE . '/upload'),
                'player' => Rewrites::learnUrl($course->slug->value, $lessonId),
            ],
        ];
    }

    /**
     * Whether any published lesson of the course previews for free, which is
     * what lets a guest open the player at all.
     */
    public static function hasFreePreview(Course $course): bool
    {
        $kernel = self::kernel();

        if ($kernel === null) {
            return false;
        }

        try {
            $sections = $kernel->getCurriculum->execute(self::principal(), $course->id);
        } catch (DomainException) {
            return false;
        }

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                if ($lesson->freePreview) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * One app bundle: the entry module plus its stylesheet. All dist files sit
     * flat in assets/app/, so the entry's relative imports (./shared.js,
     * ./vendor.js) resolve against the same directory.
     *
     * $entry is also the mount name the extra-bundle filter is asked with, so
     * a package contributing a renderer names "quiz-runner" rather than a
     * handle this class happens to have chosen.
     */
    private static function enqueueApp(string $handle, string $entry): void
    {
        $url = self::pluginUrl() . 'assets/app/';
        $version = self::assetVersion($entry . '.js');

        if (!is_file(self::pluginDir() . 'assets/app/' . $entry . '.js')) {
            // A build without node ships no bundles; a broken script tag would
            // hide that, an absent one surfaces it in the page.
            return;
        }

        wp_enqueue_style($handle, $url . $entry . '.css', [], self::assetVersion($entry . '.css'));
        wp_enqueue_script('react');
        wp_enqueue_script('react-dom');
        wp_enqueue_script('react-jsx-runtime');

        if (\function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module($handle, $url . $entry . '.js', [], $version);
            MountBundles::enqueueFor($entry, $handle);

            return;
        }

        // Pre-6.5 fallback: a classic tag rewritten to type="module".
        wp_enqueue_script($handle, $url . $entry . '.js', [], $version, true);
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

    private static function assetVersion(string $asset): string
    {
        // Fixed entry filenames need fresh URLs when their contents change.
        $file = self::pluginDir() . 'assets/app/' . $asset;

        if (is_file($file)) {
            return substr(hash_file('sha256', $file), 0, 12);
        }

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

    private static function principal(): \Shondalai\VidyaLMS\Core\Application\Shared\Principal
    {
        $userId = get_current_user_id();

        return $userId > 0
            ? \Shondalai\VidyaLMS\Core\Application\Shared\Principal::user($userId)
            : \Shondalai\VidyaLMS\Core\Application\Shared\Principal::guest();
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
