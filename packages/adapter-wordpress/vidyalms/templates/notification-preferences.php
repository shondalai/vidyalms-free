<?php

declare(strict_types=1);

/**
 * VidyaLMS: the learner's notification preference centre.
 *
 * NotificationPages has already prepared the sign-in gate for a guest and, on
 * a POST, already replaced the caller's rows, so this file only renders.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\NotificationPages;
use Shondalai\VidyaLMS\WordPress\Content\Templates;
use Shondalai\VidyaLMS\WordPress\Content\LearnerLayout;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
LearnerLayout::begin('preferences');
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-preferences">
    <header class="vidyalms-page-header">
        <p class="vy-page-kicker"><?php esc_html_e('Make it yours', 'vidyalms'); ?></p>
        <h1 class="vidyalms-page-title"><?php esc_html_e('Notification preferences', 'vidyalms'); ?></h1>
    </header>

    <?php Templates::output('part-notification-preferences', NotificationPages::context()); ?>
</main>
<?php
LearnerLayout::end();
Templates::pageFooter();
