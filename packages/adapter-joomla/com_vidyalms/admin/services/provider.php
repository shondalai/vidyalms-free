<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\CategoryFactory;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\Registry as HtmlRegistry;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use VidyaLMS\Component\VidyaLMS\Administrator\Extension\VidyaLmsComponent;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaAuthorization;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaCache;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaCommerce;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaCourseCategories;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaDatabase;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaEventBridge;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaMailer;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaRandom;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaScheduler;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaLocale;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaTranslator;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaUsers;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\SystemClock;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

/**
 * The VidyaLMS service provider.
 *
 * The ports and the CoreKernel are registered on the GLOBAL container
 * so the administrator app, the site app, the API app, plg_task_vidyalms and
 * every integration plugin resolve the same shared instances. Only the MVC,
 * dispatcher and router factories plus the component itself belong on the local
 * container Joomla hands us.
 *
 * Port implementations live in the administrator namespace because they are
 * infrastructure, not screens; installing the administrator files is what makes
 * the site and API areas work.
 *
 * @since 1.0.0
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $global = Factory::getContainer();

        // Joomla MVC, ComponentDispatcher and Router factories.
        $container->registerServiceProvider(new MVCFactory('\\VidyaLMS\\Component\\VidyaLMS'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\VidyaLMS\\Component\\VidyaLMS'));
        $container->registerServiceProvider(new RouterFactory('\\VidyaLMS\\Component\\VidyaLMS'));
        $container->registerServiceProvider(new CategoryFactory('\\VidyaLMS\\Component\\VidyaLMS'));

        // Database alias, so the ports and any repository share one driver.
        $global->set('vidyalms.db', static fn () => $global->get(DatabaseInterface::class), true);

        // The ports. Nothing here holds request state; each is safe to share.
        $global->set(
            DatabasePort::class,
            static fn (Container $c) => new JoomlaDatabase($c->get('vidyalms.db')),
            true
        );

        $global->set(
            UsersPort::class,
            static fn (Container $c) => new JoomlaUsers($c->get(UserFactoryInterface::class), $c->get('vidyalms.db')),
            true
        );

        $global->set(
            AuthorizationPort::class,
            static fn (Container $c) => new JoomlaAuthorization(
                $c->get(UserFactoryInterface::class),
                $c->get('vidyalms.db')
            ),
            true
        );

        $global->set(
            \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration::class,
            static fn (Container $c) => new \Shondalai\VidyaLMS\Core\Application\Platform\RuntimeConfiguration(
                new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseConfigurationRepository(
                    $c->get(DatabasePort::class), $c->get(ClockPort::class)
                ),
                static fn (): array => ComponentHelper::getParams('com_vidyalms')->toArray()
            ),
            true
        );

        $global->set(
            StoragePort::class,
            static function () use ($global) {
                $configured = trim(
                    defined('VIDYALMS_PRIVATE_DIR') ? (string) constant('VIDYALMS_PRIVATE_DIR') : ''
                );

                if ($configured === '') {
                    try {
                        $resolver = \Shondalai\VidyaLMS\Core\Infrastructure\Storage\AutomaticPrivateStorage::class;
                        $settings = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository(
                            $global->get(DatabasePort::class), $global->get(ClockPort::class)
                        );
                        $stored = $settings->find($resolver::SETTING)?->value;
                        $config = $global->get('config');
                        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
                        $identity = (string) realpath(JPATH_ROOT) . '|' . php_uname('n') . '|' . (string) $config->get('live_site', '');
                        $record = $resolver::resolve(JPATH_ROOT, [JPATH_ROOT, $documentRoot,
                            defined('JPATH_PUBLIC') ? JPATH_PUBLIC : '',
                            JoomlaStorage::documentRoot(JPATH_ROOT, (string) $config->get('live_site', '')) ?? ''],
                            $identity, (string) $config->get('secret', ''), $stored, $documentRoot !== '');
                        if ($record !== null) {
                            if ($stored === null) {
                                $settings->set($resolver::SETTING, $record);
                            }
                            $configured = $record['path'];
                        }
                    } catch (\Throwable) {
                        // Restricted hosting requires an explicit external root.
                        // Public uploads and the rest of the component remain usable.
                    }
                }

                // The server constant is shared by web requests and workers.
                // Database settings cannot grant a manager filesystem access.
                return new JoomlaStorage(
                    JPATH_ROOT,
                    JoomlaStorage::resolvePrivateRoot(JPATH_ROOT, $configured),
                    JoomlaStorage::documentRoot(
                        JPATH_ROOT,
                        (string) $global->get('config')->get('live_site', '')
                    )
                );
            },
            true
        );

        // The active Language object is owned by the application, not the
        // container; the container only holds the language factory.
        $global->set(
            TranslatorPort::class,
            static fn () => new JoomlaTranslator(Factory::getApplication()->getLanguage(), JPATH_ADMINISTRATOR),
            true
        );

        // Its own port rather than a method on the translator: a paid package
        // implements TranslatorPort too, so a method added there fatals an
        // installed paid build at class load the moment this one is upgraded.
        $global->set(
            LocalePort::class,
            static fn () => new JoomlaLocale(Factory::getApplication()->getLanguage()),
            true
        );

        $global->set(
            MailerPort::class,
            static fn (Container $c) => new JoomlaMailer(
                $c->get(MailerFactoryInterface::class),
                $c->get(TranslatorPort::class),
                $c->get(UsersPort::class),
                Factory::getApplication()->getLanguage()->getTag()
            ),
            true
        );

        $global->set(ClockPort::class, static fn () => new SystemClock(), true);
        $global->set(
            \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\MappingSettings::class,
            static fn (Container $c) => new \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\MappingSettings(
                new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseConfigurationRepository(
                    $c->get(DatabasePort::class), $c->get(ClockPort::class),
                    \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\MappingSettings::NAMESPACE,
                ),
                new \VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\Catalogue($c->get('vidyalms.db')),
            ),
            true,
        );
        $global->set(RandomPort::class, static fn () => new JoomlaRandom(), true);

        $global->set(
            SchedulerPort::class,
            static fn (Container $c) => new JoomlaScheduler($c->get(DatabasePort::class), $c->get(ClockPort::class)),
            true
        );

        $global->set(
            EventBridgePort::class,
            static fn (Container $c) => new JoomlaEventBridge(
                $c->get(DispatcherInterface::class),
                $c->get(LoggerInterface::class)
            ),
            true
        );

        $global->set(
            CommercePort::class,
            static fn (Container $c) => new JoomlaCommerce($c->get(DispatcherInterface::class)),
            true
        );

        $global->set(
            CourseCategoryPort::class,
            static fn (Container $c) => new JoomlaCourseCategories(
                $c->get('vidyalms.db'),
                static function (string $action, string $asset): bool {
                    $application = Factory::getApplication();

                    if (!method_exists($application, 'getIdentity')) {
                        return false;
                    }

                    return $application->getIdentity()?->authorise($action, $asset) ?? false;
                }
            ),
            true
        );

        $global->set(
            CachePort::class,
            static fn (Container $c) => new JoomlaCache($c->get(CacheControllerFactoryInterface::class)),
            true
        );

        // The bundle and the kernel. One kernel per request, shared by every app.
        $global->set(Ports::class, static fn (Container $c) => KernelFactory::ports($c), true);
        $global->set(CoreKernel::class, static fn (Container $c) => KernelFactory::create($c), true);
        $global->set('vidyalms.kernel', static fn (Container $c) => $c->get(CoreKernel::class), true);

        // Component registration.
        $container->set(
            ComponentInterface::class,
            static function (Container $container) {
                $component = new VidyaLmsComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class)
                );

                $component->setCategoryFactory($container->get(CategoryFactoryInterface::class));
                $component->setRegistry($container->get(HtmlRegistry::class));
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                $component->setRouterFactory($container->get(RouterFactoryInterface::class));

                return $component;
            }
        );
    }
};
