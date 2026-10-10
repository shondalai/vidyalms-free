<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\View\Workbench;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig;
use VidyaLMS\Component\VidyaLMS\Administrator\View\AppView;

defined('_JEXEC') or die;

/**
 * The instructor workbench: one course and its five tabs, or the caller's own
 * course list when the URL names no course.
 *
 * The slug is looked up through GetManagedCourse, which applies the per-course
 * rule. A caller who may not read this course gets an empty slug and the app
 * asks the API anyway, so the refusal is the core's and reads the same here as
 * it does on WordPress. Deciding it in this view instead would put a second
 * copy of the rule in the adapter.
 *
 * @since 1.0.0
 */
final class HtmlView extends AppView
{
    public string $mountId = AdminMountConfig::WORKBENCH_MOUNT;

    protected string $bundle = 'instructor';

    protected string $titleKey = 'COM_VIDYALMS_MANAGER_WORKBENCH';

    protected string $titleIcon = 'pencil-2 vidyalms';

    /** The course title, when the caller may read it. */
    private string $courseTitle = '';

    /**
     * @return array<string, mixed>
     */
    protected function mountConfig(): array
    {
        $courseId = Factory::getApplication()->getInput()->getInt('id', 0);

        if ($courseId <= 0) {
            return AdminMountConfig::workbench(null);
        }

        $slug = '';

        try {
            $detail = VidyaLmsHelper::kernel()->getManagedCourse->execute(VidyaLmsHelper::principal(), $courseId);

            $slug              = $detail->managed->course->slug->value;
            $this->courseTitle = $detail->managed->course->title;
        } catch (DomainException) {
            // The app asks the same route and renders the same refusal.
        }

        return AdminMountConfig::workbench($courseId, $slug);
    }

    protected function addToolbar(): void
    {
        parent::addToolbar();

        if ($this->courseTitle !== '') {
            $this->getDocument()->setTitle(
                Text::_('COM_VIDYALMS_MANAGER_WORKBENCH') . ': ' . $this->courseTitle,
            );
        }
    }
}
