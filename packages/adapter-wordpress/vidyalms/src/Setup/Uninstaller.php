<?php

declare(strict_types=1);

/**
 * VidyaLMS: uninstall, the only thing that destroys data.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\WordPress\Plugin;

defined('ABSPATH') || exit;

/**
 * Each site must opt in to data deletion before WordPress deletes the plugin.
 * Otherwise course data, settings, files and capabilities survive a reinstall.
 * Scheduled work stops in both cases. WordPress calls this once per network;
 * this class visits every site and reads its own deletion preference.
 *
 * The tables are found by name rather than read from the schema file, so a
 * table added by a Pro package or left behind by an older build is removed too
 * and nothing survives on the strength of a file that has already been deleted.
 *
 */
final class Uninstaller
{
    public const DELETE_DATA_OPTION = 'vidyalms_delete_data_on_uninstall';
    /**
     * Every option this plugin writes, plus the one Pro row that describes
     * tables this plugin drops. Listed rather than pattern matched, so a site
     * option belonging to another plugin whose name happens to start with
     * vidyalms_ is never deleted.
     *
     * @var list<string>
     */
    private const OPTIONS = [
        'vidyalms_automatic_private_storage',
        self::DELETE_DATA_OPTION,
        \Shondalai\VidyaLMS\WordPress\Files\PrivateStorageOwnership::TOKEN_OPTION,
        Activator::OPTION_SCHEMA_VERSION,
        Activator::OPTION_PLUGIN_VERSION,
        Activator::OPTION_ACTIVATED_AT,
        Settings::OPTION,
        Activator::OPTION_SIGNED_URL_TTL,
        Activator::OPTION_CATALOGUE_SHOW_LOCKED,
        Activator::OPTION_JOB_BATCH_SIZE,
        Activator::OPTION_SEEDED_DATA_VERSION,
        'vidyalms_course_shells',
        'vidyalms_rewrite_rules_version',
        'vidyalms_woocommerce_mappings',
        // The Pro schema version, and it is here rather than in the Pro
        // uninstaller on purpose.
        //
        // Pro deliberately keeps the row when Pro alone is deleted, because
        // the tables it describes are still there and deleting it would leave
        // a later reinstall believing a fresh install had created them. This
        // uninstall is what makes that premise false: it drops every
        // `<prefix>vidyalms_` table by name, Pro's among them, so once it has
        // run the row describes tables that no longer exist. Left behind, a
        // fresh Pro install reads 1.4.1 off a site with no tables at all,
        // decides it is already migrated, and creates nothing.
        //
        // Proved on the WordPress development site: deleting Pro and then the
        // free plugin, in the order the install order requires, left zero
        // wp_vidyalms_ tables and vidyalms_pro_schema_version = 1.4.1.
        'vidyalms_pro_schema_version',
    ];

    private function __construct()
    {
    }

    public static function uninstall(): void
    {
        Sites::each([self::class, 'uninstallSite']);
    }

    /** Site deletion is explicit destruction, independent of plugin-removal preferences. */
    public static function uninitializeSite(\WP_Site $site): void
    {
        Sites::on((int) $site->blog_id, static function (): void {
            // Runs before WordPress drops options and the file-reference tables.
            // A foreign marker fails closed; Sites::on always restores context.
            PrivateFiles::deleteForSite();
            self::clearScheduledWork();
        });
    }

    /** @param list<string> $tables @return list<string> */
    public static function siteTablesToDrop(array $tables, int $siteId): array
    {
        if (!is_multisite() || get_current_blog_id() !== $siteId) {
            throw new \RuntimeException('Cannot identify the site whose VidyaLMS tables should be removed.');
        }
        // WordPress is already switched to the deleted site. Its own tables
        // include options and cron; this adds Free and Pro tables only.
        return array_values(array_unique(array_merge($tables, self::ownedTables())));
    }

    public static function uninstallSite(): void
    {
        self::clearScheduledWork();
        // Strict allow-list; strings such as "false" must never erase a site.
        if (!\in_array(get_option(self::DELETE_DATA_OPTION, false), [true, 1, '1'], true)) {
            return;
        }

        PrivateFiles::deleteForSite();
        self::deleteShellsAndTerms();
        self::dropTables();
        self::deleteOptions();
        self::deleteTransients();

        Capabilities::unregister();
    }

