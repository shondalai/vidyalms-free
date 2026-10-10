<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Dispatcher;

defined('_JEXEC') or die;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\WebAsset\Exception\UnknownAssetException;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;

/**
 * Administrator dispatcher for com_vidyalms. Attaches the component stylesheet
 * once for every screen, so no template registers an asset of its own, and
 * widens the base dispatcher's core.manage gate to the four operational
 * actions.
 *
 * @since 1.0.0
 */
final class Dispatcher extends ComponentDispatcher
{
    private const COMPONENT = 'com_vidyalms';

    private const STYLE = 'com_vidyalms.admin';

    public function dispatch(): void
    {
        $this->attachStyle();

        parent::dispatch();
    }

    /**
     * Joomla's own check is core.manage on the component, which an instructor
     * does not hold: a group given only vidyalms.teach would be refused here,
     * before the workbench could show it the course it does instruct. The
     * accepted actions are VidyaLmsHelper::ADMINISTRATOR_ACTIONS, and passing
     * this gate grants nothing beyond reaching the screen: every request the
     * screens make is answered by the core's AccessGuard and CourseAccess.
     *
     * @throws NotAllowed
     */
    protected function checkAccess(): void
    {
        if (!$this->app->isClient('administrator')) {
            parent::checkAccess();

            return;
        }

        if (!VidyaLmsHelper::mayReachAdministrator($this->app->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * A raw or JSON response has no web asset manager, so the style is
     * attached only for HTML documents.
     */
    private function attachStyle(): void
    {
        $document = $this->app->getDocument();

        if (!$document instanceof HtmlDocument) {
            return;
        }

        $assets = $document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile(self::COMPONENT);

        // A missing or unreadable joomla.asset.json must not take the screen
        // down with it; the stylesheet is then registered by hand.
        try {
            $assets->useStyle(self::STYLE);
        } catch (UnknownAssetException) {
            $assets->registerAndUseStyle(self::STYLE, 'com_vidyalms/admin.css');
        }

        // Asset-only installs must refresh the host frame as well as the SPA bundle.
        $file = JPATH_ROOT . '/media/com_vidyalms/css/admin.css';
        $version = is_file($file) ? hash_file('sha256', $file) : false;

        if ($version !== false) {
            $asset = $assets->getAsset('style', self::STYLE);
            $options = $asset->getOptions();
            $options['version'] = substr($version, 0, 16);
            $assets->registerAsset(
                'style',
                self::STYLE,
                $asset->getUri(false),
                $options,
                $asset->getAttributes(),
                $asset->getDependencies(),
            );
        }
    }
}
