<?php

/**
 * Symbols WordPress defines at runtime that no stub file declares.
 *
 * The time and size constants are defined inside wp_initial_constants(), so
 * they exist only once WordPress has booted and the stub generator, which
 * reads declarations, never sees them. The database result-format constants
 * are declared in wp-db.php the same way. Nothing here is a fake: every value
 * is the value WordPress itself sets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/vendor/autoload.php';

\define('MINUTE_IN_SECONDS', 60);
\define('HOUR_IN_SECONDS', 60 * 60);
\define('DAY_IN_SECONDS', 24 * 60 * 60);
\define('WEEK_IN_SECONDS', 7 * 24 * 60 * 60);
\define('MONTH_IN_SECONDS', 30 * 24 * 60 * 60);
\define('YEAR_IN_SECONDS', 365 * 24 * 60 * 60);

\define('KB_IN_BYTES', 1024);
\define('MB_IN_BYTES', 1024 * 1024);
\define('GB_IN_BYTES', 1024 * 1024 * 1024);

// Only the type matters to the analyser: every use concatenates a path onto
// it, and WordPress sets the real value from wp-config.php at boot.
\define('WP_CONTENT_DIR', '/wp-content');

\define('OBJECT', 'OBJECT');
\define('OBJECT_K', 'OBJECT_K');
\define('ARRAY_A', 'ARRAY_A');
\define('ARRAY_N', 'ARRAY_N');
