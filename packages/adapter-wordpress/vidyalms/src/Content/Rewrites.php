<?php

declare(strict_types=1);

/**
 * VidyaLMS: rewrite rules, query vars and URL builders for the learner paths.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The learner paths that are not posts: the catalogue, the learner dashboard,
 * public certificate verification and the course player. Each is a rewrite
 * rule that sets one query var the template loader reads.
 *
 * The catalogue owns /courses rather than leaning on the post type archive.
 * The listing comes from the core catalogue use case, not from WP_Query, so a
 * page beyond WordPress's own count of shell posts would be answered with a
 * 404 by the archive while the core still has courses to show. Owning the rule
 * makes the page number mean the same thing on both platforms. The single
 * course permalink /courses/{slug}/ stays with the post type: these rules match
 * a bare path, a /page/N path and the /learn suffix only.
 *
 * Rules are flushed on activation and, after an upgrade that changes them, once
 * on the first load that notices the version moved. Never on every load.
 */
final class Rewrites
{
    public const ROUTE_CATALOGUE = 'catalogue';
    public const ROUTE_MY_LEARNING = 'my-learning';
    public const ROUTE_CERTIFICATE = 'certificate';
    public const ROUTE_LEARN = 'learn';
    public const ROUTE_INSTRUCTOR = 'instructor';
    public const ROUTE_UNSUBSCRIBE = 'unsubscribe';
    public const ROUTE_PREFERENCES = 'preferences';

    public const QUERY_ROUTE = 'vidyalms_route';
    public const QUERY_PAGE = 'vidyalms_page';
    public const QUERY_CODE = 'vidyalms_code';
    public const QUERY_SEARCH = 'vidyalms_q';
    public const QUERY_CATEGORY = 'vidyalms_category';
    public const QUERY_LEARN_SLUG = 'vidyalms_learn_slug';
    public const QUERY_TOKEN = 'vidyalms_token';

    /**
     * Bumped whenever the rules below change, so an upgraded site reflushes
     * once instead of leaving learners on dead URLs.
     */
    private const RULES_VERSION = '6';

    private const RULES_VERSION_OPTION = 'vidyalms_rewrite_rules_version';

    /**
     * A verification code is opaque and short; the bound keeps a hand-built URL
     * from carrying a novel into the query.
     */
    private const MAX_CODE_LENGTH = 64;

    /**
     * A token is a payload plus an HMAC in URL-safe base64. Anything longer is
     * not one, and truncating rather than rejecting keeps the page's answer the
     * same for a malformed token and an expired one.
     */
    private const MAX_TOKEN_LENGTH = 512;

    /** @var array<string, string> */
    private const DEFAULT_SLUGS = [
        self::ROUTE_CATALOGUE => 'courses',
        self::ROUTE_MY_LEARNING => 'my-learning',
        self::ROUTE_CERTIFICATE => 'certificate',
        self::ROUTE_LEARN => 'learn',
        self::ROUTE_INSTRUCTOR => 'teach',
        self::ROUTE_UNSUBSCRIBE => 'unsubscribe',
        self::ROUTE_PREFERENCES => 'notification-preferences',
    ];

    public static function register(): void
    {
        add_action('init', [self::class, 'addRules'], 10);
        add_action('init', [self::class, 'flushIfRulesChanged'], 99);
        add_filter('query_vars', [self::class, 'queryVars']);
    }

    /**
     * Called from the plugin's activation hook. Activation happens before init
     * has run for the newly active plugin, so the post type is registered here
     * too: without it the flush would write a rewrite cache with no course
     * permalinks in it.
     */
    public static function activate(): void
    {
        if (is_multisite() && ms_is_switched()) {
            delete_option('rewrite_rules');
            delete_option(self::RULES_VERSION_OPTION);
            return;
        }
        if (!post_type_exists(CourseCpt::POST_TYPE)) {
            CourseCpt::registerPostType();
        }

        self::addRules();
        flush_rewrite_rules(false);
        update_option(self::RULES_VERSION_OPTION, self::RULES_VERSION, true);
    }

    public static function deactivate(): void
    {
        delete_option(self::RULES_VERSION_OPTION);
        // This request still has the plugin's rules registered. Let the next
        // request rebuild after WordPress has actually removed the plugin.
        delete_option('rewrite_rules');
    }

