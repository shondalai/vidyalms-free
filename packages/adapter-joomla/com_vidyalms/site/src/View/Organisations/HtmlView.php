<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Organisations;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiPassthrough;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;

/** No guest redirect: browser-only invitation fragments never enter a server return URL. */
final class HtmlView extends BaseHtmlView
{
    public string $configJson = '';

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        MountConfig::forbidPageCache();
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $config = AdminMountConfig::shared(
            Uri::base(true) . '/index.php?option=com_vidyalms&task=api.route&path=',
            'query',
            [ApiPassthrough::CSRF_HEADER => Session::getFormToken()],
        ) + [
            'screen' => 'organisations',
            'urls' => [
                'home' => Route::_('index.php?option=com_vidyalms&view=catalogue', false),
                'myLearning' => Route::_('index.php?option=com_vidyalms&view=myLearning', false),
                'course' => str_replace('vidyalms-course-placeholder', '__VIDYALMS_SLUG__', Route::_('index.php?option=com_vidyalms&view=course&slug=vidyalms-course-placeholder', false)),
                'player' => str_replace('vidyalms-course-placeholder', '__VIDYALMS_SLUG__', Route::_('index.php?option=com_vidyalms&view=player&slug=vidyalms-course-placeholder', false)),
                'login' => Route::_('index.php?option=com_users&view=login&return='
                    . base64_encode(KernelFactory::organisationInvitationUrl()), false),
            ],
        ];
        $this->configJson = AdminMountConfig::encode($config);
        $this->setDocumentTitle(Text::_('COM_VIDYALMS_ORGANISATIONS_TITLE'));
        MountAssets::attach($this->getDocument()->getWebAssetManager(), MountAssetContext::site('credentials'));
        parent::display($tpl);
    }
}
