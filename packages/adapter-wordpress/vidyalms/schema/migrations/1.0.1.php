<?php
/**
 * @package VidyaLMS
 * @license GNU General Public License version 3 or later
 */
declare(strict_types=1);
defined('ABSPATH') || exit;

// dbDelta adds the privacy-file outbox, recipient digest and organisation-unit
// column/index before this migration runs. Existing rows need no invented
// recipient hashes or tenant scope; their consumers revalidate them.
return [];
