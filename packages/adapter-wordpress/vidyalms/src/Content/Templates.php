<?php

declare(strict_types=1);

/**
 * VidyaLMS: template loading, view contexts and presentation helpers.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateVerification;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCatalogue;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Http\Middleware\NoIndexMiddleware;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\WordPress\Setup\Settings;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The public surface: which file renders a VidyaLMS page, what data that file
 * gets, and the small formatting helpers the files share.
 *
 * Every page is server rendered because the catalogue and the course page have
 * to be readable by a search engine, and because every page here has to be
 * readable by a person with JavaScript off. Certificate verification is server
 * rendered for the second reason only: it is deliberately noindex, because it
 * names a person against a completion. The React player is a later, separate
 * concern.
 *
 * A theme may override any template by dropping a file of the same name into a
 * vidyalms/ directory: vidyalms/catalogue.php replaces the page, and
 * vidyalms/part-catalogue.php replaces just the markup that shortcodes and
 * blocks render. The theme file is handed the same $context array, which is why
 * the contexts are plain arrays of scalars, core value objects and prebuilt
 * URLs rather than live services.
 *
 * No rule is decided here. Enrolment eligibility, visibility of draft units and
 * the meaning of a certificate code all come back from a use case; this class
 * asks and formats.
 */
final class Templates
{
    public const STYLE_HANDLE = 'vidyalms';

    private const SELF_ENROL_ACTION = 'self_enrol';

    private const NOTICE_ARG = 'vidyalms_notice';

    /** Already-rendered theme footer; its script dependencies must be registered before wp_head(). */
    private static ?string $blockFooterHtml = null;

