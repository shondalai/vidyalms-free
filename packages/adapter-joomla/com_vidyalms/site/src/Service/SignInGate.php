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

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/**
 * The signed-out state of the personal learner pages.
 *
 * It invites the learner to the host's own sign-in page (com_users, or the
 * page a login override puts in its place) and never renders, reads or checks
 * a credential, so a guest page carries no form token. The return target
 * comes from a fixed view list, never from the request.
 *
 * @since 1.0.0
 */
final class SignInGate
{
    /** The views that answer a guest with the gate. */
    public const PAGES = ['mylearning', 'achievements', 'preferences', 'aiactivity'];

    /** @return array<string, mixed> */
    public static function context(string $view, string $heading = '', string $pageKicker = ''): array
    {
        $view = \in_array($view, self::PAGES, true) ? $view : 'mylearning';
        $key  = strtoupper($view);
        // The internal URL, never an absolute one: with live_site empty Joomla builds absolute URLs
        // from the request's Host header. com_users accepts this form (Uri::isInternal) and routes it
        // after sign-in, and a login override routes it the same way.
        $return = 'index.php?option=com_vidyalms&view=' . $view;
        $site   = trim((string) Factory::getApplication()->get('sitename', ''));
        $open   = (int) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 0) === 1;

        return [
            'page' => $view,
            'page_kicker' => $pageKicker,
            'heading' => $heading,
            'kicker' => Text::_('COM_VIDYALMS_SIGNIN_KICKER'),
            'title' => Text::_('COM_VIDYALMS_SIGNIN_' . $key . '_TITLE'),
            'lede' => Text::_('COM_VIDYALMS_SIGNIN_' . $key . '_LEDE'),
            'account' => $site !== ''
                ? Text::sprintf('COM_VIDYALMS_SIGNIN_ACCOUNT', $site)
                : Text::_('COM_VIDYALMS_SIGNIN_ACCOUNT_GENERIC'),
            'steps_title' => Text::_('COM_VIDYALMS_SIGNIN_STEPS_TITLE'),
            'steps' => [
                [
                    'name' => Text::_('COM_VIDYALMS_SIGNIN_STEP_1'),
                    'detail' => Text::_('COM_VIDYALMS_SIGNIN_STEP_1_DETAIL'),
                ],
                [
                    'name' => Text::_('COM_VIDYALMS_SIGNIN_STEP_2'),
                    'detail' => Text::_('COM_VIDYALMS_SIGNIN_STEP_2_DETAIL'),
                ],
                [
                    'name' => Text::_('COM_VIDYALMS_SIGNIN_STEP_3'),
                    'detail' => Text::_('COM_VIDYALMS_SIGNIN_STEP_3_DETAIL'),
                ],
            ],
            'sign_in_url' => self::withReturn(Route::_('index.php?option=com_users&view=login', false), $return),
            'register_url' => $open ? Route::_('index.php?option=com_users&view=registration', false) : '',
            'reset_url' => Route::_('index.php?option=com_users&view=reset', false),
            'remind_url' => Route::_('index.php?option=com_users&view=remind', false),
            'catalogue_url' => Route::_('index.php?option=com_vidyalms&view=catalogue', false),
        ];
    }

    /** @param array<string, mixed> $context */
    public static function render(array $context): string
    {
        return LayoutHelper::render('signin.gate', $context, '', ['component' => 'com_vidyalms', 'client' => 0]);
    }

    /**
     * Base64 then urlencoded, appended after routing: a raw "+" would reach
     * com_users as a space and fail its BASE64 filter, "=" padding is kept
     * intact, and the router rebuilds
     * the query of every URL it routes with urldecode(), so an encoded return
     * routed inside the URL would come out raw again.
     */
    private static function withReturn(string $routed, string $return): string
    {
        return $routed . (str_contains($routed, '?') ? '&' : '?') . 'return=' . urlencode(base64_encode($return));
    }
}
