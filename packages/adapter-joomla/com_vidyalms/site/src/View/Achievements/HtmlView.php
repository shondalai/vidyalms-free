<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Achievements;

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
            $this->signIn = SignInGate::context('achievements', Text::_('COM_VIDYALMS_ACHIEVEMENTS_TITLE'));
            $this->setDocumentTitle(Text::_('COM_VIDYALMS_ACHIEVEMENTS_TITLE'));
            $this->setLayout('signin');
            parent::display($tpl);
            return;
        }
        $config = AdminMountConfig::shared(
            Uri::base(true) . '/index.php?option=com_vidyalms&task=api.route&path=',
            'query',
            [ApiPassthrough::CSRF_HEADER => Session::getFormToken()],
        ) + [
            'screen' => 'credentials',
            'routes' => AdminMountConfig::servedRoutes(),
            'urls' => [
                'home' => Route::_('index.php?option=com_vidyalms&view=catalogue', false),
                'myLearning' => Route::_('index.php?option=com_vidyalms&view=myLearning', false),
                'organisations' => Route::_('index.php?option=com_vidyalms&view=organisations', false),
                'preferences' => Route::_('index.php?option=com_vidyalms&view=preferences', false),
            ],
        ];
        $this->configJson = AdminMountConfig::encode($config);
        $this->setDocumentTitle(Text::_('COM_VIDYALMS_ACHIEVEMENTS_TITLE'));
        MountAssets::attach($this->getDocument()->getWebAssetManager(), MountAssetContext::site('credentials'));
        parent::display($tpl);
    }
}
