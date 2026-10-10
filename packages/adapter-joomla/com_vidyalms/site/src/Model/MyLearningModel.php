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

use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Joomla\CMS\Factory;
use Joomla\CMS\Pagination\Pagination;

/**
 * The learner dashboard: their own enrolments and their own certificates, both
 * straight from the core. Guests never reach it; the view sends them to the
 * login page first.
 *
 * @since 1.0.0
 */
final class MyLearningModel extends KernelModel
{
    /** @var array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}|null */
    private ?array $enrolmentPage = null;

    private bool $enrolmentsAvailable = true;

    /** @var list<Certificate>|null */
    private ?array $certificates = null;

    /**
     * @return list<array<string, mixed>>
     */
    public function getEnrolments(): array
    {
        return $this->getEnrolmentPage()['items'];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function getEnrolmentPage(): array
    {
        if ($this->enrolmentPage !== null) {
            return $this->enrolmentPage;
        }
        $start = (int) Factory::getApplication()->getInput()->get('learning_limitstart', 0, 'uint');
        $page = intdiv(min(19999980, max(0, $start)), 20) + 1;
        $empty = ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => 20];
        if ($this->isGuest()) {
            return $this->enrolmentPage = $empty;
        }
        try {
            $list = $this->kernel()->listMyEnrolments;
            $result = $list->page($this->principal(), $page, 20, null, $this->getOrganisationFilter());
            $lastPage = max(1, (int) ceil($result['total'] / 20));
            // A bookmarked page can disappear when enrolments are removed.
            return $this->enrolmentPage = $page > $lastPage ? $list->page($this->principal(), $lastPage, 20, null, $this->getOrganisationFilter()) : $result;
        } catch (DomainException) {
            $this->enrolmentsAvailable = false;
            return $this->enrolmentPage = $empty;
        }
    }

    public function getEnrolmentsAvailable(): bool
    {
        $this->getEnrolmentPage();
        return $this->enrolmentsAvailable;
    }

    public function getOrganisationFilter(): ?int
    {
        $value = Factory::getApplication()->getInput()->get('organisation_id', '', 'string');
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483646]]);
        return $id === false ? null : $id;
    }

    /** @return list<array{id: int, name: string}> */
    public function getOrganisationOptions(): array
    {
        return $this->isGuest() ? [] : $this->kernel()->listMyEnrolments->organisations($this->principal());
    }

    public function getPagination(): Pagination
    {
        $page = $this->getEnrolmentPage();
        $pagination = new Pagination($page['total'], ($page['page'] - 1) * 20, 20, 'learning_');
        $pagination->setAdditionalUrlParam('option', 'com_vidyalms');
        $pagination->setAdditionalUrlParam('view', 'mylearning');
        if ($this->getOrganisationFilter() !== null) {
            $pagination->setAdditionalUrlParam('organisation_id', $this->getOrganisationFilter());
        }
        $itemId = (int) Factory::getApplication()->getInput()->get('Itemid', 0, 'int');
        if ($itemId > 0) {
            $pagination->setAdditionalUrlParam('Itemid', $itemId);
        }
        return $pagination;
    }

    /**
     * @return list<Certificate>
     */
    public function getCertificates(): array
    {
        if ($this->certificates !== null) {
            return $this->certificates;
        }

        if ($this->isGuest()) {
            return $this->certificates = [];
        }

        try {
            $kernel = $this->kernel();
            return $this->certificates = $kernel->listMyCertificates->page(
                $this->principal(),
                $kernel->ports()->clock->now(),
                1,
                6,
            )['items'];
        } catch (DomainException) {
            return $this->certificates = [];
        }
    }

    /**
     * Course titles and slugs keyed by course id, taken from the enrolment
     * rows, so a certificate can be labelled without a second lookup.
     *
     * @return array<int, array{title: string, slug: string}>
     */
    public function getCourseIndex(): array
    {
        $index = [];

        foreach ($this->getEnrolments() as $row) {
            $courseId = (int) ($row['course_id'] ?? 0);

            if ($courseId <= 0) {
                continue;
            }

            $index[$courseId] = [
                'title' => (string) ($row['course_title'] ?? ''),
                'slug'  => (string) ($row['course_slug'] ?? ''),
            ];
        }

        // Certificate previews are independently capped at six and may refer
        // to an enrolment outside this course page. Resolve only those ids.
        foreach ($this->getCertificates() as $certificate) {
            if (isset($index[$certificate->courseId])) {
                continue;
            }
            $row = $this->kernel()->listMyEnrolments->forCourse($this->principal(), $certificate->courseId);
            if ($row !== null) {
                $index[$certificate->courseId] = ['title' => (string) $row['course_title'], 'slug' => (string) $row['course_slug']];
            }
        }

        return $index;
    }
}
