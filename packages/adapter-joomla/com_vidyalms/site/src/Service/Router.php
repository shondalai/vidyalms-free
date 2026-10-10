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

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\Router\RouterView;
use Joomla\CMS\Component\Router\RouterViewConfiguration;
use Joomla\CMS\Component\Router\Rules\MenuRules;
use Joomla\CMS\Component\Router\Rules\NomenuRules;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Menu\AbstractMenu;

/**
 * SEF router for the server-rendered surface.
 *
 * The menu items carry the public shape: a catalogue item aliased "courses"
 * gives /courses and /courses/{slug}, a my-learning item gives /my-learning and
 * a verification item aliased "certificate" gives /certificate/{code}.
 *
 * The course view is keyed by slug rather than by id: slugs are the core's own
 * identifier for a course page (GetCourseBySlug), so no id lookup and no cache
 * entry is needed to build or parse a course URL.
 *
 * @since 1.0.0
 */
final class Router extends RouterView
{
    public const VIEW_CATALOGUE = 'catalogue';
    public const VIEW_COURSE = 'course';
    public const VIEW_MY_LEARNING = 'mylearning';
    public const VIEW_PLAYER = 'player';
    public const VIEW_VERIFY = 'verify';
    public const VIEW_INSTRUCTOR = 'instructor';
    public const VIEW_UNSUBSCRIBE = 'unsubscribe';
    public const VIEW_PREFERENCES = 'preferences';

    private const OPTION = 'com_vidyalms';

    public function __construct(?SiteApplication $app = null, ?AbstractMenu $menu = null)
    {
        $catalogue = new RouterViewConfiguration(self::VIEW_CATALOGUE);
        $this->registerView($catalogue);

        $course = new RouterViewConfiguration(self::VIEW_COURSE);
        $course->setKey('slug')->setParent($catalogue);
        $this->registerView($course);

        // The learner player nests under its course: /courses/{slug}/player.
        // The view has no key of its own; the lesson deep link (vy-lesson)
        // and the quiz handoff (vy-quiz) stay ordinary query parameters.
        $player = new RouterViewConfiguration(self::VIEW_PLAYER);
        // Both views use the same slug. A parent-key alias makes StandardRules
        // assign slug to itself and then unset it while parsing /player.
        $player->setParent($course);
        $this->registerView($player);

        $this->registerView(new RouterViewConfiguration(self::VIEW_MY_LEARNING));

        // The instructor page keys on nothing: the page with no course is the
        // caller's course list, and the course it opens rides as the "id"
        // query parameter. A key segment here would have to be a number, and
        // NomenuRules reads a key segment as core's {id}-{alias} pair, which
        // is the trap the slug views already had to be defended from.
        $this->registerView(new RouterViewConfiguration(self::VIEW_INSTRUCTOR));

        $verify = new RouterViewConfiguration(self::VIEW_VERIFY);
        $verify->setKey('code');
        $this->registerView($verify);

        // The unsubscribe page keys on the signed token, so one menu item
        // serves every recipient and the URI printed in the List-Unsubscribe
        // header is a path rather than a query string. The same URL answers
        // the human GET and the RFC 8058 one-click POST, which is what the
        // header pair requires: a receiver has one URI and uses it both ways.
        $unsubscribe = new RouterViewConfiguration(self::VIEW_UNSUBSCRIBE);
        $unsubscribe->setKey('token');
        $this->registerView($unsubscribe);

        // The preference centre keys on nothing: /me/notification-preferences
        // takes no user id and answers for whoever is signed in.
        $this->registerView(new RouterViewConfiguration(self::VIEW_PREFERENCES));

        parent::__construct($app, $menu);

        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
        $this->attachRule(new NomenuRules($this));
    }

    /**
     * The course key is already the segment, so building one is a pass-through.
     *
     * @param   string  $id     Slug of the course
     * @param   array   $query  The query being built
     *
     * @return  array
     */
    public function getCourseSegment($id, $query)
    {
        $slug = (string) $id;

        return [$slug => $slug];
    }

    /**
     * @param   string  $segment  Segment to parse
     * @param   array   $query    The query so far
     *
     * @return  string
     */
    public function getCourseId($segment, $query)
    {
        return (string) $segment;
    }

    /**
     * @param   string  $id     Verification code
     * @param   array   $query  The query being built
     *
     * @return  array
     */
    public function getVerifySegment($id, $query)
    {
        $code = (string) $id;

        return [$code => $code];
    }

    /**
     * @param   string  $segment  Segment to parse
     * @param   array   $query    The query so far
     *
     * @return  string
     */
    public function getVerifyId($segment, $query)
    {
        return (string) $segment;
    }

    /**
     * @param   string  $id     Unsubscribe token
     * @param   array   $query  The query being built
     *
     * @return  array
     */
    public function getUnsubscribeSegment($id, $query)
    {
        $token = (string) $id;

        return [$token => $token];
    }

    /**
     * @param   string  $segment  Segment to parse
     * @param   array   $query    The query so far
     *
     * @return  string
     */
    public function getUnsubscribeId($segment, $query)
    {
        return (string) $segment;
    }

