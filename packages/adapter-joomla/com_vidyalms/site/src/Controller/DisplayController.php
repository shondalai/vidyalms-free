<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\MVC\View\ViewInterface;
use Joomla\CMS\Router\Route;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use VidyaLMS\Component\VidyaLMS\Site\Model\CourseModel;

/**
 * The server-rendered surface: catalogue, course page, learner dashboard and
 * certificate verification, plus the self-enrolment post.
 *
 * @since 1.0.0
 */
class DisplayController extends BaseController
{
    /**
     * Joomla capitalises a view name with ucfirst before it looks for the
     * class, which would miss View\MyLearning and Model\MyLearningModel on a
     * case-sensitive filesystem. The canonical spelling is restored here.
     *
     * @var array<string, string>
     */
    private const VIEW_NAMES = ['mylearning' => 'myLearning', 'aiactivity' => 'AiActivity'];

    /** @var string */
    protected $default_view = 'catalogue';

    public function display($cachable = false, $urlparams = [])
    {
        $view = strtolower((string) $this->input->getCmd('view', $this->default_view));

        if (isset(self::VIEW_NAMES[$view])) {
            $this->input->set('view', self::VIEW_NAMES[$view]);
        }

        $safeUrlParams = [
            'option'     => 'CMD',
            'view'       => 'CMD',
            'layout'     => 'CMD',
            'slug'       => 'STRING',
            'code'       => 'STRING',
            // The signed unsubscribe token. It is a URL parameter and never a
            // cache key that matters, because the page refuses to be cached at
            // all: it is keyed to one person.
            'token'      => 'STRING',
            // The course the instructor page opens; the page with no id is
            // the caller's own course list.
            'id'         => 'INT',
            'q'          => 'STRING',
            'category'   => 'INT',
            'limitstart' => 'UINT',
            'Itemid'     => 'INT',
            'lang'       => 'CMD',
            'vy-lesson'  => 'INT',
            'vy-quiz'    => 'INT',
        ];

        return parent::display($cachable, $safeUrlParams);
    }

    /**
     * Self-enrolment in a free, open course. The rule itself lives in the
     * SelfEnrol use case; this only carries the answer back to the page.
     *
     * @return void
     */
    public function enrol()
    {
        $this->checkToken();

        $courseId = (int) $this->input->post->get('id', 0, 'int');
        $slug     = trim((string) $this->input->post->get('slug', '', 'string'));
        $itemId   = (int) $this->input->post->get('Itemid', 0, 'int');
        $return   = $this->courseUrl($slug, $itemId);

        $user = $this->app->getIdentity();

        if ($user === null || $user->guest) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_LOGIN_REQUIRED'), 'notice');
            $this->setRedirect($this->loginUrl($return));

            return;
        }

        if ($courseId <= 0 || $slug === '') {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_FAILED'), 'error');
            $this->setRedirect($return);

            return;
        }

        /** @var CourseModel $model */
        $model = $this->getModel('Course', 'Site');

        try {
            $model->enrol($courseId);
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_SUCCESS'), 'message');
        } catch (ConflictException) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_NOT_AVAILABLE'), 'warning');
        } catch (NotAuthorizedException) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_LOGIN_REQUIRED'), 'notice');
        } catch (EntityNotFoundException) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ERROR_COURSE_NOT_FOUND'), 'error');
        } catch (DomainException) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_ENROL_FAILED'), 'error');
        }

        $this->setRedirect($return);
    }

    /**
     * @param   ViewInterface  $view  The view to feed
     *
     * @return  void
     */
    protected function prepareViewModel(ViewInterface $view)
    {
        if (!method_exists($view, 'setModel')) {
            return;
        }

        $name  = $view->getName();
        if (\in_array(strtolower($name), ['organisations', 'achievements', 'aiactivity'], true)) {
            return; // These SPA landings have no server-side model or token input.
        }
        $name  = self::VIEW_NAMES[$name] ?? $name;
        $model = $this->getModel($name, 'Site', ['base_path' => $this->basePath]);

        if ($model) {
            $view->setModel($model, true);
        }
    }

    private function courseUrl(string $slug, int $itemId): string
    {
        if ($slug === '') {
            return Route::_('index.php?option=com_vidyalms&view=catalogue', false);
        }

        $url = 'index.php?option=com_vidyalms&view=course&slug=' . urlencode($slug);

        if ($itemId > 0) {
            $url .= '&Itemid=' . $itemId;
        }

        return Route::_($url, false);
    }

    private function loginUrl(string $return): string
    {
        return Route::_('index.php?option=com_users&view=login&return=' . base64_encode($return), false);
    }
}
