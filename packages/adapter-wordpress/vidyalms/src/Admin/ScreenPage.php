<?php

declare(strict_types=1);

/**
 * VidyaLMS: one wp-admin page that is one React mount.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

defined('ABSPATH') || exit;

/**
 * A screen from the typed admin catalogue: the WordPress page frame (notices
 * and the engine notice when the plugin failed to boot) around one mount div.
 * The mounted shell owns the visible page heading; the PHP fallback supplies
 * one only when that shell cannot mount.
 *
 * The capability on each page is the one WordPress needs to decide whether to
 * draw the menu entry. It is not the rule: what the caller may read or write
 * is the core's answer to each API request, and the app renders the permission
 * states from those 403s. A capability check here that went further would be a
 * second copy of a rule that already lives in one place.
 */
final class ScreenPage
{
    public function __construct(
        private readonly string $screen,
        private readonly string $capability,
        private readonly string $title,
    ) {
    }

    public function render(): void
    {
        if ($this->screen === 'dashboard' && !current_user_can(Menu::CAP_MANAGE)
            && current_user_can(Menu::CAP_ACCESS)) {
            (new self('courses', Menu::CAP_ACCESS, __('Courses', 'vidyalms')))->render();
            return;
        }
        if (!current_user_can($this->capability)) {
            wp_die(
                esc_html__('You do not have permission to view this VidyaLMS screen.', 'vidyalms'),
                '',
                ['response' => 403]
            );
        }

        echo '<div class="wrap vidyalms-wrap">';
        Menu::renderNotices();

        if (Menu::kernel() === null) {
            echo '<h1 class="wp-heading-inline">' . esc_html($this->title) . '</h1>';
            echo '<hr class="wp-header-end">';
            Menu::renderKernelNotice();
            echo '</div>';

            return;
        }

        AdminApps::renderScreen($this->screen);

        echo '</div>';
    }
}
