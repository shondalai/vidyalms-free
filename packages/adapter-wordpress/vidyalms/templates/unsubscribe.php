<?php

declare(strict_types=1);

/**
 * VidyaLMS: the unsubscribe landing page.
 *
 * A GET renders the confirmation form and acts on nothing, because a mail
 * security scanner following the link would otherwise unsubscribe the person
 * it was protecting. The POST is the RFC 8058 one-click endpoint and the
 * human form at once; NotificationPages has already run it.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\NotificationPages;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-unsubscribe">
    <header class="vidyalms-page-header">
        <h1 class="vidyalms-page-title"><?php esc_html_e('Unsubscribe', 'vidyalms'); ?></h1>
    </header>

    <?php Templates::output('part-unsubscribe', NotificationPages::context()); ?>
</main>
<?php
Templates::pageFooter();
