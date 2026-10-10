<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Pagination\Pagination;
use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCatalogue;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CoursePage;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;

/**
 * The public catalogue page: one call to the GetCatalogue use case, wrapped in
 * Joomla pagination.
 *
 * @since 1.0.0
 */
final class CatalogueModel extends KernelModel
{
    private const DEFAULT_PER_PAGE = 12;

    private ?CoursePage $page = null;

    private ?Pagination $pagination = null;

    protected function populateState(): void
    {
        $app    = Factory::getApplication();
        $input  = $app->getInput();
        $params = $app->getParams();

        $globalPerPage = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::configuration()
            ->get('catalogue_per_page', self::DEFAULT_PER_PAGE);
        $menuParams = $app instanceof SiteApplication ? $app->getMenu()->getActive()?->getParams() : null;
        $perPage = (int) ($menuParams?->get('catalogue_per_page', $globalPerPage) ?? $globalPerPage);

        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $this->setState('list.limit', min($perPage, GetCatalogue::MAX_PER_PAGE));
        $this->setState('list.start', max(0, (int) $input->get('limitstart', 0, 'uint')));
        $this->setState('filter.q', trim((string) $input->get('q', '', 'string')));
        $this->setState(
            'filter.category',
            max(0, (int) $input->get('category', (int) $params->get('catalogue_category', 0), 'int')),
        );
    }

    public function getPage(): CoursePage
    {
        if ($this->page instanceof CoursePage) {
            return $this->page;
        }

        $perPage  = (int) $this->getState('list.limit', self::DEFAULT_PER_PAGE);
        $start    = (int) $this->getState('list.start', 0);
        $search   = (string) $this->getState('filter.q', '');
        $category = (int) $this->getState('filter.category', 0);

        return $this->page = $this->kernel()->getCatalogue->execute(
            $this->principal(),
            $search === '' ? null : $search,
            $category > 0 ? $category : null,
            (int) floor($start / max(1, $perPage)) + 1,
            $perPage,
        );
    }

    public function getPagination(): Pagination
    {
        if ($this->pagination instanceof Pagination) {
            return $this->pagination;
        }

        $page       = $this->getPage();
        $pagination = new Pagination($page->total, ($page->page - 1) * $page->perPage, $page->perPage);

        $pagination->setAdditionalUrlParam('option', 'com_vidyalms');
        $pagination->setAdditionalUrlParam('view', 'catalogue');

        $itemId = (int) Factory::getApplication()->getInput()->get('Itemid', 0, 'int');

        if ($itemId > 0) {
            $pagination->setAdditionalUrlParam('Itemid', $itemId);
        }

        $search = (string) $this->getState('filter.q', '');

        if ($search !== '') {
            $pagination->setAdditionalUrlParam('q', $search);
        }

        $category = (int) $this->getState('filter.category', 0);

        if ($category > 0) {
            $pagination->setAdditionalUrlParam('category', $category);
        }

        return $this->pagination = $pagination;
    }

    public function getSearch(): string
    {
        return (string) $this->getState('filter.q', '');
    }

    public function getCategory(): int
    {
        return (int) $this->getState('filter.category', 0);
    }

    /**
     * The display price of a paid course, as the commerce source the course
     * names answers it. Null for free courses and whenever no shop is wired
     * up.
     *
     * The source is a trailing optional parameter rather than a new required
     * one, so a template or an override calling the two-argument form still
     * works and gets the site's default source, which is what it got before.
     */
    public function getPrice(int $courseId, string $priceMode, ?string $commerceSource = null): ?CoursePrice
    {
        $sources = $this->kernel()->registries->commerceSources;

        if ($priceMode === 'free') {
            return null;
        }

        $key = CommercePrices::resolveSourceKey($sources, $commerceSource);

        return $key === null ? null : $sources->get($key)->priceFor(['id' => $courseId]);
    }
}
