<?php

declare(strict_types=1);

/**
 * VidyaLMS: the course post type, a permalink and SEO shell over the core.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\WordPress\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The vidyalms_course post type exists for two reasons only: a course needs a
 * permalink WordPress understands, and search engines need a page with a title,
 * a description and a canonical URL. Nothing else.
 *
 * Every fact about a course (state, price, curriculum, enrolment, progress)
 * lives in the vidyalms_ tables and is read through core use cases. The post is
 * downstream of that, never upstream: CourseSync writes the title and the slug
 * in one direction, and no LMS value is ever written to postmeta. Editing the
 * post title in WordPress therefore changes nothing and is overwritten by the
 * next sync, which is why "Add New" is disabled for the type.
 *
 * The one WordPress-side fact stored here is the course id to post id map, kept
 * in a single option rather than in postmeta: it is adapter plumbing that lets
 * a slug change without orphaning a shell, not course data.
 */
final class CourseCpt
{
    public const POST_TYPE = 'vidyalms_course';

    /**
     * Course categories. The taxonomy exists so a site owner can name and order
     * categories with the screen WordPress already has; the value the LMS
     * stores is the term_id, in the core's courses.category_id column, and no
     * term is ever assigned to a shell post. That is why the terms are managed
     * but not shown on the post: assigning one would be a second source of
     * truth for the same fact.
     */
    public const CATEGORY_TAXONOMY = 'vidyalms_course_category';

