<?php

declare(strict_types=1);

/**
 * VidyaLMS: public certificate verification.
 *
 * The certificate link shows verification state without search indexing or
 * caching, so a revocation cannot remain displayed as valid in a page cache.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\Rewrites;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-certificate">
    <header class="vidyalms-page-header">
        <h1 class="vidyalms-page-title"><?php esc_html_e('Verify a certificate', 'vidyalms'); ?></h1>
    </header>

    <?php
    Templates::output(
        'part-certificate',
        Templates::certificateContext((string) get_query_var(Rewrites::QUERY_CODE, '')),
    );
    ?>
</main>
<?php
Templates::pageFooter();
