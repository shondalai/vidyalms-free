<?php

/**
 * VidyaLMS: run the WordPress schema upgrade from the command line.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * An updated plugin is never reactivated, so the upgrade runs itself on the
 * first wp-admin request: Plugin::boot() hooks upgradeSchemaIfNeeded() onto
 * admin_init, dbDelta reaches the new shape and Setup\Migrations says what the
 * existing rows mean. That is fine for a site with an administrator in front
 * of it and useless for a development site being driven from a terminal, which
 * is what this script is for.
 *
 * It calls the same method the hook calls, having first checked that the hook
 * is registered: firing admin_init here instead would run every other
 * listener the site has on a request that is not an admin request, and would
 * prove less, because the thing worth proving is that the callback wp-admin
 * fires is the callback that ran.
 *
 * Usage (from any directory):
 *   php build/upgrade.php [--wp=<wordpress root>] [--dry-run]
 *
 * Unlike smoke.php this deliberately changes the shape of the database, so it
 * carries no SchemaSeal. Point it at a development site.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "upgrade.php runs on the command line only.\n");
    exit(1);
}

// No built-in site: --wp or VIDYALMS_WORDPRESS_SITE names it, because this
// script works against whatever site it is pointed at.
$wpRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_WORDPRESS_SITE')), '/');
$dryRun = false;

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--wp=')) {
        $wpRoot = rtrim(str_replace('\\', '/', substr($argument, 5)), '/');
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/upgrade.php [--wp=<root>] [--dry-run]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, 'Not a WordPress root (pass --wp=<wordpress root> or set VIDYALMS_WORDPRESS_SITE): ' . $wpRoot . "\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/' . basename($wpRoot) . '/';
$_SERVER['SCRIPT_NAME'] ??= '/' . basename($wpRoot) . '/index.php';
$_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

define('WP_USE_THEMES', false);

require_once $wpRoot . '/wp-load.php';

use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Setup\Activator;
use Shondalai\VidyaLMS\WordPress\Setup\Migrations;

if (!function_exists('vidyalms_kernel')) {
    fwrite(STDERR, "The vidyalms plugin is not active on this site.\n");
    exit(1);
}

$recorded = Activator::recordedVersion();
$target = Activator::SCHEMA_VERSION;
$pending = Migrations::pending($recorded, $target);

echo "VidyaLMS WordPress schema upgrade\n";
echo '  site     : ' . $wpRoot . "\n";
echo '  recorded : ' . ($recorded ?? 'never recorded') . "\n";
echo '  target   : ' . $target . "\n";
echo '  migration: ' . ($pending === [] ? 'none pending' : implode(', ', $pending)) . "\n";
echo '  versions : ' . implode(', ', Migrations::versions()) . " shipped\n\n";

// The hook is what runs this on a real site. A method that works when called
// by hand and is not hooked would pass every check here and upgrade nothing.
$hooked = has_action('admin_init', [Plugin::instance(), 'upgradeSchemaIfNeeded']);

printf(
    "  %-44s %s\n",
    'upgradeSchemaIfNeeded is on admin_init',
    $hooked === false ? 'NO' : 'yes, priority ' . $hooked,
);

if ($hooked === false) {
    fwrite(STDERR, "The upgrade is not wired to admin_init; a real site would never run it.\n");
    exit(1);
}

$needsUpgrade = Activator::needsUpgrade();

printf(
    "  %-44s %s\n",
    'needsUpgrade',
    $needsUpgrade ? 'yes, ' . ($recorded ?? 'never recorded') . ' -> ' . $target
        : 'no, the site is already at ' . $target,
);

if ($dryRun) {
    echo "\n--dry-run: nothing was changed.\n";

    exit(0);
}

// Called whether or not the schema moved, because the method now does two
// things and only one of them is keyed on the version. The seeded-data
// reconciliation runs ahead of that gate: a data repair has to reach a site
// whose shape is already current and whose rows are not, which is exactly the
// site this branch used to skip.
Plugin::instance()->upgradeSchemaIfNeeded();

printf(
    "  %-44s %s\n",
    'seeded data reconciliation',
    get_option(Activator::OPTION_SEEDED_DATA_VERSION) === Activator::SEEDED_DATA_VERSION
        ? 'recorded ' . Activator::SEEDED_DATA_VERSION
        : 'NOT recorded',
);

// Asserted whether or not the schema moved. The script used to exit here when
// it had not, and that skipped the one thing the callback does outside its own
// schema gate: writing the plugin version option. A release that changes no
// table still changes the version, and an updated plugin is never reactivated,
// so a site driven from this script stayed at whatever the last activation
// wrote while the plugin on disk was several releases past it, and an update
// check and a support request both read a number that was true once.
$version = get_option(Activator::OPTION_PLUGIN_VERSION);

printf("  %-44s %s\n", 'recorded plugin version', is_string($version) && $version !== '' ? $version : 'none');

if ($version !== Plugin::VERSION) {
    fwrite(
        STDERR,
        "\nThe run did not record the plugin version " . Plugin::VERSION . ". That is the half of this upgrade"
        . " that has nothing to do with tables, and it is the half a release with no schema change depends on"
        . " entirely.\n",
    );

    exit(1);
}

if (!$needsUpgrade) {
    echo "\nThe schema was already at " . $target . " and the plugin version is recorded.\n";

    exit(0);
}

$now = Activator::recordedVersion();

printf("  %-44s %s\n", 'recorded after the run', $now ?? 'still none');

if ($now !== $target) {
    fwrite(
        STDERR,
        "\nThe upgrade did not record " . $target . ". A migration failed; the error is in the site's log,"
        . " and the next run repeats it.\n",
    );

    exit(1);
}

echo "\nThe schema is at " . $target . ". Run tooling/schema/verify.php --wp=<root> to check the shape.\n";

exit(0);
