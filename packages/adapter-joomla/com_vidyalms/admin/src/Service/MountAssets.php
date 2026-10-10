<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\Exception\UnknownAssetException;
use Joomla\CMS\WebAsset\WebAssetManager;

/**
 * The one place a VidyaLMS React mount gets its client assets, and the seam
 * another extension adds a bundle to.
 *
 * ## Why the seam exists
 *
 * The server half of an add-on is a provider registered on
 * onVidyaLmsKernelReady. The client half is a JavaScript bundle that has to
 * be on the page that renders the mount, and there is no CMS-neutral place to
 * declare that: only this component knows which mount is being rendered, what
 * the free bundle for it is called, and that the free bundle must run first
 * because it is what creates window.vidyalms.questionTypes. So this component
 * owns the decision and publishes the seam, exactly as it publishes
 * onVidyaLmsKernelReady for the server half and onVidyaLmsUploadPolicy for
 * upload purposes.
 *
 * Without it, a Pro package would have to edit a free-build view to get its
 * renderer onto the page, which is the one thing ADR 0002 forbids.
 *
 * ## Extending it
 *
 * The registry is built once per mount and handed to onVidyaLmsMountAssets
 * before anything is enabled:
 *
 *     public static function getSubscribedEvents(): array
 *     {
 *         return ['onVidyaLmsMountAssets' => 'onVidyaLmsMountAssets'];
 *     }
 *
 *     public function onVidyaLmsMountAssets(EventInterface $event): void
 *     {
 *         $event->getArgument('registry')->register(new MountAssetBundle(
 *             'vidyalms_pro.app.question-types',
 *             [MountAssets::MOUNT_QUIZ_RUNNER, MountAssets::MOUNT_INSTRUCTOR],
 *             'vidyalms_pro/app/pro-question-types.js',
 *         ));
 *     }
 *
 * Three facts a listener can rely on:
 *
 * - **The free bundle is enabled first and is added to every contributed
 *   bundle's dependencies.** A listener never names it, because which bundle
 *   it is depends on the mount; declaring both would drag the workbench app
 *   onto a learner's quiz page. Order is therefore declared through the web
 *   asset graph rather than hoped for from insertion order.
 * - **Attributes default to defer.** The free bundles are ES modules, which
 *   the browser defers; a classic script without defer would execute before
 *   them and find no registry to register into.
 * - **A listener that throws is caught, and only that listener loses.** Each
 *   is invoked inside its own try, so one extension's failure cannot discard
 *   the bundles of every extension queued behind it, and the throwable is
 *   written to the component log naming the seam and the mount, so a
 *   contributor whose bundle vanished has something to read. The page still
 *   renders with the free bundle and everything else that registered, because
 *   a third-party renderer must not be able to take a learner's quiz down.
 *
 * @since 1.0.0
 */
final class MountAssets
{
    public const EVENT = 'onVidyaLmsMountAssets';

    /** The learner's course player, a site page. */
    public const MOUNT_PLAYER = 'player';

    /** The learner's quiz runner, a site page. */
    public const MOUNT_QUIZ_RUNNER = 'quiz-runner';

    /** The instructor workbench, an administrator page. */
    public const MOUNT_INSTRUCTOR = 'instructor';

    /** The six administrator screens, all one bundle. */
    public const MOUNT_ADMIN = 'admin';
    public const MOUNT_CREDENTIALS = 'credentials';

    /**
     * Every mount this component renders. A bundle naming anything else is
     * refused at construction, so a typo in a third-party plugin is a message
     * in the log rather than a renderer that silently never loads.
     */
    public const MOUNTS = [
        self::MOUNT_PLAYER,
        self::MOUNT_QUIZ_RUNNER,
        self::MOUNT_INSTRUCTOR,
        self::MOUNT_ADMIN,
        self::MOUNT_CREDENTIALS,
    ];

    private function __construct()
    {
    }

    /**
     * Enables the free bundle for this mount, then every contributed bundle
     * that belongs on it.
     *
     * @return list<string> the contributed asset names that were enabled
     */
    public static function attach(WebAssetManager $assets, MountAssetContext $context): array
    {
        $free = self::useFreeBundle($assets, $context->mount);

        $enabled = [];

        foreach (self::registry($context)->bundlesFor($context) as $bundle) {
            if (self::useBundle($assets, $bundle, $free)) {
                $enabled[] = $bundle->name;
            }
        }

        return $enabled;
    }

    /**
     * The web asset name of the free build's own bundle for a mount. Public
     * because the smoke harness asserts the seam pairs each mount with it.
     */
    public static function freeAssetName(string $mount): string
    {
        return 'com_vidyalms.app.' . $mount;
    }

