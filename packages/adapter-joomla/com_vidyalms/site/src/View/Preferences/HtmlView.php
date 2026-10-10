<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Preferences;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRouteResult;
use VidyaLMS\Component\VidyaLMS\Site\Model\PreferencesModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;
use VidyaLMS\Component\VidyaLMS\Site\Service\SignInGate;

/**
 * The learner's preference centre: one row per category and channel, with the
 * site policy behind it.
 *
 * It is a server-rendered page rather than a React mount, for the reason the
 * design gives for the unsubscribe and digest pages: they are ordinary pages
 * under the host template, inheriting its accessibility conformance, and they
 * have to work in a mail client's browser with whatever it allows.
 *
 * Unlike the unsubscribe page this one requires a session, because it is the
 * whole list and not one signed category. A guest is shown the sign-in gate;
 * the core answers 401 to anyone who arrives without a session, so the rule
 * is not decided twice.
 *
 * The page is never cached: it is one person's rows.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public ?CoreRouteResult $centre = null;

    public bool $saved = false;

    public string $saveError = '';

    /** @var list<array{category_key: string, channel_key: string, frequency: string}> */
    public array $submittedRows = [];

    public Registry $params;

    public string $formAction = '';

    public string $csrfToken = '';

    /** @var array<string, mixed> */
    public array $signIn = [];

    public function display($tpl = null): void
    {
        $app      = Factory::getApplication();
        $identity = $app->getIdentity();

        if ($identity === null || $identity->guest) {
            // A guest gets the sign-in gate; the core is never asked for a guest's rows.
            $this->noCache($app);
            $this->params = \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::pageParameters(
                $app->getParams(),
                'preferences',
            );
            $heading = (int) $this->params->get('show_page_heading', 1) === 1
                ? ((string) $this->params->get('page_heading', $this->params->get('page_title', ''))
                    ?: Text::_('COM_VIDYALMS_PREFERENCES_TITLE'))
                : '';
            $this->signIn = SignInGate::context('preferences', $heading, Text::_('COM_VIDYALMS_PREF_UX_KICKER'));
            $this->setDocumentTitle(Text::_('COM_VIDYALMS_PREFERENCES_TITLE'));
            $this->setLayout('signin');
            parent::display($tpl);

            return;
        }

        /** @var PreferencesModel $model */
        $model = $this->getModel();

        $this->params     = clone $app->getParams();
        $menu = $app instanceof SiteApplication ? $app->getMenu()->getActive() : null;
        if (($menu?->query['option'] ?? '') !== 'com_vidyalms' || ($menu?->query['view'] ?? '') !== 'preferences') {
            // Component links can inherit the Home menu item; its title is not
            // the preference page's title. Honor headings on a dedicated menu.
            $this->params->set('page_heading', Text::_('COM_VIDYALMS_PREFERENCES_TITLE'));
            $this->params->set('page_title', Text::_('COM_VIDYALMS_PREFERENCES_TITLE'));
        }
        $this->formAction = Route::_('index.php?option=com_vidyalms&view=preferences');
        $this->csrfToken  = Session::getFormToken();

        $method = strtoupper((string) $app->getInput()->getMethod());

        if ($method === 'POST') {
            $this->submittedRows = $model->postedRows();
            // A session-authenticated write, so this one does carry a token.
            // The unsubscribe page deliberately does not: a mail client has no
            // session to take one from.
            if (Session::checkToken('post') === false) {
                $this->saveError = Text::_('JINVALID_TOKEN_NOTICE');
            } else {
                $outcome = $model->replace($model->postedRows());

                if ($outcome->ok()) {
                    $this->saved = true;
                } else {
                    $this->saveError = $outcome->detail() !== ''
                        ? $outcome->detail()
                        : Text::_('COM_VIDYALMS_PREFERENCES_SAVE_FAILED');
                }
            }
        }

        $this->centre = $model->centre();

        $this->noCache($app);

        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_PREFERENCES_TITLE'));

        parent::display($tpl);
    }

    /**
     * One person's rows, so the same refusal the mount pages use: the browser,
     * any proxy and Joomla's own page cache all told not to keep a copy.
     */
    private function noCache(object $app): void
    {
        MountConfig::forbidPageCache();

        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
    }
}
