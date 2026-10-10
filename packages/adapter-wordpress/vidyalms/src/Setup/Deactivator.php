<?php

declare(strict_types=1);

/**
 * VidyaLMS: deactivation, which stops work and destroys nothing.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\WordPress\Plugin;

defined('ABSPATH') || exit;

/**
 * Deactivation stops scheduled work and clears the routing WordPress cached for
 * this plugin. It removes no data, no options, no roles and no capabilities: a
 * site owner deactivating to debug a conflict must be able to reactivate and
 * find every enrolment where they left it.
 *
 * Queued rows stay in the job table. They are due work, and reactivating the
 * plugin puts the drain back on the schedule that runs them.
 */
final class Deactivator
{
    private function __construct()
    {
    }

    public static function deactivate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            Sites::each([self::class, 'deactivateSite'], get_current_network_id());
            return;
        }
        self::deactivateSite();
    }

    public static function deactivateSite(): void
    {
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_JOBS);
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_SWEEPS);
        wp_clear_scheduled_hook(Plugin::HOOK_RUN_EXPIRY);
        wp_unschedule_hook('vidyalms_upgrade_schema');
        wp_unschedule_hook(\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::RETRY_HOOK);
        wp_unschedule_hook(\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::RECONCILE_HOOK);
        wp_unschedule_hook(\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::BATCH_HOOK);
        wp_unschedule_hook(\Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::TRACKED_BATCH_HOOK);

        if (\function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('', [], Plugin::JOB_GROUP);
            as_unschedule_all_actions(
                '',
                [],
                \Shondalai\VidyaLMS\WordPress\Commerce\WooCommerce::JOB_GROUP,
            );
        }

        self::removeRewriteRules();
    }

    private static function removeRewriteRules(): void
    {
        $rewrites = 'Shondalai\\VidyaLMS\\WordPress\\Content\\Rewrites';

        if (class_exists($rewrites) && method_exists($rewrites, 'deactivate')) {
            $rewrites::deactivate();

            return;
        }

        delete_option('rewrite_rules');
    }
}
