<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Event\PageCache\IsExcludedEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiPassthrough;

/**
 * Builds the data-vy-config JSON of the learner apps (the player and the quiz
 * runner). The shape is the fixed mount contract both hosts share; the
 * WordPress adapter emits the identical structure. Everything the apps know
 * about the host arrives through this object, nothing else.
 *
 * The api block uses the "query" URL mode: the browser session does not
 * authenticate against the Joomla API application (that application only
 * accepts API tokens), so the apps call the site passthrough task api.route,
 * which serves the same core kernel from the site session. The base therefore
 * ends in "&path=" and the SDK appends the URL-encoded contract path.
 *
 * @since 1.0.0
 */
final class MountConfig
{
    /**
     * The header the API passthrough requires on every request, named once by
     * the passthrough itself so the two cannot disagree.
     */
    public const CSRF_HEADER = ApiPassthrough::CSRF_HEADER;

    /**
     * The div the workbench bundle mounts on. One id for both pages that
     * render it, because it is one bundle.
     */
    public const INSTRUCTOR_MOUNT = AdminMountConfig::WORKBENCH_MOUNT;

    /**
     * Placeholders that survive the router and urlencode(), swapped for the
     * mount contract's brace tokens once the URL is built.
     */
    private const COURSE_TOKEN = 'vycoursetoken';

    private const SLUG_TOKEN = 'vyslugtoken';

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed> the mount contract, ready for json_encode
     */
    public static function forCourse(Course $course, ?int $lessonId): array
    {
        $app      = Factory::getApplication();
        $identity = $app->getIdentity();
        $slug     = $course->slug->value;

        return [
            'contract' => AdminMountConfig::CONTRACT,
            'api' => [
                'base'    => self::apiBase(),
                'mode'    => 'query',
                'headers' => [self::CSRF_HEADER => Session::getFormToken()],
            ],
            'course' => [
                'id'   => $course->id,
                'slug' => $slug,
            ],
            'lessonId' => $lessonId !== null && $lessonId > 0 ? $lessonId : null,
            'user' => [
                'id'   => (int) ($identity->id ?? 0),
                'name' => (string) ($identity->name ?? ''),
            ],
            'locale' => $app->getLanguage()->getTag(),
            'messages' => AdminMountConfig::messages(),
            'idleTimeoutSeconds' => (int) \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::configuration()->get('learning_idle_timeout_seconds', 300),
            'urls' => [
                'course'     => Route::_('index.php?option=com_vidyalms&view=course&slug=' . urlencode($slug), false),
                'myLearning' => Route::_('index.php?option=com_vidyalms&view=mylearning', false),
                'upload' => Uri::base(true) . '/index.php?option=com_vidyalms&task=api.upload',
                'player'     => Route::_('index.php?option=com_vidyalms&view=player&slug=' . urlencode($slug)
                    . ($lessonId !== null ? '&vy-lesson=' . $lessonId : ''), false),
            ],
        ];
    }

    /**
     * Marks the current response uncacheable; every view rendering a mount
     * must call this. The config embeds the visitor's own session form token,
     * and the player is guest-reachable on free-preview courses, so with the
     * System - Page Cache plugin enabled a cached guest page would replay the
     * first visitor's token to everyone after them. allowCache(false) only
     * controls the browser Cache-Control header (the page cache plugin never
     * consults it), so the onPageCacheIsExcluded listener is what actually
     * keeps the page out of the server-side page cache.
     */
    public static function forbidPageCache(): void
    {
        $app = Factory::getApplication();

        $app->allowCache(false);
        $app->getDispatcher()->addListener(
            'onPageCacheIsExcluded',
            static function (IsExcludedEvent $event): void {
                $event->addResult(true);
            },
        );
    }

