<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_task_vidyalms
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
use VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $app = Factory::getApplication();

            // Booting the component registers the CoreKernel on the global
            // container. If com_vidyalms is absent or disabled the routines
            // resolve nothing and report a clean skip instead of fataling.
            try {
                $app->bootComponent('com_vidyalms');
            } catch (\Throwable) {
                // Swallowed: every routine guards on kernel availability.
            }

            $plugin = new VidyaLms(
                (array) PluginHelper::getPlugin('task', 'vidyalms')
            );
            $plugin->setApplication($app);
            $plugin->setDatabase($container->get(DatabaseInterface::class));

            return $plugin;
        });
    }
};
