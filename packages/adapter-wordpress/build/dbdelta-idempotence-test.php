<?php

/** Real WordPress dbDelta + wpdb, isolated in a newly created disposable database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$wp = rtrim($argv[1] ?? (string) getenv('VIDYALMS_WORDPRESS_SITE'), '/\\') . '/';
if (!is_file($wp . 'wp-admin/includes/upgrade.php')) {
    throw new RuntimeException('Supply a local WordPress source directory as the first argument.');
}
define('ABSPATH', $wp);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
require $wp . 'wp-includes/plugin.php';
require $wp . 'wp-includes/class-wpdb.php';
require $root . '/packages/core/vendor/autoload.php';
function __($text, $domain = '') { return $text; }
function is_multisite(): bool { return false; }
function absint($value): int { return abs((int) $value); }
function _doing_it_wrong($function, $message, $version): void { throw new RuntimeException($function . ': ' . $message); }
spl_autoload_register(static function (string $class): void {
    $proPrefix = 'Shondalai\\VidyaLMS\\Pro\\WordPress\\';
    if (str_starts_with($class, $proPrefix)) {
        require dirname(__DIR__, 2) . '/pro-wordpress/vidyalms-pro/src/' . str_replace('\\', '/', substr($class, strlen($proPrefix))) . '.php';
        return;
    }
    $prefix = 'Shondalai\\VidyaLMS\\WordPress\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/vidyalms/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

// Load the exact installed WordPress function without booting the site's
// plugins or options. Tokens preserve the function unchanged; no copied fork.
$tokens = token_get_all((string) file_get_contents($wp . 'wp-admin/includes/upgrade.php'));
$function = ''; $capturing = false; $depth = 0; $opened = false;
foreach ($tokens as $index => $token) {
    if (!$capturing && is_array($token) && $token[0] === T_FUNCTION) {
        $next = $index + 1;
        while (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) { ++$next; }
        $capturing = is_array($tokens[$next]) && $tokens[$next][1] === 'dbDelta';
    }
    if (!$capturing) { continue; }
    $function .= is_array($token) ? $token[1] : $token;
    if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; $opened = true; }
    if ($token === '}') { --$depth; }
    if ($opened && $depth === 0) { break; }
}
if (!$opened || $depth !== 0) { throw new RuntimeException('Could not read WordPress dbDelta.'); }
$loader = tempnam(sys_get_temp_dir(), 'vidyalms-dbdelta-');
file_put_contents($loader, "<?php\n" . $function);
require $loader;
unlink($loader);

$configuration = (string) file_get_contents($wp . 'wp-config.php');
$connection = [];
foreach (['USER' => 'root', 'PASSWORD' => '', 'HOST' => '127.0.0.1', 'NAME' => 'mysql'] as $key => $default) {
    $value = getenv('VIDYALMS_DB_' . $key);
    if ($value === false && preg_match('/define\(\s*[\'\"]DB_' . $key . '[\'\"]\s*,\s*([\'\"])(.*?)\1\s*\)/', $configuration, $match)) {
        $value = stripcslashes($match[2]);
    }
    $connection[$key] = $value === false ? $default : $value;
}
unset($configuration, $match, $value);
$wpdb = new wpdb($connection['USER'], $connection['PASSWORD'], $connection['NAME'], $connection['HOST']);
$database = 'vidyalms_lifecycle_' . bin2hex(random_bytes(8));
if ($wpdb->query($wpdb->prepare('CREATE DATABASE %i', $database)) === false) {
    throw new RuntimeException('Cannot create a disposable database: ' . $wpdb->last_error);
}
try {
    $wpdb = new wpdb($connection['USER'], $connection['PASSWORD'], $database, $connection['HOST']);
    $wpdb->set_prefix('probe_');
    $statements = Shondalai\VidyaLMS\WordPress\Setup\Activator::statements();
    $job = end($statements);
    $proStatements = Shondalai\VidyaLMS\Pro\WordPress\Setup\Schema::statements();
    dbDelta(Shondalai\VidyaLMS\Pro\WordPress\Setup\Schema::forComparison($proStatements));
    dbDelta(Shondalai\VidyaLMS\WordPress\Setup\SchemaShape::forComparison($statements));
    $statements = array_merge($statements, $proStatements);
    Shondalai\VidyaLMS\WordPress\Setup\SchemaShape::assertInstalled($statements);
    $alters = [];
    add_filter('query', static function ($query) use (&$alters) {
        if (preg_match('/^ALTER TABLE/i', $query)) { $alters[] = $query; }
        return $query;
    });
    $changes = dbDelta(Shondalai\VidyaLMS\WordPress\Setup\SchemaShape::forComparison($statements));
    if ($changes !== [] || $alters !== []) {
        throw new RuntimeException('Repeated install churn: ' . json_encode(['changes' => $changes, 'alters' => $alters]));
    }
    // A genuine change must still get through, followed by another stable pass.
    $changed = str_replace('`job_type` varchar(100)', '`job_type` varchar(110)', $job);
    dbDelta(Shondalai\VidyaLMS\WordPress\Setup\SchemaShape::forComparison([$changed]));
    if (count($alters) !== 1 || !str_contains($alters[0], 'varchar(110)')) {
        throw new RuntimeException('A genuine column change was suppressed.');
    }
    $alters = [];
    if (dbDelta(Shondalai\VidyaLMS\WordPress\Setup\SchemaShape::forComparison([$changed])) !== [] || $alters !== []) {
        throw new RuntimeException('The upgraded column churns on a repeated pass.');
    }
    // Contention from a second real connection must not enter the work block.
    $second = new wpdb($connection['USER'], $connection['PASSWORD'], $database, $connection['HOST']);
    $second->set_prefix('probe_');
    $first = $wpdb;
    Shondalai\VidyaLMS\WordPress\Setup\SchemaLock::run(static function () use ($first, $second): void {
        global $wpdb;
        $wpdb = $second;
        try {
            if (Shondalai\VidyaLMS\WordPress\Setup\SchemaLock::run(static function (): void { throw new LogicException('Concurrent installer entered'); })) {
                throw new RuntimeException('Second connection acquired held schema lock.');
            }
        } finally { $wpdb = $first; }
    });
    echo 'PASS: ' . count($statements) . ' tables on ' . $wpdb->db_server_info()
        . "; fresh shape verified, repeat has 0 ALTERs, real change applied, connection contention rejected.\n";
} finally {
    // Only the random database created above is ever dropped.
    if (preg_match('/^vidyalms_lifecycle_[0-9a-f]{16}$/D', $database) !== 1) { throw new LogicException('Unsafe fixture name'); }
    $wpdb->query($wpdb->prepare('DROP DATABASE %i', $database));
}