    private static function clearScheduledWork(): void
    {
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_JOBS);
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_SWEEPS);
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_EXPIRY);
        foreach (['vidyalms_woocommerce_sync', 'vidyalms_woocommerce_reconcile',
            'vidyalms_woocommerce_reconcile_batch', 'vidyalms_woocommerce_reconcile_tracked'] as $hook) {
            wp_unschedule_hook($hook);
        }

        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('', [], Plugin::JOB_GROUP);
            as_unschedule_all_actions('', [], 'vidyalms-woocommerce');
        }
        wp_unschedule_hook('vidyalms_upgrade_schema');
    }

    private static function deleteShellsAndTerms(): void
    {
        global $wpdb;
        // Use APIs for deletion so post metadata, revisions and relationships
        // disappear too. Query IDs directly: the CPT may not be registered.
        do {
            // Opt-in removal reads current plugin-owned records, using WordPress deletion APIs where available; no stale cache.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT 100",
                'vidyalms_course'
            ));
            foreach ($ids as $id) {
                if (!wp_delete_post((int) $id, true)) {
                    throw new \RuntimeException('Could not delete a VidyaLMS course shell.');
                }
            }
        } while (\count($ids) === 100);

        $taxonomy = 'vidyalms_course_category';
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['vidyalms_course'], ['public' => false, 'rewrite' => false]);
        }
        do {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids', 'number' => 100]);
            if (is_wp_error($terms)) {
                throw new \RuntimeException('Could not read VidyaLMS course categories.');
            }
            foreach ($terms as $id) {
                $deleted = wp_delete_term((int) $id, $taxonomy);
                if (!$deleted || is_wp_error($deleted)) {
                    throw new \RuntimeException('Could not delete a VidyaLMS course category.');
                }
            }
        } while (\count($terms) === 100);
    }

    private static function dropTables(): void
    {
        global $wpdb;

        // Foreign keys are not used by the schema, so drop order does not
        // matter; the names come from the server, not from a request.
        foreach (self::ownedTables() as $table) {
            // Opt-in removal reads current plugin-owned records, using WordPress deletion APIs where available; no stale cache.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            if ($wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $table)) === false) {
                throw new \RuntimeException('Cannot remove a VidyaLMS table.');
            }
        }
    }

    /** @return list<string> */
    private static function ownedTables(): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($wpdb->prefix . 'vidyalms_') . '%';

        /** @var list<string>|null $tables */
        // Opt-in removal reads current plugin-owned records, using WordPress deletion APIs where available; no stale cache.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        if (!\is_array($tables) || $wpdb->last_error !== '') {
            throw new \RuntimeException('Cannot list VidyaLMS tables for deletion.');
        }

        $owned = [];
        foreach ($tables as $table) {
            if (!\is_string($table) || !str_starts_with($table, $wpdb->prefix . 'vidyalms_')
                || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $owned[] = $table;
        }
        return $owned;
    }

    private static function deleteOptions(): void
    {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        self::deleteCommerceOptions();
    }

    /** Only these order-keyed option families belong to the access bridge. */
    private static function deleteCommerceOptions(): void
    {
        global $wpdb;
        foreach (['vidyalms_woocommerce_snapshot_', 'vidyalms_woocommerce_paid_', 'vidyalms_woocommerce_erased_'] as $prefix) {
            $after = '';
            do {
                // Opt-in removal reads current plugin-owned records, using WordPress deletion APIs where available; no stale cache.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $names = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 100",
                    $wpdb->esc_like($prefix) . '%', $after
                ));
                if (!\is_array($names)) {
                    throw new \RuntimeException('Could not read VidyaLMS shop access options.');
                }
                foreach ($names as $name) {
                    $after = (string) $name;
                    if (preg_match('/^vidyalms_woocommerce_(snapshot|paid|erased)_[1-9][0-9]*$/D', $after)) {
                        delete_option($after);
                    }
                }
            } while (\count($names) === 100);
        }
    }

    /**
     * The cache port falls back to transients when the site has no persistent
     * object cache, and those are rows in the options table that outlive the
     * plugin unless they are swept.
     */
    private static function deleteTransients(): void
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_vidyalms_') . '%';
        $timeoutLike = $wpdb->esc_like('_transient_timeout_vidyalms_') . '%';

        // Opt-in removal reads current plugin-owned records, using WordPress deletion APIs where available; no stale cache.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $timeoutLike
            )
        );
    }
}
