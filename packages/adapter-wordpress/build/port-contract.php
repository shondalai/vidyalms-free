<?php

/**
 * VidyaLMS: the shared database port contract, against WpDatabase.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * Runs the shared database port contract (tooling/contract) against
 * WpDatabase on a real WordPress site and a real MySQL connection.
 *
 * The core proves the same assertions against its SQLite harness in
 * tests/Integration/Port/DatabasePortContractTest.php. That is the only place
 * they can run without a CMS, and it is exactly why it is not enough: an
 * aggregate, a CASE, a rate that answers NULL on an empty domain and a
 * "nulls last" ordering MySQL has no syntax for are all things this adapter
 * compiles itself, and SQLite is forgiving where MySQL is not. On this host
 * there is a second reason: $wpdb->prepare() has only positional
 * placeholders, so every fragment must be built in the order its arguments
 * are appended, and a rate compiles its domain twice.
 *
 * Usage (from any directory):
 *   php build/port-contract.php [--wp=<wordpress root>]
 *
 * It writes nothing that survives: every row goes in inside a transaction the
 * contract deliberately rolls back, and the run then checks that the rollback
 * actually happened rather than assuming it. That check matters more here
 * than anywhere: the tables have to be InnoDB, and on MyISAM the transaction
 * statements are accepted and do nothing.
 *
 * It creates no table and drops none, and a SchemaSeal over the site's table
 * list says so out loud.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "port-contract.php runs on the command line only.\n");
    exit(1);
}

// No built-in site: --wp or VIDYALMS_WORDPRESS_SITE names it, because this
// script works against whatever site it is pointed at.
$wpRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_WORDPRESS_SITE')), '/');

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--wp=')) {
        $wpRoot = rtrim(str_replace('\\', '/', substr($argument, 5)), '/');
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/port-contract.php [--wp=<root>]\n";
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

// WordPress reads the request environment even on the command line.
$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/' . basename($wpRoot) . '/';
$_SERVER['SCRIPT_NAME'] ??= '/' . basename($wpRoot) . '/index.php';
$_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

define('WP_USE_THEMES', false);

require_once __DIR__ . '/../../../tooling/fixtures/bootstrap.php';
require_once $wpRoot . '/wp-load.php';

// After wp-load.php and not before it. The contract bootstrap loads by require
// and not by autoload, and several of its files extend a core class
// (SmokeOrganisationRosterImporter extends Registry\Importer\AbstractImporter).
// The core is autoloaded by the plugin's own vendor map, which is registered
// while WordPress loads plugins, so requiring the contracts first resolves the
// parent through an autoloader that has never heard of it.
require_once __DIR__ . '/../../../tooling/contract/bootstrap.php';

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;
use Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract;
use Shondalai\VidyaLMS\Tooling\Contract\HttpPortContract;
use Shondalai\VidyaLMS\Tooling\Contract\SecretsPortContract;
use Shondalai\VidyaLMS\Tooling\Fixtures\SchemaSeal;
use Shondalai\VidyaLMS\WordPress\Plugin;

if (!function_exists('vidyalms_kernel')) {
    fwrite(STDERR, "The vidyalms plugin is not active on this site.\n");
    exit(1);
}

$seal = new SchemaSeal(
    'wordpress port contract',
    static fn (): array => array_map('strval', (array) $GLOBALS['wpdb']->get_col('SHOW TABLES')),
);
$seal->verifyOnShutdown();

$database = Plugin::database();

if (!$database instanceof DatabasePort) {
    fwrite(STDERR, "The VidyaLMS database port is not available on this site.\n");
    exit(1);
}

echo "VidyaLMS database port contract, WordPress\n";
echo '  site:           ' . $wpRoot . "\n";
echo '  implementation: ' . \get_class($database) . "\n\n";

$databaseFailures = DatabasePortContract::failures($database);

foreach ($databaseFailures as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($databaseFailures === []) {
    echo "  [PASS] WpDatabase satisfies the database port contract.\n";
}

// The two ports the commerce wave added. They shipped with no conformance
// contract at all, so each adapter decided for itself what an allowlist entry
// means, how a timeout is clamped and what a transport failure is called. The
// checks make no outbound call and read no configured secret: every assertion
// is about a refusal.
echo "\nVidyaLMS HTTP and secrets port contracts, WordPress\n";

// The port bundle rather than the whole kernel: a kernel needs the registries,
// the settings and a booted plugin, and the two ports under test need none of
// that.
/** @var mixed $ports */
$ports = Plugin::ports();
$http = $ports instanceof Ports ? $ports->http : null;
$secrets = $ports instanceof Ports ? $ports->secrets : null;

$httpFailures = $http instanceof HttpPort
    ? HttpPortContract::failures($http)
    : ['The VidyaLMS HTTP port is not available on this site.'];
$secretsFailures = $secrets instanceof SecretsPort
    ? SecretsPortContract::failures($secrets)
    : ['The VidyaLMS secrets port is not available on this site.'];

foreach ([...$httpFailures, ...$secretsFailures] as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($httpFailures === []) {
    echo '  [PASS] ' . \get_class($http) . " satisfies the HTTP port contract.\n";
}

if ($secretsFailures === []) {
    echo '  [PASS] ' . \get_class($secrets) . " satisfies the secrets port contract.\n";
}

$failures = [...$databaseFailures, ...$httpFailures, ...$secretsFailures];

echo "\n" . ($failures === []
    ? "All three contracts hold on MySQL.\n"
    : sprintf("%d expectation(s) broken.\n", \count($failures)));

exit($failures === [] ? 0 : 1);