    public static function addRules(): void
    {
        $catalogue = self::slug(self::ROUTE_CATALOGUE);
        $myLearning = self::slug(self::ROUTE_MY_LEARNING);
        $certificate = self::slug(self::ROUTE_CERTIFICATE);

        self::addRule(
            $catalogue . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_CATALOGUE,
        );
        self::addRule(
            $catalogue . '/page/([0-9]{1,9})/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_CATALOGUE . '&' . self::QUERY_PAGE . '=$matches[1]',
        );
        // The learner player nests under the single course permalink:
        // /courses/{slug}/learn. Registered "top", so it wins over the post
        // type's own /courses/{slug} matching.
        self::addRule(
            $catalogue . '/([^/]+)/' . self::slug(self::ROUTE_LEARN) . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_LEARN . '&' . self::QUERY_LEARN_SLUG . '=$matches[1]',
        );
        self::addRule(
            $myLearning . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_MY_LEARNING,
        );
        self::addRule(
            $certificate . '/([^/]+)/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_CERTIFICATE . '&' . self::QUERY_CODE . '=$matches[1]',
        );
        self::addRule(
            $certificate . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_CERTIFICATE,
        );
        // The instructor workbench on the front end. It carries no segment of
        // its own: the course it opens rides as the vy-course parameter, the
        // way the player's lesson and quiz handoffs do, so one rule serves the
        // course list and every course.
        self::addRule(
            self::slug(self::ROUTE_INSTRUCTOR) . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_INSTRUCTOR,
        );
        // The unsubscribe landing page carries the signed token as a path
        // segment, so the URI printed in a message's List-Unsubscribe header
        // is a path rather than a query string, and the same URL answers the
        // human GET and the RFC 8058 one-click POST. The token alphabet is
        // URL-safe base64, so the segment pattern is deliberately narrow: a
        // path carrying anything else is not a token and is not this page.
        self::addRule(
            self::slug(self::ROUTE_UNSUBSCRIBE) . '/([A-Za-z0-9_.-]+)/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_UNSUBSCRIBE . '&' . self::QUERY_TOKEN . '=$matches[1]',
        );
        // A bare /unsubscribe with no token renders the page that says the
        // link is needed, rather than a 404 a recipient cannot act on.
        self::addRule(
            self::slug(self::ROUTE_UNSUBSCRIBE) . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_UNSUBSCRIBE,
        );
        // The preference centre keys on nothing: /me/notification-preferences
        // takes no user id and answers for whoever is signed in.
        self::addRule(
            self::slug(self::ROUTE_PREFERENCES) . '/?$',
            self::QUERY_ROUTE . '=' . self::ROUTE_PREFERENCES,
        );
    }

    /**
     * One rule, registered in both shapes a PATHINFO site can present.
     *
     * WordPress bakes the "index.php/" prefix into the rules it derives from
     * its own permastructs, but add_rewrite_rule() stores what it is given.
     * Which of the two forms the request is matched against depends on whether
     * the server supplies PATH_INFO: with it WordPress matches "my-learning",
     * without it "index.php/my-learning". Registering only the bare pattern
     * therefore works on some servers and 404s on others, for the same
     * permalink setting, which is worse than either.
     */
    private static function addRule(string $pattern, string $query): void
    {
        global $wp_rewrite;

        $target = 'index.php?' . $query;

        add_rewrite_rule($pattern, $target, 'top');

        if ($wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_index_permalinks()) {
            add_rewrite_rule($wp_rewrite->index . '/' . $pattern, $target, 'top');
        }
    }

    /**
     * @param array<int, string> $vars
     *
     * @return array<int, string>
     */
    public static function queryVars(array $vars): array
    {
        $vars[] = self::QUERY_ROUTE;
        $vars[] = self::QUERY_PAGE;
        $vars[] = self::QUERY_CODE;
        $vars[] = self::QUERY_SEARCH;
        $vars[] = self::QUERY_CATEGORY;
        $vars[] = self::QUERY_LEARN_SLUG;
        $vars[] = self::QUERY_TOKEN;

        return $vars;
    }

