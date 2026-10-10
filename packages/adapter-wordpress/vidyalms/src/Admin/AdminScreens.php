<?php

declare(strict_types=1);

/**
 * VidyaLMS: the published registry of screens hosted by the admin SPA.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

defined('ABSPATH') || exit;

/**
 * Builds the admin screen catalogue from immutable descriptors.
 *
 * Paid and third-party packages append an AdminScreen through the published
 * filter. The registry is intentionally stateless: every consumer observes
 * the active plugins on this request, so disabling a provider removes both
 * its menu entry and its URL from the mount contract without a cache flush.
 */
final class AdminScreens
{
    /**
     * Signature: (list<AdminScreen> $screens): list<AdminScreen>.
     */
    public const FILTER = 'vidyalms_admin_screens';

    private function __construct()
    {
    }

    /**
     * All screens served by the generic SPA page, in menu order.
     *
     * Free descriptors cannot be removed or replaced by a filter. A provider
     * may only append a new, uniquely keyed screen; a malformed contribution
     * is ignored so it cannot make the free administration area unavailable.
     *
     * @return list<AdminScreen>
     */
    public static function all(): array
    {
        $free = self::freeScreens();

        try {
            /** @var mixed $filtered */
            $filtered = apply_filters('vidyalms_admin_screens', $free);
        } catch (\Throwable) {
            return $free;
        }

        if (!\is_array($filtered)) {
            return $free;
        }

        $byKey = [];
        $slugs = [];

        foreach ($free as $screen) {
            $byKey[$screen->key] = $screen;
            $slugs[$screen->slug] = true;
        }

        foreach ($filtered as $screen) {
            if (!$screen instanceof AdminScreen) {
                continue;
            }

            // The free definitions above are the authority for their keys,
            // and the same native slug cannot safely route two SPA screens.
            if (isset($byKey[$screen->key]) || isset($slugs[$screen->slug])) {
                continue;
            }

            $byKey[$screen->key] = $screen;
            $slugs[$screen->slug] = true;
        }

        $screens = array_values($byKey);

        usort(
            $screens,
            static fn (AdminScreen $left, AdminScreen $right): int =>
                [$left->position, $left->key] <=> [$right->position, $right->key]
        );

        return $screens;
    }

    public static function find(string $key): ?AdminScreen
    {
        foreach (self::all() as $screen) {
            if ($screen->key === $key) {
                return $screen;
            }
        }

        return null;
    }

    /**
     * The native URL for every screen this install currently serves.
     *
     * @return array<string, string>
     */
    public static function urls(): array
    {
        $urls = [];

        foreach (self::all() as $screen) {
            if (current_user_can($screen->capability)) {
                $urls[$screen->key] = Menu::url($screen->slug);
            }
        }

        return $urls;
    }

    /**
     * @return list<AdminScreen>
     */
    private static function freeScreens(): array
    {
        return [
            new AdminScreen('categories', 'vidyalms-categories',
                __('VidyaLMS Categories', 'vidyalms'), __('Categories', 'vidyalms'), Menu::CAP_ACCESS, 25),
            new AdminScreen('organisations', Menu::SLUG_ORGANISATIONS,
                __('VidyaLMS Organisations', 'vidyalms'), __('Organisations', 'vidyalms'), Menu::CAP_ORGANISATIONS, 55),
            new AdminScreen('reports', Menu::SLUG_REPORTS,
                __('VidyaLMS Reports', 'vidyalms'), __('Reports', 'vidyalms'), Menu::CAP_ACCESS, 80),
            new AdminScreen('operations', Menu::SLUG_OPERATIONS,
                __('VidyaLMS Operations', 'vidyalms'), __('Operations', 'vidyalms'), Menu::CAP_MANAGE, 85),
            new AdminScreen('import', Menu::SLUG_IMPORT_CENTER,
                __('VidyaLMS Import center', 'vidyalms'), __('Import center', 'vidyalms'), Menu::CAP_MANAGE, 87),
            new AdminScreen(
                'dashboard',
                Menu::SLUG_ROOT,
                __('VidyaLMS Dashboard', 'vidyalms'),
                __('Dashboard', 'vidyalms'),
                Menu::CAP_MANAGE,
                10,
            ),
            new AdminScreen(
                'courses',
                Menu::SLUG_COURSES,
                __('VidyaLMS Courses', 'vidyalms'),
                __('Courses', 'vidyalms'),
                Menu::CAP_ACCESS,
                20,
            ),
            new AdminScreen(
                'enrolments',
                Menu::SLUG_ENROLMENTS,
                __('VidyaLMS Enrolments', 'vidyalms'),
                __('Enrolments', 'vidyalms'),
                Menu::CAP_ACCESS,
                40,
            ),
            new AdminScreen(
                'learners',
                Menu::SLUG_LEARNERS,
                __('VidyaLMS Learners', 'vidyalms'),
                __('Learners', 'vidyalms'),
                Menu::CAP_ACCESS,
                50,
            ),
            new AdminScreen(
                'certificates',
                Menu::SLUG_CERTIFICATES,
                __('VidyaLMS Certificates', 'vidyalms'),
                __('Certificates', 'vidyalms'),
                Menu::CAP_ACCESS,
                60,
            ),
            new AdminScreen(
                'notifications',
                Menu::SLUG_NOTIFICATIONS,
                __('VidyaLMS Notifications', 'vidyalms'),
                __('Notifications', 'vidyalms'),
                Menu::CAP_ACCESS,
                70,
            ),
            new AdminScreen(
                'settings',
                Menu::SLUG_SETTINGS,
                __('VidyaLMS Settings', 'vidyalms'),
                __('Settings', 'vidyalms'),
                Menu::CAP_ACCESS,
                90,
            ),
            new AdminScreen(
                'email-templates',
                Menu::SLUG_EMAIL_TEMPLATES,
                __('VidyaLMS Email templates', 'vidyalms'),
                __('Email templates', 'vidyalms'),
                Menu::CAP_ACCESS,
                75,
            ),
            new AdminScreen(
                'automations',
                Menu::SLUG_AUTOMATIONS,
                __('VidyaLMS Automations', 'vidyalms'),
                __('Automations', 'vidyalms'),
                Menu::CAP_MANAGE,
                78,
            ),
            /*
             * The AI screen. Free on every build and gated on manage: the run
             * log, the spend and the data-use policy are the three things a
             * site owner reads and nobody else should.
             */
            new AdminScreen(
                'ai',
                Menu::SLUG_AI,
                __('VidyaLMS AI', 'vidyalms'),
                __('AI', 'vidyalms'),
                Menu::CAP_MANAGE,
                80,
            ),
        ];
    }
}
