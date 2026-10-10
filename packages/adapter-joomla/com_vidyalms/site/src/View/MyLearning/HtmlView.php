<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\MyLearning;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use VidyaLMS\Component\VidyaLMS\Site\Model\MyLearningModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\SignInGate;

/**
 * The learner dashboard. A guest is shown the sign-in gate, so the dashboard
 * itself only ever renders for a signed-in learner.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    /** @var list<array<string, mixed>> */
    public array $enrolments = [];

    /** @var list<\Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate> */
    public array $certificates = [];

    /** @var array<int, array{title: string, slug: string}> */
    public array $courseIndex = [];

    public Registry $params;

    public string $catalogueUrl = '';

    public \Joomla\CMS\Pagination\Pagination $pagination;

    public bool $enrolmentsAvailable = true;

    public int $enrolmentTotal = 0;

    public int $enrolmentPage = 1;

    public ?int $organisationFilter = null;

    /** @var list<array{id: int, name: string}> */
    public array $organisationOptions = [];

    /** @var array<string, mixed> */
    public array $signIn = [];

    public function display($tpl = null): void
    {
        \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forbidPageCache();
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        if ($user === null || $user->guest) {
            // A guest gets the sign-in gate inside the Learning space: never cached, no model, no token.
            $app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
            $this->params = \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::pageParameters(
                $app->getParams(),
                'mylearning',
            );
            $heading = (int) $this->params->get('show_page_heading', 1) === 1
                ? ((string) $this->params->get('page_heading', $this->params->get('page_title', ''))
                    ?: Text::_('COM_VIDYALMS_MYLEARNING_TITLE'))
                : '';
            $this->signIn = SignInGate::context('mylearning', $heading, Text::_('COM_VIDYALMS_LEARNING_EYEBROW'));
            $title = (string) $this->params->get('page_title', '');
            $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_MYLEARNING_TITLE'));
            $this->getDocument()->setMetaData('robots', 'noindex, follow');
            $this->setLayout('signin');
            parent::display($tpl);

            return;
        }

        /** @var MyLearningModel $model */
        $model = $this->getModel();

        $this->params       = \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::pageParameters($app->getParams(), 'mylearning');
        $this->enrolments   = $model->getEnrolments();
        $this->organisationFilter = $model->getOrganisationFilter();
        $this->organisationOptions = $model->getOrganisationOptions();
        $this->enrolmentsAvailable = $model->getEnrolmentsAvailable();
        $this->enrolmentTotal = $model->getEnrolmentPage()['total'];
        $this->enrolmentPage = $model->getEnrolmentPage()['page'];
        $this->pagination = $model->getPagination();
        $this->certificates = $model->getCertificates();
        $this->courseIndex  = $model->getCourseIndex();
        $this->catalogueUrl = Route::_('index.php?option=com_vidyalms&view=catalogue');

        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_MYLEARNING_TITLE'));

        // A personal dashboard has nothing to offer a crawler.
        $this->getDocument()->setMetaData('robots', 'noindex, follow');
        $app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);

        parent::display($tpl);
    }
}
