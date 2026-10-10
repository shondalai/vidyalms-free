<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * PHPStan bootstrap. Joomla defines the JPATH_* constants in its own
 * bootstrap, which static analysis never runs; the values are irrelevant to
 * the analysis, only the fact that the constants exist and are strings.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../core/vendor/autoload.php';

\define('JPATH_ROOT', __DIR__);
\define('JPATH_BASE', JPATH_ROOT);
\define('JPATH_SITE', JPATH_ROOT);
\define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
\define('JPATH_API', JPATH_ROOT . '/api');
\define('JPATH_LIBRARIES', JPATH_ROOT . '/libraries');
\define('JPATH_PLUGINS', JPATH_ROOT . '/plugins');
\define('JPATH_CACHE', JPATH_ROOT . '/cache');
\define('JPATH_CONFIGURATION', JPATH_ROOT);
\define('JPATH_MANIFESTS', JPATH_ADMINISTRATOR . '/manifests');
\define('JPATH_THEMES', JPATH_ROOT . '/templates');
\define('JPATH_PUBLIC', JPATH_ROOT);