    /**
     * One flush after an upgrade that moved the rules, and none after that.
     */
    public static function flushIfRulesChanged(): void
    {
        if (get_option(self::RULES_VERSION_OPTION) === self::RULES_VERSION) {
            return;
        }

        flush_rewrite_rules(false);
        update_option(self::RULES_VERSION_OPTION, self::RULES_VERSION, true);
    }

    /**
     * The path segment for a route. Filterable so a site can translate it or
     * move it out of the way of an existing page; both the rules and every URL
     * builder read it from here, so they cannot drift apart.
     */
    public static function slug(string $route): string
    {
        $default = self::DEFAULT_SLUGS[$route] ?? $route;

        /**
         * Filters the path segment of a VidyaLMS learner route.
         *
         * @param string $slug  Default path segment.
         * @param string $route One of the ROUTE_* constants.
         */
        $slug = (string) apply_filters('vidyalms/route_slug', $default, $route);
        $slug = sanitize_title($slug);

        return $slug !== '' ? $slug : $default;
    }

    /**
     * The route being served, or an empty string on any other page.
     */
    public static function currentRoute(): string
    {
        $route = (string) get_query_var(self::QUERY_ROUTE, '');

        $known = [
            self::ROUTE_CATALOGUE,
            self::ROUTE_MY_LEARNING,
            self::ROUTE_CERTIFICATE,
            self::ROUTE_LEARN,
            self::ROUTE_INSTRUCTOR,
            self::ROUTE_UNSUBSCRIBE,
            self::ROUTE_PREFERENCES,
        ];

        return \in_array($route, $known, true) ? $route : '';
    }

    /**
     * @param array<string, string> $args extra query arguments (search, category)
     */
    public static function catalogueUrl(array $args = [], int $page = 1): string
    {
        $path = self::slug(self::ROUTE_CATALOGUE) . '/';

        if ($page > 1) {
            $path .= 'page/' . $page . '/';
        }

        $url = self::routeUrl($path, [self::QUERY_ROUTE => self::ROUTE_CATALOGUE]
            + ($page > 1 ? [self::QUERY_PAGE => (string) $page] : []));

        // add_query_arg encodes the values it is given, so they are passed raw.
        return $args === [] ? $url : add_query_arg($args, $url);
    }

    public static function courseUrl(string $slug): string
    {
        $slug = sanitize_title($slug);

        return $slug === ''
            ? self::catalogueUrl()
            : self::routeUrl(self::slug(self::ROUTE_CATALOGUE) . '/' . $slug . '/',
                ['post_type' => CourseCpt::POST_TYPE, 'name' => $slug]);
    }

    public static function myLearningUrl(): string
    {
        return self::routeUrl(self::slug(self::ROUTE_MY_LEARNING) . '/', [self::QUERY_ROUTE => self::ROUTE_MY_LEARNING]);
    }

    /**
     * The instructor workbench on the front end. With no course it is the
     * caller's own course list, which is what the app renders when the mount
     * carries no course.
     *
     * $course is a string rather than an int because the mount contract wants
     * a URL template carrying a {course_id} token, and the caller substitutes
     * the brace form after add_query_arg has encoded everything else.
     */
    public static function instructorUrl(string $course = ''): string
    {
        $url = self::routeUrl(self::slug(self::ROUTE_INSTRUCTOR) . '/', [self::QUERY_ROUTE => self::ROUTE_INSTRUCTOR]);

        return $course === '' ? $url : add_query_arg(InstructorPage::PARAM_COURSE, $course, $url);
    }

    /**
     * The learner player for one course, optionally deep-linked to a lesson.
     * The lesson rides as the vy-lesson query parameter, matching what the
     * player mount reads on both platforms.
     */
    public static function learnUrl(string $slug, ?int $lessonId = null): string
    {
        $slug = sanitize_title($slug);

        if ($slug === '') {
            return self::catalogueUrl();
        }

        $url = self::routeUrl(
            self::slug(self::ROUTE_CATALOGUE) . '/' . $slug . '/' . self::slug(self::ROUTE_LEARN) . '/',
            [self::QUERY_ROUTE => self::ROUTE_LEARN, self::QUERY_LEARN_SLUG => $slug],
        );

        return $lessonId !== null && $lessonId > 0
            ? add_query_arg('vy-lesson', (string) $lessonId, $url)
            : $url;
    }

