<?php

declare(strict_types=1);

/**
 * VidyaLMS: the catalogue page.
 *
 * A theme may replace this file with vidyalms/catalogue.php of its own, or
 * replace only the listing with vidyalms/part-catalogue.php.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Templates;
use Shondalai\VidyaLMS\WordPress\Content\LearnerLayout;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
LearnerLayout::begin('catalogue');
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-catalogue">
    <header class="vidyalms-page-header">
        <p class="vy-page-kicker"><?php esc_html_e('A little curiosity goes a long way', 'vidyalms'); ?></p>
        <h1 class="vidyalms-page-title"><?php esc_html_e('Course catalogue', 'vidyalms'); ?></h1>
        <p class="vy-page-description"><?php esc_html_e('Find something you want to understand. Make it something you can do.', 'vidyalms'); ?></p>
    </header>

    <?php Templates::output('part-catalogue', Templates::catalogueContext()); ?>
</main>
<?php
LearnerLayout::end();
Templates::pageFooter();