    /**
     * Anchors course and verification links on the menu item that owns them.
     * The standard menu rules key their lookup on integers, so a slug-keyed and
     * a code-keyed view never match and would otherwise fall back to the active
     * page.
     *
     * @param   array  $query  The query to preprocess
     *
     * @return  array
     */
    public function preprocess($query)
    {
        $query = parent::preprocess($query);

        $view     = (string) ($query['view'] ?? '');
        $language = (string) ($query['lang'] ?? '');

        if ($view === self::VIEW_COURSE || $view === self::VIEW_PLAYER) {
            $itemId = $this->menuItemId(self::VIEW_COURSE, $language, (string) ($query['slug'] ?? ''))
                ?: $this->menuItemId(self::VIEW_CATALOGUE, $language);

            if ($itemId > 0) {
                $query['Itemid'] = $itemId;
            }
        }

        if ($view === self::VIEW_VERIFY || $view === self::VIEW_UNSUBSCRIBE) {
            $itemId = $this->menuItemId($view, $language);

            if ($itemId > 0) {
                $query['Itemid'] = $itemId;
            }
        }

        return $query;
    }

    /**
     * @param   array  $query  Query to convert into segments
     *
     * @return  array
     */
    public function build(&$query)
    {
        // The standard rules cannot append a segment to the very view a menu
        // item points at, and two pages here are exactly that: one menu item
        // serving every verification code, and one serving every unsubscribe
        // token.
        foreach ([self::VIEW_VERIFY => 'code', self::VIEW_UNSUBSCRIBE => 'token'] as $view => $key) {
            if (
                ($query['view'] ?? '') === $view
                && ($query[$key] ?? '') !== ''
                && $this->isItemFor((int) ($query['Itemid'] ?? 0), $view)
            ) {
                $segment = (string) $query[$key];

                unset($query['view'], $query[$key]);

                return [$segment];
            }
        }

        $isNestedPlayer = ($query['view'] ?? '') === self::VIEW_PLAYER
            && ($this->isItemFor((int) ($query['Itemid'] ?? 0), self::VIEW_CATALOGUE)
                || $this->isItemFor((int) ($query['Itemid'] ?? 0), self::VIEW_COURSE));
        $segments = parent::build($query);

        // A keyless child keeps its parent's key in the query. For nested
        // player URLs it is already carried by the course path/menu item.
        // Menu-less player URLs still need the slug query parameter.
        if ($isNestedPlayer) {
            unset($query['slug']);
        }

        return $segments;
    }

    /**
     * @param   array  $segments  Segments to parse
     *
     * @return  array
     */
    public function parse(&$segments)
    {
        $vars = $this->withLiteralKeys(parent::parse($segments));

        if ($segments === []) {
            return $vars;
        }

        $active = $this->menu->getActive();
        $view   = (string) ($vars['view'] ?? (\is_object($active) ? ($active->query['view'] ?? '') : ''));

        if ($view === self::VIEW_VERIFY) {
            $vars['view'] = self::VIEW_VERIFY;
            $vars['code'] = (string) array_shift($segments);
        }

        if ($view === self::VIEW_UNSUBSCRIBE) {
            $vars['view']  = self::VIEW_UNSUBSCRIBE;
            $vars['token'] = (string) array_shift($segments);
        }

        return $vars;
    }

    /**
     * Undoes Joomla's id-alias split on this component's key segments.
     *
     * A site with no menu item for this component routes through NomenuRules,
     * and that rule reads every key segment as core's `{id}-{alias}` pair:
     * it hands the segment to get<View>Id() and then runs
     * preg_replace('/-/', ':', $result, 1) over the answer, so the key an
     * article carries is "42:my-article" and the id is the part before the
     * colon. Both keys here are literal strings rather than pairs, so that
     * turns the first hyphen of every one of them into a colon:
     * /component/vidyalms/course/algebra-101 parsed to slug "algebra:101",
     * which is a course that does not exist, and the player's Start attempt
     * link answered a 404 on every course whose slug has a hyphen in it,
     * which is nearly all of them. Building the same URL is unaffected,
     * because the reverse replacement finds no colon to undo, so build and
     * parse were not inverses and only the parse half was wrong.
     *
     * The other two rules assign the key verbatim, so the colon is only ever
     * there when NomenuRules put it there, and none of the three keys can
     * contain one: a slug and a verification code are validated as lowercase
     * alphanumerics with hyphens by the core that issues them, and an
     * unsubscribe token is URL-safe base64, whose alphabet is letters, digits,
     * hyphen and underscore. Only the first hyphen is ever replaced, so
     * undoing the first colon is the exact inverse.
     *
     * @param   array  $vars  The vars parsed so far
     *
     * @return  array
     */
    private function withLiteralKeys(array $vars): array
    {
        foreach (['slug', 'code', 'token'] as $key) {
            $value = $vars[$key] ?? null;

            if (!\is_string($value)) {
                continue;
            }

            $colon = strpos($value, ':');

            if ($colon !== false) {
                $vars[$key] = substr_replace($value, '-', $colon, 1);
            }
        }

        return $vars;
    }

    /**
     * The id of the first menu item pointing at the given view, optionally for
     * one course slug only. Returns 0 when the site has no such item.
     */
    private function menuItemId(string $view, string $language, ?string $slug = null): int
    {
        $items = $this->menu->getItems('component', self::OPTION);

        if (!\is_array($items)) {
            return 0;
        }

        foreach ($items as $item) {
            if (!isset($item->query['view']) || $item->query['view'] !== $view) {
                continue;
            }

            if ($language !== '' && $item->language !== '*' && $item->language !== $language) {
                continue;
            }

            if ($slug !== null && (string) ($item->query['slug'] ?? '') !== $slug) {
                continue;
            }

            return (int) $item->id;
        }

        return 0;
    }

    private function isItemFor(int $itemId, string $view): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        $item = $this->menu->getItem($itemId);

        return $item !== null && ($item->query['view'] ?? '') === $view;
    }
}
