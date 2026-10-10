<?php
declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Setup {
    function extension_loaded(string $name): bool { return !in_array($name, $GLOBALS['missingExtensions'], true); }
}
namespace {
    if (PHP_SAPI !== 'cli') exit(1);
    define('ABSPATH', __DIR__ . '/');
    function esc_html__($text, $domain) { return htmlspecialchars($text); }
    function esc_html($text) { return htmlspecialchars($text); }
    require dirname(__DIR__) . '/vidyalms/src/Setup/Activator.php';
    $GLOBALS['missingExtensions'] = [];
    $wpdb = new class {
        public string $version = '8.4.3';
        public function db_server_info(): string { return $this->version; }
    };
    $check = new ReflectionMethod(Shondalai\VidyaLMS\WordPress\Setup\Activator::class, 'requireEnvironment');
    foreach (['8.4.3', '5.7.8', '10.2.7-MariaDB', '5.5.5-10.11.6-MariaDB'] as $version) {
        $wpdb->version = $version;
        $check->invoke(null);
        echo "PASS accepted $version\n";
    }
    foreach (['5.7.7', '10.2.6-MariaDB', 'unknown'] as $version) {
        $wpdb->version = $version;
        try { $check->invoke(null); throw new LogicException('Unsupported database accepted'); }
        catch (RuntimeException $e) { echo "PASS refused $version\n"; }
    }
    $wpdb->version = '8.4.3';
    foreach (['mbstring', 'dom', 'gd', 'iconv', 'zlib'] as $extension) {
        $GLOBALS['missingExtensions'] = [$extension];
        try { $check->invoke(null); throw new LogicException('Missing PHP dependency accepted'); }
        catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), $extension)) throw $e;
            echo "PASS refused missing $extension\n";
        }
    }
}