    /**
     * course id => shell post id. An option, not postmeta, and not autoloaded:
     * only sync and permalink resolution ever read it.
     */
    private const SHELL_MAP_OPTION = 'vidyalms_course_shells';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostType'], 5);
        add_action('init', [self::class, 'registerTaxonomy'], 6);
        add_action('template_redirect', [self::class, 'guardSingular'], 0);
        add_filter('wp_robots', [self::class, 'robots']);
        add_filter('posts_where', [self::class, 'excludeDiscovery'], 10, 2);
        add_filter('wp_sitemaps_post_types', [self::class, 'excludeSitemap']);
        // Run after WordPress adds the rich response: it can turn false back
        // into an array. The controller converts our final false into a 404.
        add_filter('oembed_response_data', [self::class, 'excludeOembed'], PHP_INT_MAX, 2);
    }

    /** Deny before a theme or SEO plugin can read a published shell's title. */
    public static function guardSingular(): void
    {
        if (!is_singular(self::POST_TYPE)) {
            return;
        }
        try {
            $post = get_queried_object();
            if ($post instanceof WP_Post && $post->post_type === self::POST_TYPE && $post->post_name !== '') {
                $userId = get_current_user_id();
                Plugin::kernel()->getCourseBySlug->execute($userId > 0 ? Principal::user($userId) : Principal::guest(), $post->post_name);
                return;
            }
        } catch (\Throwable) {
            // Missing courses and unavailable policy services fail closed.
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        wp_die(esc_html__('Course not found.', 'vidyalms'), esc_html__('Not found', 'vidyalms'), ['response' => 404]);
    }

    /**
     * Native embeds bypass course access rules. Course discovery belongs to
     * the LMS catalogue, even when a shell retains a queryable permalink.
     *
     * @param array<string, mixed>|false $data
     * @return array<string, mixed>|false
     */
    public static function excludeOembed(array|false $data, WP_Post $post): array|false
    {
        return $post->post_type === self::POST_TYPE ? false : $data;
    }

    /** WordPress discovery cannot evaluate the LMS's visibility and availability rules. */
    public static function excludeDiscovery(string $where, \WP_Query $query): string
    {
        // Only a guarded singular route may expose a course shell. Explicit
        // post_type queries and secondary archive loops bypass the catalogue.
        if (!is_admin() && !$query->is_singular()) {
            global $wpdb;
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.post_type <> %s", self::POST_TYPE);
        }

        return $where;
    }

    /** @param array<string, \WP_Post_Type> $types @return array<string, \WP_Post_Type> */
    public static function excludeSitemap(array $types): array
    {
        unset($types[self::POST_TYPE]);
        return $types;
    }

    /**
     * A course whose restriction is authored hidden is not indexed.
     *
     * The answer is the core's rather than this file's reading of it, so this
     * host, the Joomla search indexer and a future third adapter cannot
     * disagree about what a hidden restriction means: a hidden item is
     * **absent** rather than present and unreachable, because the title
     * itself is the leak. It is the same question the search indexer asks on
     * the other host, and asking it in one place is the point.
     *
     * Missing course data fails closed; indexing must never disclose a title
     * merely because the learning engine could not evaluate its policy.
     *
     * @param array<string, mixed> $robots
     *
     * @return array<string, mixed>
     */
    public static function robots(array $robots): array
    {
        if (!is_singular(self::POST_TYPE)) {
            return $robots;
        }

        $courseId = self::courseIdForShell((int) get_queried_object_id());

        try {
            $row = Plugin::database()->selectOne(Query::from('courses')->where('id', '=', $courseId));
            $publishable = $row !== null && $row['state'] === 'published' && $row['visibility'] === 'public'
                && Plugin::kernel()->availability->titleIsPublishable(
                new GateSubject(GateSubject::TYPE_COURSE, $courseId),
            );
        } catch (\Throwable) {
            $publishable = false;
        }

        if ($publishable) {
            return $robots;
        }

        $robots['noindex'] = true;
        unset($robots['index']);

        return $robots;
    }

    /**
     * Registered after the post type, because a taxonomy attached to a type
     * that does not exist yet is dropped.
     */
    public static function registerTaxonomy(): void
    {
        register_taxonomy(self::CATEGORY_TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => _x('Course categories', 'taxonomy general name', 'vidyalms'),
                'singular_name' => _x('Course category', 'taxonomy singular name', 'vidyalms'),
                'menu_name' => __('Categories', 'vidyalms'),
                'all_items' => __('All course categories', 'vidyalms'),
                'edit_item' => __('Edit course category', 'vidyalms'),
                'update_item' => __('Update course category', 'vidyalms'),
                'add_new_item' => __('Add course category', 'vidyalms'),
                'new_item_name' => __('New course category name', 'vidyalms'),
                'search_items' => __('Search course categories', 'vidyalms'),
                'not_found' => __('No course categories found.', 'vidyalms'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'hierarchical' => true,
            'show_ui' => true,
            // The plugin owns its own menu; Admin\Menu links the term screen.
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_admin_column' => false,
            'show_in_quick_edit' => false,
            // The course's category lives in the LMS table, so the metabox that
            // would let an editor set a second, contradictory value is off.
            'meta_box_cb' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'capabilities' => [
                'manage_terms' => 'vidyalms_manage',
                'edit_terms' => 'vidyalms_manage',
                'delete_terms' => 'vidyalms_manage',
                'assign_terms' => 'vidyalms_teach',
            ],
        ]);
    }

    public static function registerPostType(): void
    {
        $labels = [
            'name' => _x('Courses', 'post type general name', 'vidyalms'),
            'singular_name' => _x('Course', 'post type singular name', 'vidyalms'),
            'menu_name' => _x('Courses', 'admin menu', 'vidyalms'),
            'name_admin_bar' => _x('Course', 'add new on admin bar', 'vidyalms'),
            'edit_item' => __('Edit course page', 'vidyalms'),
            'view_item' => __('View course', 'vidyalms'),
            'view_items' => __('View courses', 'vidyalms'),
            'all_items' => __('Course pages', 'vidyalms'),
            'search_items' => __('Search courses', 'vidyalms'),
            'not_found' => __('No courses found.', 'vidyalms'),
            'not_found_in_trash' => __('No courses found in Trash.', 'vidyalms'),
            'archives' => __('Course catalogue', 'vidyalms'),
            'item_published' => __('Course page published.', 'vidyalms'),
            'item_updated' => __('Course page updated.', 'vidyalms'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'description' => __('Public course pages. Course data itself lives in VidyaLMS, not in this post.', 'vidyalms'),
            // Only the LMS catalogue may discover courses. The shell still
            // resolves a direct permalink through the access-aware template.
            'public' => false,
            'publicly_queryable' => true,
            'embeddable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            // The plugin owns its own admin menu; a second top-level "Courses"
            // entry would offer an editing story the shell cannot honour.
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'menu_icon' => 'dashicons-welcome-learn-more',
            'supports' => ['title', 'editor'],
            // Content\Rewrites owns /courses and /courses/page/N, because the
            // listing comes from the core catalogue use case rather than from
            // WP_Query. An archive here would register its own rules for the
            // same paths, win them (register_post_type adds them at 'top'
            // before our init runs), and answer page 2 with a 404 whenever
            // WordPress holds fewer shell posts than the core holds courses.
            // The single permalink /courses/{slug} is unaffected: that comes
            // from 'rewrite' below, not from the archive.
            'has_archive' => false,
            'rewrite' => [
                'slug' => Rewrites::slug(Rewrites::ROUTE_CATALOGUE),
                'with_front' => false,
                'feeds' => false,
                'pages' => true,
            ],
            'query_var' => true,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            // A shell is created by sync or not at all: hand-made posts would
            // claim a slug the core expects to own.
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'delete_with_user' => false,
            'can_export' => true,
            'hierarchical' => false,
            'taxonomies' => [],
        ]);
    }

    /**
     * Public course URLs for a page of catalogue rows, resolved in one query.
     *
     * @param list<array{id: int, slug: string}> $courses
     *
     * @return array<int, string> course id => URL
     */
    public static function permalinks(array $courses): array
    {
        $slugs = [];

        foreach ($courses as $course) {
            $slug = trim((string) ($course['slug'] ?? ''));

            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }

        $bySlug = self::publishedShellsBySlug($slugs);
        $urls = [];

        foreach ($courses as $course) {
            $courseId = (int) ($course['id'] ?? 0);
            $slug = trim((string) ($course['slug'] ?? ''));

            if ($courseId <= 0) {
                continue;
            }

            $post = $bySlug[$slug] ?? self::mappedShell($courseId);
            $urls[$courseId] = $post instanceof WP_Post
                ? (string) get_permalink($post)
                : Rewrites::courseUrl($slug);
        }

        return $urls;
    }

    /**
     * The public URL of one course. The shell wins when it exists so the
     * canonical link and the catalogue link are the same URL.
     */
    public static function permalink(int $courseId, string $slug): string
    {
        $urls = self::permalinks([['id' => $courseId, 'slug' => $slug]]);

        return $urls[$courseId] ?? Rewrites::courseUrl($slug);
    }

    public static function shellIdForCourse(int $courseId): int
    {
        $map = self::shellMap();

        return (int) ($map[$courseId] ?? 0);
    }

    /**
     * The course id a shell stands for, or 0. Used by sync to decide between
     * updating a shell and creating one.
     */
    public static function courseIdForShell(int $postId): int
    {
        foreach (self::shellMap() as $courseId => $shellId) {
            if ($shellId === $postId) {
                return $courseId;
            }
        }

        return 0;
    }

    public static function rememberShell(int $courseId, int $postId): void
    {
        if ($courseId <= 0 || $postId <= 0) {
            return;
        }

        $map = self::shellMap();

        if (($map[$courseId] ?? 0) === $postId) {
            return;
        }

        $map[$courseId] = $postId;

        self::saveShellMap($map);
    }

    public static function forgetShell(int $courseId): void
    {
        $map = self::shellMap();

        if (!isset($map[$courseId])) {
            return;
        }

        unset($map[$courseId]);

        self::saveShellMap($map);
    }

    /**
     * @return array<int, int>
     */
    public static function shellMap(): array
    {
        $stored = get_option(self::SHELL_MAP_OPTION, []);

        if (!\is_array($stored)) {
            return [];
        }

        $map = [];

        foreach ($stored as $courseId => $postId) {
            $courseId = (int) $courseId;
            $postId = (int) $postId;

            if ($courseId > 0 && $postId > 0) {
                $map[$courseId] = $postId;
            }
        }

        return $map;
    }

    /**
     * @param array<int, int> $map
     */
    private static function saveShellMap(array $map): void
    {
        update_option(self::SHELL_MAP_OPTION, $map, false);
    }

    private static function mappedShell(int $courseId): ?WP_Post
    {
        $postId = self::shellIdForCourse($courseId);

        if ($postId <= 0) {
            return null;
        }

        $post = get_post($postId);

        return $post instanceof WP_Post && $post->post_type === self::POST_TYPE && $post->post_status === 'publish'
            ? $post
            : null;
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, WP_Post>
     */
    private static function publishedShellsBySlug(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs)));

        if ($slugs === []) {
            return [];
        }

        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_name__in' => $slugs,
            'numberposts' => \count($slugs),
            'orderby' => 'none',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        $bySlug = [];

        foreach ($posts as $post) {
            if ($post instanceof WP_Post) {
                $bySlug[$post->post_name] = $post;
            }
        }

        return $bySlug;
    }
}