    /**
     * The URI a message's List-Unsubscribe header carries. It is the same URL
     * the human clicks and the one a mail client one-click POSTs to, because
     * the header pair gives a receiver one URI and it uses it both ways.
     */
    public static function unsubscribeUrl(string $token = ''): string
    {
        $token = self::sanitizeToken($token);
        return self::routeUrl(self::slug(self::ROUTE_UNSUBSCRIBE) . '/'
            . ($token === '' ? '' : rawurlencode($token) . '/'),
            [self::QUERY_ROUTE => self::ROUTE_UNSUBSCRIBE]
                + ($token === '' ? [] : [self::QUERY_TOKEN => $token]));
    }

    public static function preferencesUrl(): string
    {
        return self::routeUrl(self::slug(self::ROUTE_PREFERENCES) . '/', [self::QUERY_ROUTE => self::ROUTE_PREFERENCES]);
    }

    /**
     * An unsubscribe token is URL-safe base64 over a payload and an HMAC, so
     * its alphabet is letters, digits, hyphen, underscore and dot. Anything
     * else in the segment is not a token, and it is stripped rather than
     * rejected so a mangled link renders the same "not valid" page as an
     * expired one, which is also what keeps the page from telling a guesser
     * which of the two it was.
     */
    public static function sanitizeToken(string $token): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_.-]/', '', $token) ?? '';

        return substr($clean, 0, self::MAX_TOKEN_LENGTH);
    }

    public static function certificateUrl(string $code = ''): string
    {
        $code = self::sanitizeCode($code);
        return self::routeUrl(self::slug(self::ROUTE_CERTIFICATE) . '/'
            . ($code === '' ? '' : rawurlencode($code) . '/'),
            [self::QUERY_ROUTE => self::ROUTE_CERTIFICATE]
                + ($code === '' ? [] : [self::QUERY_CODE => $code]));
    }

    /** Core PublicLink uses the value slot when the host requires query routing. */
    public static function certificateBaseUrl(): string
    {
        return self::needsPrettyPermalinks()
            ? str_replace('%7Bvalue%7D', '{value}', add_query_arg(self::QUERY_CODE, '{value}', self::certificateUrl()))
            : self::certificateUrl();
    }

    public static function unsubscribeBaseUrl(): string
    {
        return self::needsPrettyPermalinks()
            ? str_replace('%7Bvalue%7D', '{value}', add_query_arg(self::QUERY_TOKEN, '{value}', self::unsubscribeUrl()))
            : self::unsubscribeUrl();
    }

    /**
     * A site URL for one of the rewrite rules above.
     *
     * WordPress offers two shapes of pretty permalink and the rules only match
     * under the one the site chose. With a PATHINFO structure (the "/index.php/"
     * prefix WordPress falls back to where the server cannot rewrite) every
     * matched path carries that prefix, so a URL built without it is a 404 on a
     * site that is otherwise working. get_permalink() handles this for posts;
     * these routes are not posts, so it is handled here.
     *
     * A site with permalinks left on "Plain" has no rewrite rules at all. The
     * query var form is what answers there, which is why Rewrites registers the
     * vars as well as the rules.
     */
    private static function routeUrl(string $path, array $query): string
    {
        global $wp_rewrite;

        $structure = get_option('permalink_structure');

        if (!\is_string($structure) || $structure === '') {
            return add_query_arg($query, home_url('/'));
        }

        $prefix = $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_index_permalinks()
            ? 'index.php/'
            : '';

        return home_url('/' . $prefix . ltrim($path, '/'));
    }

    /**
     * True for Plain permalinks: callers must use the registered query vars.
     */
    public static function needsPrettyPermalinks(): bool
    {
        $structure = get_option('permalink_structure');

        return !\is_string($structure) || $structure === '';
    }

    /**
     * Codes are printed on certificates and typed by hand, so they are trimmed
     * and bounded rather than rejected. An unknown code is a valid question the
     * core answers with "not found".
     */
    public static function sanitizeCode(string $code): string
    {
        $code = sanitize_text_field($code);
        $code = trim(wp_strip_all_tags($code));

        return substr($code, 0, self::MAX_CODE_LENGTH);
    }
}
