<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Instructor;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;
use VidyaLMS\Component\VidyaLMS\Site\Model\InstructorModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;

/**
 * The instructor page on the front end: the same workbench bundle the
 * administrator mounts, on a site page, so a person who teaches never needs a
 * Joomla administrator login to build a course.
 *
 * The page decides two things and no more. A guest is sent to the login form
 * with a return URL, because a mount that carries a guest's session token can
 * answer nothing. Everyone else gets the mount, and the app asks the API: a
 * learner's course list comes back 403 and the app renders the permission
 * state that was designed for it. There is no capability check here, and no
 * edition check anywhere: who may teach is the core's answer to a route, and a
 * second copy of that rule in this view could only ever be the wrong copy.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    /** The data-vy-config payload, not yet HTML-escaped. */
    public string $configJson = '';

    /** The mount div id, shared with the administrator workbench page. */
    public string $mountId = MountConfig::INSTRUCTOR_MOUNT;

    public Registry $params;

    public string $myLearningUrl = '';

    public function display($tpl = null): void
    {
        $app      = Factory::getApplication();
        $identity = $app->getIdentity();

        if ($identity === null || $identity->guest) {
            $app->enqueueMessage(Text::_('COM_VIDYALMS_INSTRUCTOR_LOGIN_REQUIRED'), 'notice');
            $app->redirect($this->buildLoginUrl());

            return;
        }

        /** @var InstructorModel $model */
        $model    = $this->getModel();
        $courseId = $app->getInput()->getInt('id', 0);

        $this->params        = $app->getParams();
        $this->configJson    = MountConfig::encodeConfig(
            MountConfig::forInstructor($courseId > 0 ? $courseId : null, $model->getCourseSlug($courseId)),
        );
        $this->myLearningUrl = Route::_('index.php?option=com_vidyalms&view=mylearning');

        // The config carries this visitor's own session form token, so the
        // page must never be served from a cache to the next visitor.
        MountConfig::forbidPageCache();

        MountAssets::attach(
            $this->getDocument()->getWebAssetManager(),
            MountAssetContext::site(MountAssets::MOUNT_INSTRUCTOR),
        );

        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_INSTRUCTOR_TITLE'));

        // A working surface has nothing to offer a crawler.
        $this->getDocument()->setMetaData('robots', 'noindex, follow');

        parent::display($tpl);
    }

    private function buildLoginUrl(): string
    {
        $current = Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']);

        return Route::_('index.php?option=com_users&view=login&return=' . base64_encode($current), false);
    }
}
