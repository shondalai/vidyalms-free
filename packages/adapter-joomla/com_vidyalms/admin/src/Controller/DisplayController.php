<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Controller;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;

defined('_JEXEC') or die;

/**
 * The default administrator controller: the dashboard unless another view is
 * asked for.
 *
 * The gate is the same one the component dispatcher applies, and for the same
 * reason: an instructor holds vidyalms.teach and not core.manage, so
 * core.manage alone would keep the person who teaches a course out of that
 * course's workbench. What each screen may then do is the core's answer, not
 * this controller's.
 *
 * @since 1.0.0
 */
final class DisplayController extends BaseController
{
    /** @var string */
    protected $default_view = 'dashboard';

    /**
     * @param bool  $cachable
     * @param array $urlparams
     *
     * @return static
     */
    public function display($cachable = false, $urlparams = [])
    {
        if (!VidyaLmsHelper::mayReachAdministrator($this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($this->input->getCmd('view', 'dashboard') === 'dashboard'
            && !$this->app->getIdentity()->authorise('core.manage', 'com_vidyalms')) {
            $this->input->set('view', 'courses');
        }

        try {
            $storage = VidyaLmsHelper::storage();
            if (!$storage instanceof \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage
                || !\Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::writableDirectory(
                    dirname($storage->privatePath('.storage-health')),
                )) {
                throw new \RuntimeException('Private storage is unavailable.');
            }
        } catch (\Throwable) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_PRIVATE_STORAGE_UNAVAILABLE'), 'warning');
        }

        $view = $this->input->getCmd('view');
        $screen = $view === 'screen' ? $this->input->getCmd('screen') : $view;
        if (in_array($screen, ['certificates', 'settings', 'operations'], true) && $this->certificatePdfUnavailable()) {
            $this->app->enqueueMessage(Text::_('COM_VIDYALMS_PDF_WORKER_UNAVAILABLE'), 'warning');
        }
        return parent::display($cachable, $urlparams);
    }

    /**
     * Whether a certificate PDF renderer is installed and cannot run on this host.
     *
     * This package ships no PDF engine: its certificates are HTML, so a site
     * with no renderer seated has nothing to warn about. VidyaLMS Pro seats one
     * at the ports-ready seam, and that renderer can say why it cannot start
     * its worker, which is the one case worth a warning. The check reads the
     * port the kernel was actually built with, so it can never disagree with
     * what issuance will do.
     */
    private function certificatePdfUnavailable(): bool
    {
        try {
            $pdf = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::kernel()->ports()->pdf;
        } catch (\Throwable) {
            // A kernel that cannot be built has its own, louder notice.
            return false;
        }

        if ($pdf === null || !method_exists($pdf, 'availabilityProblem')) {
            return false;
        }

        try {
            return $pdf->availabilityProblem() !== null;
        } catch (\Throwable) {
            return true;
        }
    }
}