    /**
     * The instructor workbench's contract, for the site page that mounts it.
     *
     * The shape is the one AdminMountConfig::workbench() writes, key for key,
     * because it is the same bundle reading it. Only the URLs differ: they
     * point at site routes, so a person who teaches never needs a Joomla
     * administrator login to reach the workbench.
     *
     * A null course id renders the caller's own course list, which is what
     * "view=instructor" without an id means. The slug is empty when the caller
     * may not read the course; the app asks the API anyway and renders the
     * refusal the core gives it, exactly as the administrator page does.
     *
     * Nothing here asks which edition the site runs. The page is free, the
     * workbench is free, and who may teach is the core's answer to a route,
     * never a check in this adapter.
     *
     * @return array<string, mixed> ready for json_encode
     */
    public static function forInstructor(?int $courseId, string $slug = ''): array
    {
        // The contract, user and locale half is the administrator page's own
        // answer, asked rather than restated: a key added to the workbench
        // contract has to reach both surfaces or the front end mounts a bundle
        // missing it.
        $shared = AdminMountConfig::shared(
            self::apiBase(),
            'query',
            [self::CSRF_HEADER => Session::getFormToken()],
        );

        return AdminMountConfig::extendedFor(AdminMountConfig::WORKBENCH_MOUNT, $shared + [
            'course' => $courseId === null || $courseId <= 0
                ? null
                : ['id' => $courseId, 'slug' => $slug],
            'uploadPurposes' => AdminMountConfig::uploadPurposes(),
            'routes' => AdminMountConfig::servedRoutes(),
            'urls' => [
                'courses'   => self::instructorUrl(),
                'emailTemplates' => AdminMountConfig::emailTemplatesUrl(),
                ...(Factory::getApplication()->getIdentity()?->authorise('core.manage', 'com_vidyalms') === true
                    ? ['certificateTemplates' => AdminMountConfig::certificateTemplatesUrl()] : []),
                'workbench' => self::instructorTemplate(),
                'preview'   => self::previewTemplate(),
                'upload'    => Uri::base(true) . '/index.php?option=com_vidyalms&task=api.upload',
            ],
        ]);
    }

    /**
     * The instructor page itself, which is the course list.
     *
     * Routed with xhtml escaping off, here and in the two templates below.
     * Route::_ defaults to true, which turns every "&" into "&amp;", and these
     * URLs go into a JSON attribute rather than into markup: an app that
     * followed "?id=5&amp;Itemid=101" would be asking for a parameter called
     * "amp;Itemid". The templates are the case that showed it, because they
     * are the only URLs here that carry two parameters.
     */
    public static function instructorUrl(): string
    {
        return Route::_('index.php?option=com_vidyalms&view=instructor', false);
    }

    /**
     * The config JSON exactly as the mount attribute wants it. Slashes stay
     * unescaped so the URLs read naturally; the caller HTML-escapes the
     * result for the attribute context.
     */
    public static function encode(Course $course, ?int $lessonId): string
    {
        return (string) json_encode(
            self::forCourse($course, $lessonId),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * An already assembled config as the mount attribute wants it, for the
     * pages whose config is not built from a Course.
     *
     * @param array<string, mixed> $config
     */
    public static function encodeConfig(array $config): string
    {
        return AdminMountConfig::encode($config);
    }

    /**
     * The workbench URL of any course, as a template the app fills.
     *
     * The token survives routing and urlencode() because it is plain
     * lowercase letters; a literal "{course_id}" would come back
     * percent-encoded from the router and the app's fillUrlTemplate would
     * never match it. The same trick the WordPress adapter uses, for the same
     * reason.
     */
    private static function instructorTemplate(): string
    {
        return str_replace(
            self::COURSE_TOKEN,
            '{course_id}',
            Route::_('index.php?option=com_vidyalms&view=instructor&id=' . self::COURSE_TOKEN, false),
        );
    }

    /**
     * Preview as learner opens the player, a site page like this one.
     */
    private static function previewTemplate(): string
    {
        return str_replace(
            self::SLUG_TOKEN,
            '{slug}',
            Route::_('index.php?option=com_vidyalms&view=player&slug=' . self::SLUG_TOKEN, false),
        );
    }

    /**
     * The passthrough base, deliberately a plain index.php URL: the task is an
     * API endpoint, not a page, so it must not depend on SEF routing or on a
     * menu item existing. Ends in "&path=" per the query URL mode.
     */
    private static function apiBase(): string
    {
        return Uri::base(true) . '/index.php?option=com_vidyalms&task=api.route&path=';
    }
}
