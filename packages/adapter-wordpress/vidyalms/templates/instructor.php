<?php

declare(strict_types=1);

/**
 * VidyaLMS: the instructor workbench page (/teach).
 *
 * A theme may replace this file with vidyalms/instructor.php of its own, or
 * replace only the mount with vidyalms/part-instructor.php.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

use Shondalai\VidyaLMS\WordPress\Content\InstructorPage;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

defined( 'ABSPATH' ) || exit;

Templates::pageHeader();
?>
<main id="vidyalms-content" class="vidyalms vidyalms-page vidyalms-page-instructor">
    <?php Templates::output('part-instructor', InstructorPage::context()); ?>
</main>
<?php
Templates::pageFooter();
