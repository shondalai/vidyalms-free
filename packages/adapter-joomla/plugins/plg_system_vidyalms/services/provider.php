<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_system_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use VidyaLMS\Plugin\System\VidyaLms\Extension\VidyaLms;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            $plugin = new VidyaLms(
                (array) PluginHelper::getPlugin('system', 'vidyalms')
            );
            $plugin->setApplication(Factory::getApplication());
            $plugin->setUserFactory($container->get(UserFactoryInterface::class));

            return $plugin;
        });
    }
};
