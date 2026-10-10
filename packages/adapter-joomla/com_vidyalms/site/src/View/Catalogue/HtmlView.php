<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Catalogue;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Pagination\Pagination;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CoursePage;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use VidyaLMS\Component\VidyaLMS\Site\Model\CatalogueModel;

/**
 * The public course catalogue, server rendered for search engines.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public CoursePage $page;

    public Pagination $pagination;

    public Registry $params;

    public string $search = '';

    public int $category = 0;

    /** @var array<int, object> */
    public array $categoryOptions = [];

    /** @var array<int, CoursePrice|null> */
    public array $prices = [];

    public string $formAction = '';

    public int $itemId = 0;

    public function display($tpl = null): void
    {
        /** @var CatalogueModel $model */
        $model = $this->getModel();
        $app   = Factory::getApplication();

        $this->params     = \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::pageParameters($app->getParams(), 'catalogue');
        $this->page       = $model->getPage();
        $this->pagination = $model->getPagination();
        $this->search     = $model->getSearch();
        $this->category   = $model->getCategory();
        $this->itemId     = (int) $app->getInput()->get('Itemid', 0, 'int');
        $this->formAction = Route::_('index.php?option=com_vidyalms&view=catalogue');

        $options = HTMLHelper::_('category.options', 'com_vidyalms', ['filter.published' => 1]);

        $this->categoryOptions = \is_array($options) ? $options : [];

        foreach ($this->page->items as $course) {
            $this->prices[$course->id] = $model->getPrice($course->id, $course->priceMode, $course->commerceSource);
        }

        $this->prepareDocument();

        parent::display($tpl);
    }

    private function prepareDocument(): void
    {
        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_CATALOGUE_TITLE'));

        $description = (string) $this->params->get('menu-meta_description', '');

        if ($description !== '') {
            $this->getDocument()->setDescription($description);
        }
    }
}
