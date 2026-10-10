<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_privacy_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use VidyaLMS\Plugin\Privacy\VidyaLms\Extension\VidyaLms;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $app = Factory::getApplication();

            // The port bundle is the preferred read and write path. Booting the
            // component registers it; the plugin falls back to the driver when
            // com_vidyalms is absent.
            try {
                $app->bootComponent('com_vidyalms');
            } catch (\Throwable) {
                // Swallowed: the plugin guards on port availability.
            }

            $plugin = new VidyaLms(
                (array) PluginHelper::getPlugin('privacy', 'vidyalms')
            );
            $plugin->setApplication($app);
            $plugin->setDatabase($container->get(DatabaseInterface::class));

            return $plugin;
        });
    }
};
