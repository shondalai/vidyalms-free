<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use VidyaLMS\Plugin\System\VidyaLmsEasyCommerce\Extension\EasyCommerce;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (): EasyCommerce {
            $plugin = new EasyCommerce((array) PluginHelper::getPlugin('system', 'vidyalms_easycommerce'));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
