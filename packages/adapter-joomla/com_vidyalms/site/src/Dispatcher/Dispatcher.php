<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Dispatcher;

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\WebAsset\Exception\UnknownAssetException;

/**
 * Site dispatcher for com_vidyalms. Attaches the component stylesheet once for
 * every server-rendered view, then hands over to the standard component
 * dispatcher.
 *
 * @since 1.0.0
 */
final class Dispatcher extends ComponentDispatcher
{
    public function dispatch(): void
    {
        $this->attachSiteStyle();

        parent::dispatch();
    }

    /**
     * Raw and feed responses (the signed file download among them) have no web
     * asset manager, so the style is attached only for HTML documents.
     */
    private function attachSiteStyle(): void
    {
        $document = $this->app->getDocument();

        if (!$document instanceof HtmlDocument) {
            return;
        }

        $assets = $document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_vidyalms');

        $navigationFile = JPATH_ROOT . '/media/com_vidyalms/app/js/learner-navigation.js';
        if (is_file($navigationFile)) {
            $assets->registerAndUseScript('com_vidyalms.learner-navigation', 'com_vidyalms/app/learner-navigation.js',
                ['version' => substr(hash_file('sha256', $navigationFile), 0, 12)], ['type' => 'module']);
        }

        // A missing or unreadable joomla.asset.json must not take the page
        // down with it; the stylesheet is then registered by hand.
        try {
            $theme = JPATH_ROOT . '/media/com_vidyalms/app/css/public-theme.css';
            if (is_file($theme)) {
                $assets->registerAndUseStyle('com_vidyalms.site', 'com_vidyalms/app/public-theme.css',
                    ['version' => substr(hash_file('sha256', $theme), 0, 12)], [], ['com_vidyalms.public-structure']);
            } else {
                $assets->useStyle('com_vidyalms.site');
            }
        } catch (UnknownAssetException) {
            $assets->registerAndUseStyle('com_vidyalms.site', 'com_vidyalms/site.css');
        }
    }
}
