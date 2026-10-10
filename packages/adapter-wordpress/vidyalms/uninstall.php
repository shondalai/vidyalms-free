<?php

declare(strict_types=1);

/**
 * VidyaLMS: what WordPress runs when the plugin is deleted.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

// WordPress defines this only for a genuine delete, and this file is never
// reached any other way.
defined('WP_UNINSTALL_PLUGIN') || exit;
defined('ABSPATH') || exit;

/*
 * The main plugin file is deliberately not included: it would register hooks
 * and boot an engine for a plugin that is being removed. Only the classes
 * the uninstall needs are loaded, and none of them touches the core, so the
 * generated core loader does not have to exist any more either.
 */
require_once __DIR__ . '/src/Plugin.php';
require_once __DIR__ . '/src/Setup/Capabilities.php';
require_once __DIR__ . '/src/Setup/Settings.php';
require_once __DIR__ . '/src/Setup/Activator.php';
require_once __DIR__ . '/src/Setup/Sites.php';
require_once __DIR__ . '/src/Files/PrivateStorageOwnership.php';
require_once __DIR__ . '/core/Infrastructure/Storage/LocalStorageSafety.php';
require_once __DIR__ . '/core/Infrastructure/Storage/AutomaticPrivateStorage.php';
require_once __DIR__ . '/src/Setup/PrivateFiles.php';
require_once __DIR__ . '/src/Setup/Uninstaller.php';

\Shondalai\VidyaLMS\WordPress\Setup\Uninstaller::uninstall();