    private static bool $privatePlacement = false;

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'handleRequest'], 5);
        add_filter('template_include', [self::class, 'chooseTemplate'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'registerAssets'], 5);
        add_filter('document_title_parts', [self::class, 'documentTitle']);
        add_filter('wp_robots', [self::class, 'robots']);
        add_filter('body_class', [self::class, 'bodyClass']);
    }

    /**
     * Runs before the template is chosen: the self enrolment post is answered
     * with a redirect (so a refresh cannot enrol twice), and the virtual routes
     * are told they are not a 404.
     */
    public static function handleRequest(): void
    {
        self::handleSelfEnrolment();
        self::softenNotFound();
        $post = is_singular() ? get_post() : null;
        if ($post !== null) {
            foreach ([Shortcodes::MY_LEARNING, Shortcodes::CERTIFICATE_VERIFY, Shortcodes::UNSUBSCRIBE, Shortcodes::PREFERENCES] as $shortcode) {
                self::$privatePlacement = self::$privatePlacement || has_shortcode((string) $post->post_content, $shortcode);
            }
            self::$privatePlacement = self::$privatePlacement || has_block(Blocks::MY_LEARNING, $post) || has_block(Blocks::CERTIFICATE_VERIFY, $post);
        }
        self::noIndexDisclosureSurfaces();
        if (self::$privatePlacement) {
            NotificationPages::forbidCaching();
        }
        if (is_singular(CourseCpt::POST_TYPE)
            || \in_array(Rewrites::currentRoute(), [Rewrites::ROUTE_MY_LEARNING, Rewrites::ROUTE_CERTIFICATE], true) || ($post !== null
            && (has_shortcode((string) $post->post_content, Shortcodes::MY_LEARNING)
                || has_block(Blocks::MY_LEARNING, $post)))) {
            self::refuseLearningCache();
        }
    }

    /**
     * The header half of the noindex rule, beside the meta half in robots().
     *
     * A crawler that ignores one of the two usually honours the other, and the
     * design asks for both on the verification page: it names a person against
     * a course completion. The value is the core middleware's own, so the rule
     * is stated once and this only carries it onto a transport the middleware
     * cannot reach, which is a page rendered by the host rather than returned
     * by the kernel.
     */
    private static function noIndexDisclosureSurfaces(): void
    {
        if ((!self::$privatePlacement && Rewrites::currentRoute() !== Rewrites::ROUTE_CERTIFICATE) || headers_sent()) {
            return;
        }

        header(NoIndexMiddleware::HEADER . ': ' . NoIndexMiddleware::VALUE, true);
    }

    public static function chooseTemplate(string $template): string
    {
        $found = '';

        switch (Rewrites::currentRoute()) {
            case Rewrites::ROUTE_CATALOGUE:
                $found = self::locate('catalogue');
                break;

            case Rewrites::ROUTE_MY_LEARNING:
                $found = self::locate('my-learning');
                break;

            case Rewrites::ROUTE_LEARN:
                $found = self::locate('learn');
                break;

            case Rewrites::ROUTE_CERTIFICATE:
                $found = self::locate('certificate');
                break;

            case Rewrites::ROUTE_INSTRUCTOR:
                $found = self::locate('instructor');
                break;

            default:
                if (is_singular(CourseCpt::POST_TYPE)) {
                    $found = self::locate('single-course');
                } elseif (is_post_type_archive(CourseCpt::POST_TYPE)) {
                    $found = self::locate('catalogue');
                }
        }

        return $found !== '' ? $found : $template;
    }

    public static function registerAssets(): void
    {
        self::registerStyle();

        if (self::isVidyaLmsView()) {
            wp_enqueue_style(self::STYLE_HANDLE);
            self::enqueueNavigation();
        }
    }

    /**
     * Called from every render path as well, so a shortcode or block on an
     * ordinary page gets the stylesheet and no other page ever does.
     */
    public static function enqueue(): void
    {
        self::registerStyle();
        wp_enqueue_style(self::STYLE_HANDLE);
        self::enqueueNavigation();
    }

    /**
     * @param array<string, string> $parts
     *
     * @return array<string, string>
     */
    public static function documentTitle(array $parts): array
    {
        switch (Rewrites::currentRoute()) {
            case Rewrites::ROUTE_CATALOGUE:
                $parts['title'] = __('Course catalogue', 'vidyalms');
                break;

            case Rewrites::ROUTE_MY_LEARNING:
                $parts['title'] = __('My learning', 'vidyalms');
                break;

            case Rewrites::ROUTE_LEARN:
                $course = LearnerApps::learnCourse();
                $parts['title'] = $course !== null ? (string) $course->title : __('Course player', 'vidyalms');
                break;

            case Rewrites::ROUTE_CERTIFICATE:
                $parts['title'] = __('Verify a certificate', 'vidyalms');
                break;

            case Rewrites::ROUTE_INSTRUCTOR:
                $parts['title'] = __('Teach', 'vidyalms');
                break;
        }

        return $parts;
    }

    /**
     * The dashboard and the verification page both stay out of the index.
     *
     * Verification used to be indexable here, on the reasoning that the link
     * is printed on the certificate. That reasoning is wrong and
     * 13-learning-flow-and-credentials.md says why: the page discloses a
     * person's name against a course completion, which is personal data, so it
     * is noindex and rate limited alongside the credential routes and the
     * unsubscribe pair. Printed links still resolve; they are simply not
     * crawled into a searchable index of who completed what.
     *
     * The meta half is here and the header half comes from the core middleware
     * chain the page's contract call runs through, so the rule itself lives in
     * one place rather than being retyped per adapter.
     *
     * @param array<string, mixed> $robots
     *
     * @return array<string, mixed>
     */
    public static function robots(array $robots): array
    {
        $route = Rewrites::currentRoute();

        if (
            self::$privatePlacement || $route === Rewrites::ROUTE_MY_LEARNING
            || $route === Rewrites::ROUTE_LEARN
            || $route === Rewrites::ROUTE_INSTRUCTOR
            || $route === Rewrites::ROUTE_CERTIFICATE
        ) {
            unset($robots['index']);
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }

        return $robots;
    }

    /**
     * @param array<int, string> $classes
     *
     * @return array<int, string>
     */
    public static function bodyClass(array $classes): array
    {
        $route = Rewrites::currentRoute();

        if ($route !== '') {
            $classes[] = 'vidyalms-site';
            $classes[] = 'vidyalms-route-' . $route;
        } elseif (is_singular(CourseCpt::POST_TYPE) || is_post_type_archive(CourseCpt::POST_TYPE)) {
            $classes[] = 'vidyalms-site';
        }

        return $classes;
    }

    /**
     * Opens the page document around a VidyaLMS template.
     *
     * get_header() alone is wrong on a block theme, which is the WordPress
     * default: a block theme ships no header.php, so get_header() falls through
     * to wp-includes/theme-compat/header.php, which emits a deprecation on
     * every page load and renders none of the theme's own header. The document
     * is opened here instead and the theme's header template part is rendered
     * with the function block themes provide for exactly this.
     *
     * A classic theme keeps get_header(), so an existing header.php, and every
     * hook a theme hangs on it, still runs.
     */
    public static function pageHeader(): void
    {
        self::$blockFooterHtml = null;

        if (self::themeHasHeader()) {
            get_header();
            self::openThemeContent();

            return;
        }

        // Match WordPress's block template canvas: rendering, not just printing,
        // registers a block's styles and module imports. Both parts must run
        // before wp_head() prints the block theme's single import map.
        $headerHtml = '';
        $footerHtml = '';

        if (wp_is_block_theme()) {
            if (\function_exists('block_header_area')) {
                $headerHtml = self::captureBlockPart(static function (): void {
                    block_header_area();
                });
            }

            if (\function_exists('block_footer_area')) {
                $footerHtml = self::captureBlockPart(static function (): void {
                    block_footer_area();
                });
            }
        }

        self::$blockFooterHtml = $footerHtml;

        echo '<!DOCTYPE html>';
        echo '<html ';
        language_attributes();
        echo '>';
        echo '<head>';
        printf('<meta charset="%s" />', esc_attr(get_bloginfo('charset')));
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';

        // This canvas stands in for core's block template canvas, which is where a block theme's title is printed.
        DocumentTitle::onCanvas();
        wp_head();

        echo '</head>';
        printf('<body class="%s">', esc_attr(implode(' ', get_body_class())));

        wp_body_open();

        echo '<div class="wp-site-blocks">';

        // Native block renderers own escaping; print their captured markup once.
        echo $headerHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured block_header_area() output; native block renderers own context-sensitive escaping.
        self::openThemeContent();
    }

    /**
     * Closes what pageHeader() opened.
     */
    public static function pageFooter(): void
    {
        echo '</div></div>';

        if (self::themeHasHeader()) {
            get_footer();

            return;
        }

        $footerHtml = self::$blockFooterHtml ?? '';
        self::$blockFooterHtml = null;
        echo $footerHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Captured block_footer_area() output; filtering it would remove theme block markup.

        echo '</div>';

        wp_footer();

        echo '</body></html>';
    }

    /**
     * Routed pages have no theme post-content block to provide width or gutters.
     * Keep this outside the SPA shell; embedded shortcodes/blocks use their host
     * container instead. Never put the .vidyalms style scope on the document body.
     */
    private static function openThemeContent(): void
    {
        echo '<div class="vidyalms-theme-content"><div class="vidyalms-theme-container">';
    }

    /** @param callable(): void $render */
    private static function captureBlockPart(callable $render): string
    {
        $outerLevel = ob_get_level();
        ob_start();

        try {
            $render();

            // A render callback may leave nested buffers open. Fold those into
            // our buffer on success, but discard every owned buffer on failure.
            while (ob_get_level() > $outerLevel + 1) {
                if (!ob_end_flush()) {
                    throw new \RuntimeException('Theme block output buffer could not be finalized.');
                }
            }

            if (ob_get_level() !== $outerLevel + 1) {
                throw new \RuntimeException('Theme block renderer closed its output buffer.');
            }

            return (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $outerLevel) {
                if (!ob_end_clean()) {
                    break;
                }
            }
        }
    }

    /**
     * Whether the active theme actually ships a header.php.
     *
     * locate_template() cannot answer this: it falls back to
     * wp-includes/theme-compat/header.php and so returns a path for every
     * theme, block themes included. The theme directories are asked directly.
     */
    private static function themeHasHeader(): bool
    {
        if (file_exists(get_stylesheet_directory() . '/header.php')) {
            return true;
        }

        return is_child_theme() && file_exists(get_template_directory() . '/header.php');
    }

    /**
     * The theme copy of a template wins; the plugin copy is the fallback.
     */
    public static function locate(string $name): string
    {
        $name = sanitize_file_name($name);

        if ($name === '') {
            return '';
        }

        $found = (string) locate_template(['vidyalms/' . $name . '.php'], false, false);

        if ($found === '') {
            $candidate = self::pluginDir() . 'templates/' . $name . '.php';
            $found = file_exists($candidate) ? $candidate : '';
        }

        /**
         * Filters the resolved template path.
         *
         * @param string $found Absolute path, or an empty string when nothing matched.
         * @param string $name  Template name without its extension.
         */
        return (string) apply_filters('vidyalms/template', $found, $name);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function render(string $part, array $context): string
    {
        ob_start();
        self::output($part, $context);

        return (string) ob_get_clean();
    }

    /**
     * The included file reads $context. Everything in it is escaped by the
     * template at the point of output, never here.
     *
     * @param array<string, mixed> $context
     */
    public static function output(string $part, array $context): void
    {
        $file = self::locate($part);

        if ($file === '' || !is_readable($file)) {
            return;
        }

        self::enqueue();

        include $file;
    }

    /**
     * @param array<string, mixed> $args search, category, page, per_page, show_search
     *
     * @return array<string, mixed>
     */
    public static function catalogueContext(array $args = []): array
    {
        $search = isset($args['search'])
            ? sanitize_text_field((string) $args['search'])
            : self::requestSearch();
        $category = isset($args['category'])
            ? max(0, (int) $args['category'])
            : self::requestCategory();
        $page = isset($args['page']) ? max(1, (int) $args['page']) : self::requestPage();
        $perPage = isset($args['per_page']) ? (int) $args['per_page'] : Settings::cataloguePerPage();
        $perPage = min(GetCatalogue::MAX_PER_PAGE, max(1, $perPage));

        $context = [
            'available' => false,
            'search' => $search,
            'category' => $category,
            'page' => $page,
            'per_page' => $perPage,
            'total' => 0,
            'total_pages' => 0,
            'courses' => [],
            'pagination' => '',
            'show_search' => !isset($args['show_search']) || self::isTruthy($args['show_search']),
            'form_action' => self::catalogueFormAction(),
            'heading' => isset($args['heading']) ? (string) $args['heading'] : '',
        ];

        $kernel = self::kernel();

        if ($kernel === null) {
            return $context;
        }

        try {
            $result = $kernel->getCatalogue->execute(
                self::principal(),
                $search !== '' ? $search : null,
                $category > 0 ? $category : null,
                $page,
                $perPage,
            );
        } catch (DomainException) {
            return $context;
        }

        $context['available'] = true;
        $context['total'] = $result->total;
        $context['total_pages'] = (int) ceil($result->total / max(1, $result->perPage));
        $context['page'] = $result->page;

        $rows = [];

        foreach ($result->items as $course) {
            $rows[] = ['id' => $course->id, 'slug' => $course->slug->value];
        }

        $urls = CourseCpt::permalinks($rows);

        foreach ($result->items as $course) {
            $price = self::coursePrice($kernel, $course->id, $course->priceMode, $course->commerceSource);

            $context['courses'][] = [
                'id' => $course->id,
                'title' => $course->title,
                'url' => $urls[$course->id] ?? Rewrites::courseUrl($course->slug->value),
                'summary' => (string) $course->summary,
                'image' => (string) $course->image,
                'level' => (string) $course->level,
                'level_label' => self::levelLabel((string) $course->level),
                'is_free' => $course->priceMode === 'free',
                'price_label' => self::priceLabel($price),
                'featured' => $course->featured,
            ];
        }

        $context['pagination'] = self::paginationLinks($context['page'], $context['total_pages'], $search, $category);

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public static function courseContext(string $slug): array
    {
        $slug = sanitize_title($slug);
        $notice = self::notice();

        $context = [
            'available' => false,
            'found' => false,
            'course' => null,
            'sections' => [],
            // The core writes instructors and prerequisites but exposes no read
            // use case for either yet, so the page renders those blocks only
            // once one exists. The Joomla adapter has the same two empty slots.
            'instructors' => [],
            'prerequisites' => [],
            'is_guest' => !is_user_logged_in(),
            'enrolment' => null,
            'percent' => 0.0,
            'completed_lessons' => [],
            'resume_lesson_id' => null,
            'preview_urls' => [],
            'price_label' => '',
            'purchase_url' => '',
            'can_enrol' => false,
            'login_url' => wp_login_url(Rewrites::courseUrl($slug)),
            'course_url' => Rewrites::courseUrl($slug),
            'catalogue_url' => Rewrites::catalogueUrl(),
            'my_learning_url' => Rewrites::myLearningUrl(),
            'notice' => $notice['message'],
            'notice_type' => $notice['type'],
        ];

        $kernel = self::kernel();

        if ($kernel === null || $slug === '') {
            return $context;
        }

        $context['available'] = true;

        try {
            $course = $kernel->getCourseBySlug->execute(self::principal(), $slug);
        } catch (DomainException) {
            return $context;
        }

        $context['found'] = true;
        $context['course'] = $course;
        $context['course_url'] = CourseCpt::permalink($course->id, $course->slug->value);
        $context['login_url'] = wp_login_url($context['course_url']);

        try {
            $context['sections'] = $kernel->getCurriculum->execute(self::principal(), $course->id);
        } catch (DomainException) {
            $context['sections'] = [];
        }

        $enrolment = self::enrolmentFor($kernel, $course->id);
        $context['enrolment'] = $enrolment;

        if ($enrolment !== null) {
            $context['percent'] = round((float) ($enrolment['progress_percent'] ?? 0.0), 1);

            try {
                $progress = $kernel->getMyCourseProgress->execute(self::principal(), (int) $enrolment['id']);

                if ($progress->summary !== null) {
                    $context['percent'] = round($progress->summary->percent, 1);
                }

                foreach ($progress->lessons as $lesson) {
                    if ($lesson instanceof LessonProgress && $lesson->isCompleted()) {
                        $context['completed_lessons'][$lesson->lessonId] = true;
                    }
                }
            } catch (DomainException) {
                $context['completed_lessons'] = [];
            }

            $context['resume_lesson_id'] = self::resumeLessonId($context['sections'], $context['completed_lessons']);
        } else {
            $context['preview_urls'] = self::previewUrls($course->slug->value, $context['sections']);
        }

        if ($course->priceMode !== 'free') {
            $context['price_label'] = self::priceLabel(
                self::coursePrice($kernel, $course->id, $course->priceMode, $course->commerceSource)
            );
            $context['purchase_url'] = self::purchaseUrl(
                $kernel,
                $course->id,
                $course->priceMode,
                $course->commerceSource
            );
        }

        $context['can_enrol'] = $enrolment === null
            && !$context['is_guest']
            && self::selfEnrolmentAllowed($kernel, $course);

        return $context;
    }

    /**
     * @param array<string, mixed> $args show_certificates
     *
     * @return array<string, mixed>
     */
    public static function myLearningContext(array $args = []): array
    {
        self::refuseLearningCache();
        $context = [
            'available' => false,
            'is_guest' => !is_user_logged_in(),
            'enrolments' => [],
            'enrolment_total' => 0,
            'enrolment_page' => 1,
            'enrolment_per_page' => 20,
            'enrolment_pagination' => '',
            'organisation_filter' => null, 'organisation_options' => [],
            'learning_url' => self::myLearningBaseUrl(),
            'certificates' => [],
            'show_certificates' => !isset($args['show_certificates']) || self::isTruthy($args['show_certificates']),
            'catalogue_url' => Rewrites::catalogueUrl(),
            'achievements_url' => AchievementsLanding::url(),
            'organisations_url' => OrganisationLanding::url(),
            'preferences_url' => Rewrites::preferencesUrl(),
        ];

        if ($context['is_guest']) {
            $context['signin'] = SignInGate::context('mylearning', Rewrites::myLearningUrl());

            return $context;
        }

        $kernel = self::kernel();

        if ($kernel === null) {
            return $context;
        }

        $context['available'] = true;

        try {
            $filter = filter_var(self::getValue('organisation_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483646]]);
            $context['organisation_filter'] = $filter === false ? null : $filter;
            $context['organisation_options'] = $kernel->listMyEnrolments->organisations(self::principal());
            $requested = filter_var(self::getValue('vidyalms_learning_page'), FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            $result = $kernel->listMyEnrolments->page(self::principal(), $requested === false ? 1 : $requested, 20, null, $context['organisation_filter']);
            $lastPage = max(1, (int) ceil($result['total'] / 20));
            if ($result['page'] > $lastPage) {
                $result = $kernel->listMyEnrolments->page(self::principal(), $lastPage, 20, null, $context['organisation_filter']);
            }
            $enrolments = $result['items'];
            $context['enrolment_total'] = $result['total'];
            $context['enrolment_page'] = $result['page'];
            $context['enrolment_pagination'] = self::myLearningPagination($result['page'], $lastPage, $context['organisation_filter']);
        } catch (DomainException) {
            $context['available'] = false;
            return $context;
        }

        $rows = [];
        $titles = [];

        foreach ($enrolments as $enrolment) {
            $courseId = (int) ($enrolment['course_id'] ?? 0);
            $slug = (string) ($enrolment['course_slug'] ?? '');
            $title = (string) ($enrolment['course_title'] ?? '');

            if ($courseId > 0) {
                $titles[$courseId] = $title;
                $rows[] = ['id' => $courseId, 'slug' => $slug];
            }
        }

        $urls = CourseCpt::permalinks($rows);

        foreach ($enrolments as $enrolment) {
            $courseId = (int) ($enrolment['course_id'] ?? 0);
            $status = (string) ($enrolment['status'] ?? '');
            $slug = (string) ($enrolment['course_slug'] ?? '');

            $context['enrolments'][] = [
                'id' => (int) ($enrolment['id'] ?? 0),
                'course_id' => $courseId,
                'title' => (string) ($enrolment['course_title'] ?? ''),
                'url' => $urls[$courseId] ?? Rewrites::courseUrl($slug),
                'status' => $status,
                'status_label' => self::enrolmentStatusLabel($status),
                'organisations' => $enrolment['organisations'] ?? [],
                'personal_enrolment' => $enrolment['personal_enrolment'] ?? false,
                'percent' => round((float) ($enrolment['progress_percent'] ?? 0.0), 1),
                // The player is only offered where the learner can still work
                // in the enrolment; a suspended or cancelled one goes to the
                // course page, which explains itself.
                'learn_url' => $slug !== '' && \in_array($status, ['active', 'completed'], true)
                    ? Rewrites::learnUrl($slug)
                    : '',
            ];
        }

        if (!$context['show_certificates']) {
            return $context;
        }

        try {
            $certificates = $kernel->listMyCertificates->page(self::principal(), $kernel->ports()->clock->now(), 1, 6)['items'];
        } catch (DomainException) {
            $certificates = [];
        }

        foreach ($certificates as $certificate) {
            if (!$certificate instanceof Certificate) {
                continue;
            }

            // This six-item preview may refer to courses outside the current
            // enrolment page; look up only those exact owned relationships.
            if (!isset($titles[$certificate->courseId])) {
                $row = $kernel->listMyEnrolments->forCourse(self::principal(), $certificate->courseId);
                $titles[$certificate->courseId] = (string) ($row['course_title'] ?? '');
            }

            $context['certificates'][] = [
                'code' => $certificate->code,
                'title' => $titles[$certificate->courseId],
                'issued' => self::formatDate($certificate->issuedAt),
                'issued_iso' => self::isoDate($certificate->issuedAt),
                'expires' => self::formatDate($certificate->expiresAt),
                'expires_iso' => self::isoDate($certificate->expiresAt),
                'revoked' => $certificate->isRevoked(),
                'verify_url' => Rewrites::certificateUrl($certificate->code),
            ];
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public static function certificateContext(string $code): array
    {
        self::refuseLearningCache();
        $code = Rewrites::sanitizeCode($code);

        $context = [
            'available' => false,
            'code' => $code,
            'answered' => false,
            'valid' => false,
            'rate_limited' => false,
            'status' => '',
            'status_label' => '',
            'reason_label' => '',
            'course_title' => '',
            'learner_name' => '',
            'issuer_name' => '',
            'issuer_url' => '',
            'completed' => '',
            'completed_iso' => '',
            'issued' => '',
            'issued_iso' => '',
            'valid_from' => '',
            'valid_from_iso' => '',
            'expires' => '',
            'expires_iso' => '',
            'revoked_on' => '',
            'revoked_on_iso' => '',
            'credits' => [],
            'credential_url' => '',
            'superseded_by_url' => '',
            'show_form' => true,
            'form_action' => Rewrites::certificateUrl(),
        ];

        if (self::kernel() === null) {
            return $context;
        }

        $context['available'] = true;

        if ($code === '') {
            return $context;
        }

        // The contract rather than the use case behind it, because the
        // verification page discloses a person and both of the controls that
        // protect it, the rate limit and the noindex header, are core
        // middleware rather than a rule each adapter remembers. Going through
        // ApiKernel::handle() puts this page in the same chain as the REST
        // route, so a burst against either is counted once and the header
        // comes back on the response instead of being retyped here.
        //
        // An unknown code is an ordinary negative answer, so this page answers
        // every code the same way and never 404s: enumerating codes must
        // reveal nothing, and the printed link must always resolve.
        $answer = CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($code) . '/verify',
            self::principal(),
        );

        $context['rate_limited'] = $answer->rateLimited();
        $context['available'] = !$answer->routeMissing();

        if (!$answer->ok()) {
            return $context;
        }

        $data = $answer->data;
        $context['answered'] = true;
        $context['valid'] = ($data['valid'] ?? false) === true;
        $context['status'] = $answer->string('status');
        $context['status_label'] = self::verificationStatusLabel($context['status']);
        $context['reason_label'] = $context['valid']
            ? ''
            : self::verificationReasonLabel($answer->string('reason'));
        $context['course_title'] = $answer->string('course_title');
        $context['learner_name'] = $answer->string('learner_name');
        $context['credential_url'] = $answer->string('credential_url');
        $context['superseded_by_url'] = $answer->string('superseded_by_url');

        $issuer = $data['issuer'] ?? null;

        if (\is_array($issuer)) {
            $context['issuer_name'] = \is_string($issuer['name'] ?? null) ? $issuer['name'] : '';
            $context['issuer_url'] = \is_string($issuer['url'] ?? null) ? $issuer['url'] : '';
        }

        foreach (['completed_at' => 'completed', 'issued_at' => 'issued', 'valid_from' => 'valid_from', 'expires_at' => 'expires', 'revoked_at' => 'revoked_on'] as $field => $key) {
            $value = self::parseDate($answer->string($field));

            $context[$key] = self::formatDate($value);
            $context[$key . '_iso'] = self::isoDate($value);
        }

        foreach ($answer->rows('credits') as $credit) {
            $amount = \is_string($credit['amount'] ?? null) ? $credit['amount'] : '';
            $unit = \is_string($credit['unit'] ?? null) ? $credit['unit'] : '';
            $label = trim($amount . ' ' . $unit);

            if ($label !== '') {
                $context['credits'][] = $label;
            }
        }

        return $context;
    }

    /**
     * A contract datetime string as a date object, or null.
     *
     * The verification answer carries ISO strings because that is what the
     * wire shape is; the two date helpers beside this one take objects,
     * because everything else on these pages comes from the core as one.
     */
    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The six status values of the credential enumeration, translated.
     *
     * An unknown value is returned untranslated rather than dropped, so a
     * status a later build adds still shows rather than silently disappearing
     * from an evidentiary page.
     */
    private static function verificationStatusLabel(string $status): string
    {
        switch ($status) {
            case 'active':
                return __('In force', 'vidyalms');

            case 'not_yet_valid':
                return __('Not in force yet', 'vidyalms');

            case 'expired':
                return __('Expired', 'vidyalms');

            case 'suspended':
                return __('Suspended', 'vidyalms');

            case 'revoked':
                return __('Revoked', 'vidyalms');

            case 'superseded':
                return __('Superseded', 'vidyalms');
        }

        return $status;
    }

    public static function levelLabel(string $level): string
    {
        switch (strtolower(trim($level))) {
            case 'beginner':
                return __('Beginner', 'vidyalms');

            case 'intermediate':
                return __('Intermediate', 'vidyalms');

            case 'advanced':
                return __('Advanced', 'vidyalms');
        }

        return $level;
    }

    public static function lessonTypeLabel(string $type): string
    {
        switch (strtolower(trim($type))) {
            case 'reading':
                return __('Reading', 'vidyalms');

            case 'video':
                return __('Video', 'vidyalms');

            case 'resource':
                return __('Resource', 'vidyalms');

            case 'quiz':
                return __('Quiz', 'vidyalms');

            case 'assignment':
                return __('Assignment', 'vidyalms');
        }

        return $type;
    }

    public static function enrolmentStatusLabel(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'active':
                return __('Active', 'vidyalms');

            case 'suspended':
                return __('Suspended', 'vidyalms');

            case 'completed':
                return __('Completed', 'vidyalms');

            case 'cancelled':
                return __('Cancelled', 'vidyalms');
        }

        return $status;
    }

    public static function verificationReasonLabel(string $reason): string
    {
        switch ($reason) {
            case CertificateVerification::REASON_NOT_FOUND:
                return __('No certificate carries this code.', 'vidyalms');

            case CertificateVerification::REASON_REVOKED:
                return __('This certificate has been revoked.', 'vidyalms');

            case CertificateVerification::REASON_EXPIRED:
                return __('This certificate has expired.', 'vidyalms');

            // The three reasons that arrive with the status enumeration.
            // Suspension and supersession are materially different answers to
            // a relying party from revocation, so neither may fall through to
            // the generic line below.
            case 'suspended':
                return __('This certificate is on hold while it is reviewed.', 'vidyalms');

            case 'superseded':
                return __('This certificate has been replaced by a later one.', 'vidyalms');

            case 'not_yet_valid':
                return __('This certificate is not in force yet.', 'vidyalms');
        }

        return __('This certificate could not be verified.', 'vidyalms');
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '';
        }

        $minutes = max(1, (int) round($seconds / 60));

        if ($minutes < 60) {
            /* translators: %d: number of minutes. */
            return sprintf(_n('%d minute', '%d minutes', $minutes, 'vidyalms'), $minutes);
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($rest === 0) {
            /* translators: %d: number of hours. */
            return sprintf(_n('%d hour', '%d hours', $hours, 'vidyalms'), $hours);
        }

        /* translators: 1: number of hours, 2: number of minutes. */
        return sprintf(__('%1$d h %2$d min', 'vidyalms'), $hours, $rest);
    }

    public static function formatDate(?\DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '';
        }

        return (string) wp_date((string) get_option('date_format', 'Y-m-d'), $date->getTimestamp());
    }

    public static function isoDate(?\DateTimeImmutable $date): string
    {
        return $date === null ? '' : gmdate('c', $date->getTimestamp());
    }

    /**
     * Course artwork as the core stored it: an attachment id when the media
     * library holds it, otherwise a URL. Decorative, because the course title
     * always sits beside it.
     */
    public static function courseImage(string $image, string $class = 'vidyalms-card-image'): string
    {
        $image = trim($image);

        if ($image === '') {
            return '';
        }

        if (ctype_digit($image)) {
            return (string) wp_get_attachment_image(
                (int) $image,
                'medium_large',
                false,
                ['class' => $class, 'loading' => 'lazy', 'alt' => ''],
            );
        }

        $url = esc_url($image);

        if ($url === '') {
            return '';
        }

        return sprintf(
            '<img class="%1$s" src="%2$s" alt="" loading="lazy" decoding="async">',
            esc_attr($class),
            $url,
        );
    }

    public static function priceLabel(?CoursePrice $price): string
    {
        if ($price === null) {
            return '';
        }

        if ($price->formatted !== null && trim($price->formatted) !== '') {
            return $price->formatted;
        }

        return $price->currency . ' ' . number_format_i18n($price->amountMinor / 100, 2);
    }

    /**
     * The nonce field the self enrolment form must carry.
     */
    public static function enrolNonceField(int $courseId): string
    {
        return (string) wp_nonce_field(self::enrolNonceAction($courseId), 'vidyalms_nonce', true, false);
    }

    private static function enrolNonceAction(int $courseId): string
    {
        return 'vidyalms_self_enrol_' . $courseId;
    }

    /**
     * Whether to offer the enrol button, asked of the enrolment rule the core
     * will ask when the form is submitted.
     *
     * The page must not re-derive this. A second copy of the answer, whether it
     * reads the site setting again or repeats the rule's published and free
     * test, is free to drift from the one the POST is judged by, and a button
     * that leads to a refusal is the better half of that failure.
     */
    private static function selfEnrolmentAllowed(CoreKernel $kernel, Course $course): bool
    {
        return $kernel->selfEnrol->isAvailableFor(self::principal(), $course->id);
    }

    private static function handleSelfEnrolment(): void
    {
        if (sanitize_key(self::postValue('vidyalms_action')) !== self::SELF_ENROL_ACTION) {
            return;
        }

        $courseId = absint(self::postValue('vidyalms_course_id'));
        $slug = sanitize_title(self::postValue('vidyalms_course_slug'));
        $nonce = sanitize_text_field(self::postValue('vidyalms_nonce'));

        $redirect = $slug !== '' ? CourseCpt::permalink($courseId, $slug) : Rewrites::catalogueUrl();

        if ($courseId <= 0 || !wp_verify_nonce($nonce, self::enrolNonceAction($courseId))) {
            self::redirectWithNotice($redirect, 'invalid');
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($redirect));

            exit;
        }

        $kernel = self::kernel();

        if ($kernel === null) {
            self::redirectWithNotice($redirect, 'unavailable');
        }

        try {
            $kernel->selfEnrol->execute(self::principal(), $courseId);
        } catch (NotAuthorizedException) {
            self::redirectWithNotice($redirect, 'forbidden');
        } catch (EntityNotFoundException) {
            self::redirectWithNotice($redirect, 'missing');
        } catch (ConflictException) {
            self::redirectWithNotice($redirect, 'conflict');
        } catch (DomainException) {
            self::redirectWithNotice($redirect, 'failed');
        }

        self::redirectWithNotice($redirect, 'enrolled');
    }

    private static function redirectWithNotice(string $url, string $code): never
    {
        wp_safe_redirect(add_query_arg(self::NOTICE_ARG, $code, $url));

        exit;
    }

    /**
     * @return array{code: string, type: string, message: string}
     */
    private static function notice(): array
    {
        $empty = ['code' => '', 'type' => '', 'message' => ''];

        $code = sanitize_key(self::getValue(self::NOTICE_ARG));

        switch ($code) {
            case 'enrolled':
                return [
                    'code' => $code,
                    'type' => 'success',
                    'message' => __('You are enrolled. Your progress is saved as you go.', 'vidyalms'),
                ];

            case 'conflict':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('This course is not open for self enrolment.', 'vidyalms'),
                ];

            case 'forbidden':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('Please sign in to enrol on this course.', 'vidyalms'),
                ];

            case 'missing':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('That course could not be found.', 'vidyalms'),
                ];

            case 'invalid':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('The enrolment request could not be verified. Please try again.', 'vidyalms'),
                ];

            case 'unavailable':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('Enrolment is unavailable at the moment.', 'vidyalms'),
                ];

            case 'failed':
                return [
                    'code' => $code,
                    'type' => 'error',
                    'message' => __('Enrolment could not be completed.', 'vidyalms'),
                ];
        }

        return $empty;
    }

    private static function softenNotFound(): void
    {
        if (Rewrites::currentRoute() === '') {
            return;
        }

        global $wp_query;

        if (!$wp_query instanceof WP_Query) {
            return;
        }

        // These routes carry no post, so WordPress has nothing to report on;
        // without this they answer 404 with a page full of content.
        $wp_query->is_404 = false;
        $wp_query->is_home = false;
        $wp_query->is_archive = false;
        $wp_query->is_singular = false;
        $wp_query->is_single = false;
        $wp_query->is_page = false;

        status_header(200);
    }

    /**
     * @param list<\Shondalai\VidyaLMS\Core\Application\Curriculum\CurriculumSection> $sections
     * @param array<int, bool>                                                       $completed
     */
    private static function resumeLessonId(array $sections, array $completed): ?int
    {
        $first = null;

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                $first ??= $lesson->id;

                if (!isset($completed[$lesson->id])) {
                    return $lesson->id;
                }
            }
        }

        return $first;
    }

    /**
     * A lesson labelled free preview is only a preview if the label leads
     * somewhere: the learn page deep link for each one, which the login
     * guard opens to a guest whenever the course previews anything. Built
     * only for a visitor without an enrolment; an enrolled learner gets the
     * resume button and the player's own outline instead.
     *
     * @param list<\Shondalai\VidyaLMS\Core\Application\Curriculum\CurriculumSection> $sections
     *
     * @return array<int, string>
     */
    private static function previewUrls(string $slug, array $sections): array
    {
        $urls = [];

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                if ($lesson->freePreview) {
                    $urls[$lesson->id] = Rewrites::learnUrl($slug, $lesson->id);
                }
            }
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function enrolmentFor(CoreKernel $kernel, int $courseId): ?array
    {
        if (!is_user_logged_in()) {
            return null;
        }

        try {
            return $kernel->listMyEnrolments->forCourse(self::principal(), $courseId);
        } catch (DomainException) {
            return null;
        }
    }

    /** A constant-size pager, even when the learner has thousands of courses. */
    private static function myLearningBaseUrl(): string
    {
        $base = Rewrites::myLearningUrl();
        if (Rewrites::currentRoute() !== Rewrites::ROUTE_MY_LEARNING) {
            $queried = get_queried_object();
            $permalink = $queried instanceof WP_Post ? get_permalink($queried) : false;
            if (\is_string($permalink) && $permalink !== '') {
                $base = $permalink;
            }
        }
        return $base;
    }

    private static function myLearningPagination(int $page, int $totalPages, ?int $organisationId = null): string
    {
        if ($totalPages < 2) {
            return '';
        }
        $base = self::myLearningBaseUrl();
        if ($organisationId !== null) {
            $base = add_query_arg('organisation_id', $organisationId, $base);
        }
        $link = static fn (int $number, string $label, string $extra = ''): string
            => '<a class="page-numbers" ' . $extra . ' href="'
                . esc_url(add_query_arg('vidyalms_learning_page', $number, $base)) . '">' . esc_html($label) . '</a>';
        $links = $page > 1 ? $link($page - 1, __('Previous', 'vidyalms'), 'rel="prev"') : '';
        $numbers = array_values(array_unique([1, ...range(max(1, $page - 2), min($totalPages, $page + 2)), $totalPages]));
        sort($numbers);
        $previous = 0;
        foreach ($numbers as $number) {
            if ($previous > 0 && $number > $previous + 1) {
                $links .= '<span aria-hidden="true">…</span>';
            }
            $label = number_format_i18n($number);
            $links .= $number === $page ? '<span class="page-numbers current" aria-current="page">' . esc_html($label) . '</span>'
                : $link($number, $label);
            $previous = $number;
        }
        return $links . ($page < $totalPages ? $link($page + 1, __('Next', 'vidyalms'), 'rel="next"') : '');
    }

    private static function refuseLearningCache(): void
    {
        if (!\defined('DONOTCACHEPAGE')) {
            \define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    /**
     * The display price, from the commerce source the course names.
     *
     * Resolved through the registry rather than from a hardcoded key: with a
     * constant here, a site that registered a second commerce source and
     * pinned courses to it was priced correctly by the REST route and wrongly
     * by these templates, which are the pages a buyer reads. A course pinned
     * to a source whose plugin is gone answers no price rather than a price
     * from whichever shop happens to be installed.
     */
    private static function coursePrice(
        CoreKernel $kernel,
        int $courseId,
        string $priceMode,
        ?string $commerceSource = null
    ): ?CoursePrice {
        $sources = $kernel->registries->commerceSources;

        if ($priceMode === 'free') {
            return null;
        }

        $key = CommercePrices::resolveSourceKey($sources, $commerceSource);

        return $key === null ? null : $sources->get($key)->priceFor(['id' => $courseId]);
    }

    /**
     * The buy link comes from whichever shop plugin is installed, so anything
     * that is not a plain http(s) or site relative URL is dropped rather than
     * rendered.
     */
    private static function purchaseUrl(
        CoreKernel $kernel,
        int $courseId,
        string $priceMode,
        ?string $commerceSource = null
    ): string {
        $sources = $kernel->registries->commerceSources;

        if ($priceMode === 'free') {
            return '';
        }

        $key = CommercePrices::resolveSourceKey($sources, $commerceSource);

        if ($key === null) {
            return '';
        }

        $url = (string) $sources->get($key)->purchaseUrlFor(['id' => $courseId]);
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, '/')) {
            return $url;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 ? '' : $url;
    }

    private static function paginationLinks(int $page, int $totalPages, string $search, int $category): string
    {
        if ($totalPages < 2) {
            return '';
        }

        $extra = [];

        if ($search !== '') {
            $extra[Rewrites::QUERY_SEARCH] = $search;
        }

        if ($category > 0) {
            $extra[Rewrites::QUERY_CATEGORY] = $category;
        }

        if (Rewrites::currentRoute() === Rewrites::ROUTE_CATALOGUE) {
            $base = trailingslashit(Rewrites::catalogueUrl()) . 'page/%#%/';
        } else {
            // paginate_links substitutes %#% before it runs add_query_arg, so
            // the placeholder has to be appended by hand: add_query_arg would
            // encode the percent signs and the substitution would never match.
            $action = self::catalogueFormAction();
            $base = $action . (str_contains($action, '?') ? '&' : '?') . Rewrites::QUERY_PAGE . '=%#%';
        }

        $links = paginate_links([
            'base' => $base,
            'format' => '',
            'current' => max(1, $page),
            'total' => $totalPages,
            'add_args' => $extra,
            'prev_text' => __('Previous', 'vidyalms'),
            'next_text' => __('Next', 'vidyalms'),
        ]);

        return \is_string($links) ? $links : '';
    }

    /**
     * The catalogue form posts back to the page it is on, so a shortcode on an
     * ordinary page keeps its own URL instead of jumping to /courses.
     */
    private static function catalogueFormAction(): string
    {
        if (Rewrites::currentRoute() === Rewrites::ROUTE_CATALOGUE) {
            return Rewrites::catalogueUrl();
        }

        $queried = get_queried_object();

        if ($queried instanceof WP_Post) {
            $permalink = get_permalink($queried);

            if (\is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return Rewrites::catalogueUrl();
    }

    private static function requestSearch(): string
    {
        return sanitize_text_field((string) get_query_var(Rewrites::QUERY_SEARCH, ''));
    }

    private static function requestCategory(): int
    {
        return max(0, (int) get_query_var(Rewrites::QUERY_CATEGORY, 0));
    }

    private static function requestPage(): int
    {
        $page = (int) get_query_var(Rewrites::QUERY_PAGE, 0);

        if ($page < 1) {
            $page = (int) get_query_var('paged', 0);
        }

        if ($page < 1) {
            $page = (int) get_query_var('page', 0);
        }

        return max(1, $page);
    }

    /**
     * Unslashed scalar request input. An array or an object is not an answer to
     * any question this class asks, so it reads as absent.
     */
    private static function postValue(string $key): string
    {
        // Only reads a form field; the self-enrol handler verifies its nonce before calling the core.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!isset($_POST[$key]) || !\is_scalar($_POST[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies the nonce before acting on this.
        return sanitize_text_field((string) wp_unslash($_POST[$key]));
    }

    private static function getValue(string $key): string
    {
        // Read-only view selection; no state change. Access remains checked by the authenticated core route.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET[$key]) || !\is_scalar($_GET[$key])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display only flag, no state changes with it.
        return sanitize_text_field((string) wp_unslash($_GET[$key]));
    }

    /**
     * @param mixed $value
     */
    private static function isTruthy($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return !\in_array($value, ['0', 'no', 'false', 'off', ''], true);
    }

    private static function isVidyaLmsView(): bool
    {
        return Rewrites::currentRoute() !== ''
            || is_singular(CourseCpt::POST_TYPE)
            || is_post_type_archive(CourseCpt::POST_TYPE);
    }

    private static function enqueueNavigation(): void
    {
        $file = self::pluginDir() . 'assets/app/learner-navigation.js';
        if (!is_file($file) || wp_script_is('vidyalms-learner-navigation', 'enqueued')) {
            return;
        }
        // SPA navigation may open a React lesson from a server-rendered catalogue page.
        wp_enqueue_script('vidyalms-learner-navigation', self::pluginUrl() . 'assets/app/learner-navigation.js', ['react', 'react-dom', 'react-jsx-runtime'],
            substr(hash_file('sha256', $file), 0, 12), ['in_footer' => true, 'strategy' => 'defer']);
        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle !== 'vidyalms-learner-navigation') {
                return $tag;
            }
            $html = new \WP_HTML_Tag_Processor($tag);
            if ($html->next_tag(['tag_name' => 'script'])) {
                $html->set_attribute('type', 'module');
            }
            return $html->get_updated_html();
        }, 10, 2);
    }

    private static function registerStyle(): void
    {
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            return;
        }

        wp_register_style(
            self::STYLE_HANDLE . '-structure',
            self::pluginUrl() . 'assets/css/vidyalms.css',
            [],
            self::assetVersion(),
        );
        wp_register_style(
            self::STYLE_HANDLE,
            self::pluginUrl() . 'assets/app/public-theme.css',
            [self::STYLE_HANDLE . '-structure'],
            is_file(self::pluginDir() . 'assets/app/public-theme.css')
                ? substr(hash_file('sha256', self::pluginDir() . 'assets/app/public-theme.css'), 0, 12)
                : self::assetVersion(),
        );
    }

    private static function assetVersion(): string
    {
        $file = self::pluginDir() . 'assets/css/vidyalms.css';

        if (is_file($file)) {
            return substr(hash_file('sha256', $file), 0, 12);
        }

        if (\defined('VIDYALMS_VERSION')) {
            return (string) \constant('VIDYALMS_VERSION');
        }

        return '1.0.0';
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