    /**
     * The bundles this site contributes to the mount: none from the free
     * build, then whatever the extension event added.
     */
    public static function registry(MountAssetContext $context): MountAssetRegistry
    {
        $registry = new MountAssetRegistry();

        try {
            $dispatcher = Factory::getApplication()->getDispatcher();
        } catch (\Throwable $error) {
            ComponentLog::error('The mount asset seam could not reach a dispatcher: ' . $error->getMessage());

            return $registry;
        }

        // One try per listener, not one around the whole dispatch.
        //
        // Catching around the dispatch meant a single third-party listener
        // that threw discarded every listener queued behind it, including a
        // paid package's, with no log line anywhere: a contributor whose
        // bundle silently vanished had nothing at all to read. Isolating each
        // listener keeps the failure to the extension that caused it, and the
        // log names it.
        foreach (self::listeners($dispatcher) as $listener) {
            $event = new GenericEvent(self::EVENT, [
                'registry' => $registry,
                'context'  => $context,
                'subject'  => $registry,
            ]);

            try {
                $listener($event);
            } catch (\Throwable $error) {
                ComponentLog::error(sprintf(
                    'A listener on %s failed while the %s mount was rendering and its bundles were skipped: %s',
                    self::EVENT,
                    $context->mount,
                    $error->getMessage(),
                ));
            }
        }

        return $registry;
    }

    /**
     * The listeners registered for the seam, in dispatch order.
     *
     * Joomla's dispatcher has no public per-listener invoke, so the list is
     * read and called here. A dispatcher that cannot answer (a future version,
     * a decorator) falls back to one dispatch of the whole event, which is
     * the previous behaviour rather than no behaviour.
     *
     * @return list<callable>
     */
    private static function listeners(object $dispatcher): array
    {
        if (!method_exists($dispatcher, 'getListeners')) {
            return [
                static function (GenericEvent $event) use ($dispatcher): void {
                    if (method_exists($dispatcher, 'dispatch')) {
                        $dispatcher->dispatch(self::EVENT, $event);
                    }
                },
            ];
        }

        /** @var mixed $listeners */
        $listeners = $dispatcher->getListeners(self::EVENT);

        return \is_array($listeners) ? array_values(array_filter($listeners, '\is_callable')) : [];
    }

    /**
     * Loads the free build's bundle for a mount. The entries live in
     * media/joomla.asset.json; a site whose registry copy is missing or stale
     * still gets them through the by-hand registration, the same fallback the
     * dispatchers apply to the stylesheets.
     */
    private static function useFreeBundle(WebAssetManager $assets, string $mount): string
    {
        $name = self::freeAssetName($mount);

        try {
            $assets->useStyle($name);
            $assets->useScript($name);
        } catch (UnknownAssetException) {
            $assets->registerAndUseStyle($name, 'com_vidyalms/app/' . $mount . '.css');
            $assets->registerAndUseScript(
                $name,
                'com_vidyalms/app/' . $mount . '.js',
                [],
                ['type' => 'module'],
            );
        }

        // Fixed entry names must expire when their installed content changes,
        // including asset-only deployments that keep the extension version.
        foreach (['style' => ['css', 'css'], 'script' => ['js', 'js']] as $type => [$folder, $extension]) {
            $file = JPATH_ROOT . '/media/com_vidyalms/app/' . $folder . '/' . $mount . '.' . $extension;
            $version = is_file($file) ? hash_file('sha256', $file) : false;

            if ($version === false) {
                continue;
            }

            $asset = $assets->getAsset($type, $name);
            $options = $asset->getOptions();
            $options['version'] = substr($version, 0, 16);
            $assets->registerAsset(
                $type,
                $name,
                $asset->getUri(false),
                $options,
                ['data-vy-spa-asset' => 'true'] + $asset->getAttributes(),
                $asset->getDependencies(),
            );
        }

        return $name;
    }

    /**
     * Registers and enables one contributed bundle behind the mount's own.
     *
     * Local contributions use their installed content as the cache version,
     * so an asset-only update also takes effect after a normal refresh.
     * External assets retain Joomla's version handling.
     */
    private static function useBundle(WebAssetManager $assets, MountAssetBundle $bundle, string $free): bool
    {
        $dependencies = $bundle->dependencies;
        $dependencies[] = $free;
        $dependencies = array_values(array_unique($dependencies));

        try {
            if ($bundle->style !== null) {
                $assets->registerAndUseStyle($bundle->name, $bundle->style, [], ['data-vy-spa-asset' => 'true'], $dependencies);
                self::versionLocalContribution($assets, 'style', $bundle->name);
            }

            if ($bundle->script !== null) {
                $assets->registerAndUseScript($bundle->name, $bundle->script, [], ['data-vy-spa-asset' => 'true'] + $bundle->attributes, $dependencies);
                self::versionLocalContribution($assets, 'script', $bundle->name);
            }
        } catch (\Throwable) {
            // One bad contribution costs its own bundle and nothing else.
            return false;
        }

        return true;
    }

    private static function versionLocalContribution(WebAssetManager $assets, string $type, string $name): void
    {
        $asset = $assets->getAsset($type, $name);
        $uri = $asset->getUri();
        $prefix = rtrim(Uri::root(true), '/') . '/media/';

        if (!str_starts_with($uri, $prefix)) {
            return;
        }

        $media = realpath(JPATH_ROOT . '/media');
        $file = realpath(JPATH_ROOT . '/media/' . substr($uri, strlen($prefix)));

        if ($media === false || $file === false || !str_starts_with($file, $media . DIRECTORY_SEPARATOR) || !is_file($file)) {
            return;
        }

        $hash = hash_file('sha256', $file);

        if ($hash === false) {
            return;
        }

        $options = $asset->getOptions();
        $options['version'] = substr($hash, 0, 16);
        $assets->registerAsset($type, $name, $asset->getUri(false), $options, $asset->getAttributes(), $asset->getDependencies());
    }
}
