<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\View;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets;

defined('_JEXEC') or die;

/**
 * The base of every administrator screen that is a React mount: the six admin
 * screens and the course workbench.
 *
 * A subclass names its screen and its title and nothing else. The page itself
 * is one div carrying the whole host contract in data-vy-config plus one ES
 * module entry; everything inside the mount belongs to the app, and the app
 * learns what the caller may do from the API's own 401s and 403s rather than
 * from a capability this page would have to guess at.
 *
 * All actions, including the Settings entry, belong to the workspace.
 * The host title remains available to Atum without a second action toolbar.
 *
 * @since 1.0.0
 */
abstract class AppView extends BaseHtmlView
{
    /** The data-vy-config payload, not yet HTML-escaped. */
    public string $configJson = '';

    /** The id of the div the bundle mounts on. */
    public string $mountId = AdminMountConfig::ADMIN_MOUNT;

    /** The asset name suffix: com_vidyalms.app.<bundle>. */
    protected string $bundle = 'admin';

    /** Which of the admin app's screens this view renders. */
    protected string $screen = 'dashboard';

    /** The language key of the page title. */
    protected string $titleKey = 'COM_VIDYALMS_MANAGER_DASHBOARD';

    /** The Joomla toolbar icon of the page title. */
    protected string $titleIcon = 'home-2 vidyalms';

    /**
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        $this->configJson = AdminMountConfig::encode($this->mountConfig());

        $this->attachAssets();
        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mountConfig(): array
    {
        return AdminMountConfig::admin($this->screen);
    }

    /**
     * Loads this screen's app bundle, and anything another extension
     * contributed to the same mount, through Service\MountAssets. That class
     * owns the whole decision: the free bundle first, then every contributed
     * bundle behind it as a declared dependency. A Pro package or a
     * third-party question type reaches this page by listening to
     * onVidyaLmsMountAssets, never by editing this view.
     */
    protected function attachAssets(): void
    {
        MountAssets::attach(
            $this->getDocument()->getWebAssetManager(),
            MountAssetContext::administrator($this->bundle),
        );
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_($this->titleKey), $this->titleIcon);
    }
}
