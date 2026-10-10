<?php

declare(strict_types=1);

/**
 * VidyaLMS: the learner dashboard page.
 *
 * A theme may replace this file with vidyalms/my-learning.php of its own, or
 * replace only the dashboard with vidyalms/part-my-learning.php.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Templates;
use Shondalai\VidyaLMS\WordPress\Content\LearnerLayout;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
LearnerLayout::begin('mylearning');
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-my-learning">
    <header class="vidyalms-page-header">
        <p class="vy-page-kicker"><?php esc_html_e('Make room for your next chapter', 'vidyalms'); ?></p>
        <h1 class="vidyalms-page-title"><?php esc_html_e('My learning', 'vidyalms'); ?></h1>
        <?php if (is_user_logged_in()) : ?>
            <p class="vy-page-description"><?php esc_html_e('Your courses, your progress, and a clear next step.', 'vidyalms'); ?></p>
        <?php endif; ?>
    </header>

    <?php Templates::output('part-my-learning', Templates::myLearningContext()); ?>
</main>
<?php
LearnerLayout::end();
Templates::pageFooter();
