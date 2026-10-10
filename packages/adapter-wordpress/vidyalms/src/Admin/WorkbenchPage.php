<?php

declare(strict_types=1);

/**
 * VidyaLMS: the instructor workbench inside wp-admin.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

defined('ABSPATH') || exit;

/**
 * admin.php?page=vidyalms-course&course={id}: one course and its five tabs
 * (Curriculum, Quizzes, Gradebook, Learners, Settings), or the caller's own
 * course list when the URL names no course.
 *
 * This page replaces the PHP course form. Creating, editing, publishing and
 * archiving a course all happen through the /v1 management surface now, which
 * is the same surface the Joomla workbench and any API client use, so there is
 * one code path per rule rather than one per screen.
 */
final class WorkbenchPage
{
    public function courseId(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view selector, no state changes.
        return isset($_GET['course']) ? absint(wp_unslash($_GET['course'])) : 0;
    }

    public function render(): void
    {
        // The same one gate the rest of the menu carries. Not teach alone: the
        // workbench's five tabs ride teach, grade, enrol and reports, each
        // scoped to the course by the core, so a grader of this course holds
        // no teach capability and still belongs here. The tabs the caller may
        // not use answer 403 and render the permission state.
        if (!current_user_can(Menu::CAP_ACCESS)) {
            wp_die(
                esc_html__('You do not have permission to open the VidyaLMS course workbench.', 'vidyalms'),
                '',
                ['response' => 403]
            );
        }

        echo '<div class="wrap vidyalms-wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('VidyaLMS Course', 'vidyalms') . '</h1>';
        echo '<hr class="wp-header-end">';

        Menu::renderNotices();

        if (Menu::kernel() === null) {
            Menu::renderKernelNotice();
            echo '</div>';

            return;
        }

        AdminApps::renderWorkbench($this->courseId());

        echo '</div>';
    }
}
