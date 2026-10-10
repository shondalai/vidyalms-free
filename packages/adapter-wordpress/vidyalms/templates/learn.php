<?php

declare(strict_types=1);

/**
 * VidyaLMS: the learner player page (/courses/{slug}/learn).
 *
 * One mount div carrying the data-vy-config contract; the React player owns
 * everything inside it. A theme may replace this file with vidyalms/learn.php
 * of its own; it receives the same context array.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\LearnerApps;
use Shondalai\VidyaLMS\WordPress\Content\Templates;
use Shondalai\VidyaLMS\WordPress\Content\LearnerLayout;

defined( 'ABSPATH' ) || exit;

$vidyalmsContext = LearnerApps::learnContext();

if (!$vidyalmsContext['found']) {
    // An unknown or hidden course: an honest 404, not an empty player.
    status_header(404);
    nocache_headers();
}

Templates::pageHeader();
LearnerLayout::begin('mylearning');
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-learn">
    <?php if (!$vidyalmsContext['found']) : ?>
        <header class="vidyalms-page-header">
            <h1 class="vidyalms-page-title"><?php esc_html_e('Course not found', 'vidyalms'); ?></h1>
        </header>
        <p>
            <a class="vidyalms-link" href="<?php echo esc_url($vidyalmsContext['course_url']); ?>">
                <?php esc_html_e('Browse the course catalogue', 'vidyalms'); ?>
            </a>
        </p>
    <?php else : ?>
        <div id="vidyalms-player" data-vy-config="<?php echo esc_attr($vidyalmsContext['config_attribute']); ?>">
            <noscript>
                <p>
                    <?php esc_html_e('The course player needs JavaScript.', 'vidyalms'); ?>
                    <a href="<?php echo esc_url($vidyalmsContext['course_url']); ?>">
                        <?php esc_html_e('Back to the course page', 'vidyalms'); ?>
                    </a>
                </p>
            </noscript>
        </div>
    <?php endif; ?>
</main>
<?php
LearnerLayout::end();
Templates::pageFooter();
