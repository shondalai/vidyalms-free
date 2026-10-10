<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Player;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;
use VidyaLMS\Component\VidyaLMS\Site\Model\PlayerModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;

/**
 * The learner player page: one mount div carrying the data-vy-config contract,
 * plus the player bundle loaded as an ES module. The React app owns everything
 * inside the mount; this page owns login, the config and the assets.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public Course $course;

    /** The data-vy-config payload, not yet HTML-escaped. */
    public string $configJson = '';

    public function display($tpl = null): void
    {
        /** @var PlayerModel $model */
        $model  = $this->getModel();
        $course = $model->getCourse();

        if ($course === null) {
            throw new \Exception(Text::_('COM_VIDYALMS_ERROR_COURSE_NOT_FOUND'), 404);
        }

        $app = Factory::getApplication();

        // The player is a signed-in surface unless the course previews for
        // free; the return URL brings the learner straight back here.
        $identity = $app->getIdentity();

        if (($identity === null || $identity->guest) && !$model->hasFreePreviewContent()) {
            $current = Uri::getInstance()->toString(['path', 'query']);

            $app->enqueueMessage(Text::_('COM_VIDYALMS_PLAYER_LOGIN_REQUIRED'), 'notice');
            $app->redirect(
                Route::_('index.php?option=com_users&view=login&return=' . base64_encode($current), false),
            );

            return;
        }

        $this->course     = $course;
        $this->configJson = MountConfig::encode($course, $model->getLessonId());

        // The config carries the visitor's own CSRF token and this page is
        // guest-reachable on free preview, so it must never be page-cached.
        MountConfig::forbidPageCache();

        $this->attachAssets('player');
        $this->setDocumentTitle($course->title);

        parent::display($tpl);
    }

    /**
     * Loads one app bundle, and anything another extension contributed to the
     * same mount, through Service\MountAssets. See that class for the seam a
     * Pro package or a third-party renderer reaches this page through.
     */
    private function attachAssets(string $app): void
    {
        MountAssets::attach(
            $this->getDocument()->getWebAssetManager(),
            MountAssetContext::site($app),
        );
    }
}
