<?php

declare(strict_types=1);

/**
 * VidyaLMS: the published seam for loading a second client bundle beside a mount.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Assets;

defined('ABSPATH') || exit;

/**
 * Extra client bundles, contributed per mount.
 *
 * The free build ships four app bundles and each page loads exactly one of
 * them. A package that contributes a question type renderer, a lesson type
 * renderer or any other client behaviour has a second bundle that has to load
 * beside one of those four, and before this seam existed its only options were
 * to edit a free-build file or to guess a hook of its own. Both are the fork
 * this architecture exists to prevent, so the list of extra bundles for a mount
 * is a filter, asked once at the point the app itself is enqueued.
 *
 * A contributor answers with a list of arrays:
 *
 *   add_filter('vidyalms_mount_bundles', function (array $bundles, string $mount, MountContext $context): array {
 *       if ($context->mount !== MountBundles::MOUNT_QUIZ_RUNNER) {
 *           return $bundles;
 *       }
 *
 *       $bundles[] = new MountBundle(
 *           'acme-question-types',
 *           plugins_url('assets/app/acme.js', __FILE__),
 *           '1.2.0',
 *       );
 *
 *       return $bundles;
 *   }, 10, 3);
 *
 * The third argument is a MountContext, the same shape the Joomla adapter
 * hands its own seam, so an add-on shipping for both hosts writes one
 * integration rather than two unrelated ones and the seam can learn a new
 * fact without every listener changing. The bare mount string stays as the
 * second argument for one release, and the array shape below is still
 * accepted, so a listener written before either keeps working.
 *
 * | Key | | |
 * |---|---|---|
 * | `handle` | required | the script handle, unique across the site |
 * | `src` | required | absolute URL to the JavaScript file |
 * | `version` | optional | cache-busting version, or null for none |
 * | `style` | optional | absolute URL to a stylesheet loaded with it |
 * | `module` | optional | false to load it as a classic script; default true |
 *
 * **Execution order is the whole point of this class.** The contributed bundle
 * reads globals the app bundle publishes at its own module scope, so it has to
 * run after the app, and "after" is not the same as "later in the document": an
 * ordinary classic script placed after a module tag executes while the document
 * is still parsing, which is before any module executes. So a contributed
 * bundle is enqueued as a script module where WordPress has them, and as a
 * deferred classic script otherwise. Both are queued behind the app bundle in
 * document order, and both defer, so document order becomes execution order.
 *
 * A contributed bundle is never a dependency of the free build. If the filter
 * has no listeners, or a listener answers with nothing, the page loads exactly
 * what it loaded before this class existed.
 *
 * **A duplicate handle is reported rather than swallowed.** wp_enqueue_script
 * keeps the first registration of a handle and says nothing, so two plugins
 * contributing the same handle used to mean one renderer simply never loaded,
 * invisibly. The Joomla registry throws on a duplicate name for the same
 * reason: two packages that both believe they own a name have to be told.
 */
final class MountBundles
{
    /**
     * The extra bundles for one mount.
     *
     * Signature: (array $bundles, string $mount, MountContext $context): array
     */
    public const FILTER = 'vidyalms_mount_bundles';

    /** The four mount names, which are also the app bundle file names. */
    public const MOUNT_PLAYER = 'player';

    public const MOUNT_QUIZ_RUNNER = 'quiz-runner';

    public const MOUNT_INSTRUCTOR = 'instructor';

    public const MOUNT_ADMIN = 'admin';

    private function __construct()
    {
    }

    /**
     * Asks for and enqueues the extra bundles for one mount.
     *
     * Called immediately after the app bundle itself is enqueued, and only
     * then: a page that ships no app has no globals to register into, and a
     * bundle loaded there would warn in the console about a bundle that was
     * never going to be present.
     *
     * @param string $mount one of the four MOUNT_ constants
     * @param string $appHandle the handle the app bundle was enqueued under
     */
    public static function enqueueFor(string $mount, string $appHandle, ?MountContext $context = null): void
    {
        LearnerAssets::mark($appHandle);
        $context ??= is_admin()
            ? MountContext::admin($mount, $appHandle)
            : MountContext::site($mount, $appHandle);

        // The bare mount stays as the second argument for one release, so a
        // listener written before the context object keeps working; the
        // context is the third, and is what a listener should read.
        /** @var mixed $contributed */
        $contributed = apply_filters('vidyalms_mount_bundles', [], $mount, $context);

        if (!\is_array($contributed)) {
            return;
        }

        $seen = [];

        foreach ($contributed as $entry) {
            $bundle = self::readBundle($entry, $mount);

            if ($bundle === null) {
                continue;
            }

            // wp_enqueue_script silently keeps the first registration of a
            // handle, so two plugins contributing the same handle used to be
            // invisible: one renderer simply never loaded. The Joomla
            // registry throws on a duplicate name for the same reason.
            if (isset($seen[$bundle->handle])) {
                self::complain(
                    sprintf('two bundles claim the handle "%s", so only the first was loaded', $bundle->handle),
                    $mount
                );

                continue;
            }

            $seen[$bundle->handle] = true;
            LearnerAssets::mark($bundle->handle);

            if ($bundle->style !== null) {
                wp_enqueue_style($bundle->handle, $bundle->style, [], $bundle->version ?? false);
            }

            self::enqueueScript($bundle->handle, $bundle->src, $bundle->version, $appHandle, $bundle->module);
        }
    }

    /**
     * One contributed entry as a typed descriptor, or null when it is not a
     * bundle at all and the contributor has been told so.
     */
    private static function readBundle(mixed $entry, string $mount): ?MountBundle
    {
        if ($entry instanceof MountBundle) {
            return $entry;
        }

        if (!\is_array($entry)) {
            self::complain('every contributed bundle must be a MountBundle or an array', $mount);

            return null;
        }

        try {
            /** @var array<string, mixed> $entry */
            return MountBundle::fromArray($entry);
        } catch (\InvalidArgumentException $error) {
            self::complain($error->getMessage(), $mount);

            return null;
        }
    }

    /**
     * Either mechanism defers, and both queue behind the app bundle, so the
     * contributed bundle runs after the app's module scope has published the
     * globals it registers into.
     *
     * The classic path names the app handle as a dependency, which is what
     * orders it in the printed output. The module path relies on enqueue
     * order, which is the order WordPress prints script modules in, and
     * declares the dependency anyway so the relationship is stated rather
     * than inferred from a call sequence.
     */
    private static function enqueueScript(
        string $handle,
        string $src,
        ?string $version,
        string $appHandle,
        bool $asModule
    ): void {
        if ($asModule && \function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module($handle, $src, [['id' => $appHandle]], $version ?? false);

            return;
        }

        // 'defer' rather than in_footer alone: a classic script printed after a
        // module tag still executes before it unless it defers too.
        wp_enqueue_script($handle, $src, [$appHandle], $version ?? false, [
            'in_footer' => true,
            'strategy' => 'defer',
        ]);
    }

    /**
     * _doing_it_wrong() rather than a silent skip: a contributor whose bundle
     * never loads has nothing to read otherwise, and this speaks only when
     * WP_DEBUG is on, so a production site sees nothing.
     */
    private static function complain(string $problem, string $mount): void
    {
        _doing_it_wrong(
            __METHOD__,
            esc_html(
                sprintf(
                    /* translators: 1: what was wrong with the filtered value, 2: the mount name. */
                    __('The %2$s mount was offered a bundle that was skipped: %1$s.', 'vidyalms'),
                    $problem,
                    $mount
                )
            ),
            '1.0.0'
        );
    }
}
