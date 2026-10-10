<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\AiActivity;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiPassthrough;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;
use VidyaLMS\Component\VidyaLMS\Site\Service\SignInGate;

/**
 * The learner's own AI activity, which is the Article 15 answer this product
 * offers as a screen rather than as a paragraph.
 *
 * It mounts the credential bundle under the screen key `ai-activity` rather
 * than starting a fourth entry: the audience, the session and the asset are the
 * same as the holder's own credentials page, and a new bundle would be a new
 * asset on both hosts for one screen.
 *
 * A guest is shown the sign-in gate, because the route it reads answers for
 * one signed-in person and for nobody else. The page is personal, so it is
 * never cached and never indexed.
 *
 * @since 1.26.0
 */
final class HtmlView extends BaseHtmlView
{
    public string $configJson = '';

    /** @var array<string, mixed> */
    public array $signIn = [];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        MountConfig::forbidPageCache();
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $user = $app->getIdentity();

        if ($user === null || $user->guest) {
            // A guest gets the sign-in gate: no app config, no form token, no mount assets.
            $this->signIn = SignInGate::context('aiactivity', Text::_('COM_VIDYALMS_AI_ACTIVITY_TITLE'));
            $this->setDocumentTitle(Text::_('COM_VIDYALMS_AI_ACTIVITY_TITLE'));
            $this->setLayout('signin');
            parent::display($tpl);

            return;
        }

        $config = AdminMountConfig::shared(
            Uri::base(true) . '/index.php?option=com_vidyalms&task=api.route&path=',
            'query',
            [ApiPassthrough::CSRF_HEADER => Session::getFormToken()],
        ) + [
            'screen' => 'ai-activity',
            'routes' => AdminMountConfig::servedRoutes(),
            'urls' => [
                'home' => Route::_('index.php?option=com_vidyalms&view=catalogue', false),
                'myLearning' => Route::_('index.php?option=com_vidyalms&view=myLearning', false),
                'preferences' => Route::_('index.php?option=com_vidyalms&view=preferences', false),
            ],
        ];
        $this->configJson = AdminMountConfig::encode($config);
        $this->setDocumentTitle(Text::_('COM_VIDYALMS_AI_ACTIVITY_TITLE'));
        MountAssets::attach($this->getDocument()->getWebAssetManager(), MountAssetContext::site('credentials'));
        parent::display($tpl);
    }
}
