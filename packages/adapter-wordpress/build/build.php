<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * The vidyalms WordPress plugin packager. Plain PHP CLI, no Composer, no
 * WordPress bootstrap, Windows and Linux.
 *
 * Usage:
 *   php build/build.php [--out=<dir>] [--version=X.Y.Z] [--install-to=<plugins dir>] [--strict]
 *
 * Steps:
 *   1. compile the neutral schema and string catalogue into the plugin
 *   2. copy packages/core/src into vidyalms/core/ and vendor the PSR interfaces
 *   3. stamp the release version into a staged copy and zip it
 *   4. with --install-to, copy the staged plugin into a WordPress plugins dir
 *
 * Steps 1 and 2 write generated files into the plugin directory, which is why
 * vidyalms/core, vidyalms/vendor-psr, vidyalms/schema/schema.php,
 * vidyalms/src/I18n/catalogue.php, vidyalms/src/I18n/gettext.php and
 * vidyalms/assets/app are ignored by git. Step 3 stamps a copy under
 * build/.stage, never the committed vidyalms.php or readme.txt.
 *
 * What the free ZIP does not carry, on purpose: a PDF renderer (the Pro add-on
 * offers one through the vidyalms_pdf_port filter), the interface's editable
 * source (published in the public repository the readme links to), source
 * maps, and translation files (translate.wordpress.org serves the language
 * packs).
 */

declare(strict_types=1);

// A packager, not a WordPress file: it runs before anything is installed, so
// no ABSPATH guard.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "build.php runs on the command line only.\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ERROR: the zip extension is not loaded in " . PHP_BINARY . "\n");
    exit(1);
}

const EXCLUDED_NAMES = ['.git', '.gitignore', '.gitkeep', '.gitattributes', '.idea', '.vscode', 'node_modules', '.DS_Store', 'Thumbs.db'];
// Stay below the directory's decimal 10 MB submission limit.
const WORDPRESS_SUBMISSION_MAX_BYTES = 10000000;
// The size the package is expected to stay under now that the PDF runtime,
// the editable source copy, the source maps and the POT are gone. Crossing it
// is a warning, which --strict turns into a failure, so a regression is found
// by the build rather than by the directory review.
const WORDPRESS_PACKAGE_BUDGET_BYTES = 6000000;

/**
 * Generated folders earlier builds wrote into the plugin directory and this
 * one no longer produces. The stage step copies the whole plugin directory, so
 * a leftover from an old build would otherwise ship again.
 */
const RETIRED_GENERATED_PATHS = ['pdf-runtime', 'build-source', 'i18n', 'languages'];

/** Where the public source of the free plugin lives, linked from readme.txt. */
const PUBLIC_SOURCE_URL = 'https://github.com/shondalai/vidyalms-free';

/**
 * The PSR packages the plugin carries. WordPress bundles neither, and the core
 * type-hints both, so a site without Composer would fatal without these. Each
 * is loaded only when the site does not already have it.
 */
const PSR_PACKAGES = [
    'psr/clock' => 'Psr\\Clock\\ClockInterface',
    'psr/log'   => 'Psr\\Log\\LoggerInterface',
];

$warnings = [];
$generated = [];

function out(string $line = ''): void
{
    echo $line, "\n";
}

function step(string $title): void
{
    out();
    out('== ' . $title);
}

function fail(string $message): never
{
    fwrite(STDERR, "\nERROR: " . $message . "\n");
    exit(1);
}

function warn(string $message): void
{
    global $warnings;

    $warnings[] = $message;
    out('   WARNING: ' . $message);
}

function slashes(string $path): string
{
    return str_replace('\\', '/', $path);
}

function relative(string $path, string $root): string
{
    $path = slashes($path);
    $root = rtrim(slashes($root), '/') . '/';

    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

function humanSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return sprintf('%.1f MB', $bytes / 1048576);
    }

    if ($bytes >= 1024) {
        return sprintf('%.0f KB', $bytes / 1024);
    }

    return $bytes . ' B';
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        fail('Cannot create directory ' . slashes($path));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            unlink($path);
        }

        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}

/**
 * @return list<string> relative file paths, sorted, so a rebuild is byte-stable
 */
function fileList(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $relative = relative($item->getPathname(), $directory);

        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, EXCLUDED_NAMES, true)) {
                continue 2;
            }
        }

        if ($item->isFile()) {
            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

function copyTree(string $source, string $target): int
{
    $copied = 0;

    foreach (fileList($source) as $relative) {
        $destination = $target . '/' . $relative;
        ensureDirectory(dirname($destination));

        if (!copy($source . '/' . $relative, $destination)) {
            fail('Cannot copy ' . slashes($source . '/' . $relative));
        }

        $copied++;
    }

    return $copied;
}

function copyFile(string $source, string $target, string $label): void
{
    global $generated;

    if (!is_file($source)) {
        fail('Missing ' . $label . ': ' . slashes($source));
    }

    ensureDirectory(dirname($target));

    if (!copy($source, $target)) {
        fail('Cannot write ' . slashes($target));
    }

    $generated[] = $target;
}

function writeFile(string $target, string $contents): void
{
    global $generated;

    ensureDirectory(dirname($target));

    if (file_put_contents($target, $contents) === false) {
        fail('Cannot write ' . slashes($target));
    }

    $generated[] = $target;
}

/**
 * Removes the trailing source-map reference a bundler appends to a script or a
 * stylesheet. The maps are not shipped, so a reference left behind would point
 * at a file that does not exist and make browser developer tools report a
 * failed request on every admin page.
 *
 * Only whole comment lines are removed: "//# sourceMappingURL=..." in
 * JavaScript and "/*# sourceMappingURL=... *\/" in CSS, with the legacy "@"
 * marker as well. Nothing inside the code itself is touched.
 */
function stripSourceMappingUrl(string $contents): string
{
    $stripped = preg_replace(
        '~^[ \t]*(?://[#@][ \t]*sourceMappingURL=[^\r\n]*|/\*[#@][ \t]*sourceMappingURL=[^\r\n]*?\*/)[ \t]*(?:\r?\n|$)~m',
        '',
        $contents,
        -1,
        $count,
    );

    if (!is_string($stripped)) {
        fail('Cannot strip a source map reference: ' . preg_last_error_msg());
    }

    // The reference is the last line, so removing it leaves the line break
    // before it dangling; end the file with exactly one instead.
    return $count === 0 ? $contents : rtrim($stripped, "\r\n") . "\n";
}

/**
 * Deletes every source map under a directory and strips the map references
 * from its scripts and stylesheets, so the result carries no dangling link.
 *
 * @return array{0: int, 1: int} maps removed, files rewritten
 */
function dropSourceMaps(string $directory): array
{
    $removed = 0;
    $rewritten = 0;

    foreach (fileList($directory) as $relative) {
        $path = $directory . '/' . $relative;

        if (str_ends_with($relative, '.map')) {
            if (!unlink($path)) {
                fail('Cannot remove ' . slashes($path));
            }

            $removed++;
            continue;
        }

        if (!str_ends_with($relative, '.js') && !str_ends_with($relative, '.css')) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (!str_contains($contents, 'sourceMappingURL')) {
            continue;
        }

        $stripped = stripSourceMappingUrl($contents);

        if ($stripped !== $contents) {
            if (file_put_contents($path, $stripped) === false) {
                fail('Cannot write ' . slashes($path));
            }

            $rewritten++;
        }
    }

    return [$removed, $rewritten];
}

/**
 * WordPress requires the plugin folder as the top-level entry of the zip, so
 * every path inside is prefixed with it.
 */
function makeZip(string $zipPath, string $sourceDirectory, string $topLevel): int
{
    if (is_file($zipPath) && !unlink($zipPath)) {
        fail('Cannot replace ' . slashes($zipPath));
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('Cannot create ' . slashes($zipPath));
    }

    $zip->addEmptyDir($topLevel);
    $entries = 0;

    foreach (fileList($sourceDirectory) as $relative) {
        $entry = $topLevel . '/' . $relative;
        if (!$zip->addFile($sourceDirectory . '/' . $relative, $entry)
            || !$zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 9)) {
            fail('Cannot compress ' . $relative);
        }
        $entries++;
    }

    if (!$zip->close()) {
        fail('Cannot write ' . slashes($zipPath));
    }

    return $entries;
}

/**
 * @param list<string> $arguments
 */
function runPhpScript(string $script, array $arguments): void
{
    if (!is_file($script)) {
        fail('Compiler not found: ' . slashes($script));
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);

    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }

    $output = [];
    $code   = 0;
    exec($command . ' 2>&1', $output, $code);

    foreach ($output as $line) {
        out('   | ' . $line);
    }

    if ($code !== 0) {
        fail(slashes($script) . ' exited with code ' . $code);
    }
}

/**
 * Runs a command line in a directory, streaming its output into the build log.
 *
 * The command is passed to the shell unquoted on purpose: on Windows, quoting
 * the program token breaks npm.cmd's own-directory resolution and it then
 * looks for npm-cli.js underneath the working directory. Callers pass fixed
 * literals only.
 */
function runCommand(string $command, string $directory): int
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $directory,
    );

    if (!\is_resource($process)) {
        return 1;
    }

    fclose($pipes[0]);

    foreach ([1, 2] as $stream) {
        foreach (explode("\n", rtrim((string) stream_get_contents($pipes[$stream]))) as $line) {
            if (trim($line) !== '') {
                out('   | ' . rtrim($line));
            }
        }

        fclose($pipes[$stream]);
    }

    return proc_close($process);
}

/**
 * Whether npm can be invoked at all. The React apps are built with it, so
 * its absence downgrades the app bundle step to a warning instead of failing
 * a PHP-only environment.
 */
function npmAvailable(): bool
{
    $probe = proc_open(
        'npm --version',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (!\is_resource($probe)) {
        return false;
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($probe) === 0;
}

/**
 * The newest mtime under the given files and directories, so a dist can be
 * compared against the sources that produce it.
 *
 * @param list<string> $paths
 */
function newestMtime(array $paths): int
{
    $newest = 0;

    foreach ($paths as $path) {
        if (is_file($path)) {
            $newest = max($newest, (int) filemtime($path));
            continue;
        }

        if (!is_dir($path)) {
            continue;
        }

        foreach (fileList($path) as $relative) {
            $newest = max($newest, (int) filemtime($path . '/' . $relative));
        }
    }

    return $newest;
}

/**
 * The symbol a vendored PSR file declares, so the generated autoloader can map
 * class name to file without guessing from the path.
 *
 * @return array{0: string, 1: string}|null fully qualified name, kind
 */
function declaredSymbol(string $file): ?array
{
    $body = (string) file_get_contents($file);

    if (preg_match('/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $body, $namespace) !== 1) {
        return null;
    }

    if (preg_match('/^\s*(?:final\s+|abstract\s+)*(class|interface|trait)\s+([A-Za-z0-9_]+)/m', $body, $symbol) !== 1) {
        return null;
    }

    return [$namespace[1] . '\\' . $symbol[2], $symbol[1]];
}

function coreAutoloaderSource(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

/**
 * VidyaLMS core autoloader. GENERATED by build/build.php: never edit.
 *
 * WordPress has no library-extension concept, so the CMS-agnostic core ships
 * inside the plugin as a build-time copy of packages/core/src, served by this
 * PSR-4 loader. The core is namespaced Shondalai\VidyaLMS\Core and takes no
 * dependency beyond the PSR interfaces in ../vendor-psr.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

defined( 'ABSPATH' ) || exit;

if (defined('VIDYALMS_CORE_PATH')) {
    return;
}

define('VIDYALMS_CORE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Shondalai\\VidyaLMS\\Core\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, $length));

    // A class name is the only thing that may become a path here.
    if (preg_match('#^[A-Za-z0-9_]+(?:/[A-Za-z0-9_]+)*$#', $relative) !== 1) {
        return;
    }

    $file = __DIR__ . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

PHP;
}

/**
 * @param array<string, array<string, string>> $packages package => (class => relative file)
 */
function psrAutoloaderSource(array $packages): string
{
    $blocks = '';

    foreach ($packages as $package => $map) {
        $root = PSR_PACKAGES[$package];
        $entries = '';

        foreach ($map as $class => $file) {
            $entries .= sprintf("            '%s' => '%s',\n", addcslashes($class, '\\'), $file);
        }

        $blocks .= sprintf(
            "\n// %s\nif (!interface_exists('%s')) {\n"
            . "    spl_autoload_register(static function (\$class) {\n"
            . "        static \$map = [\n%s        ];\n\n"
            . "        if (!isset(\$map[\$class])) {\n            return;\n        }\n\n"
            . "        \$file = __DIR__ . '/' . \$map[\$class];\n\n"
            . "        if (is_file(\$file)) {\n            require \$file;\n        }\n"
            . "    });\n}\n",
            $package,
            addcslashes($root, '\\'),
            $entries,
        );
    }

    return <<<PHP
<?php

declare(strict_types=1);

/**
 * PSR interface loader. GENERATED by build/build.php: never edit.
 *
 * WordPress bundles neither psr/clock nor psr/log, and the core type-hints
 * both, so the plugin carries the interface packages. Each block registers only
 * when the site does not already provide that package, so a site running
 * Composer keeps its own copy and nothing is ever declared twice.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   MIT (psr/clock and psr/log are MIT licensed, see the LICENSE files)
 */

defined( 'ABSPATH' ) || exit;
{$blocks}
PHP;
}

/**
 * Rewrites one header line in place, in the staged copy only.
 */
function stampHeader(string $file, string $pattern, string $replacement, string $label): void
{
    $body = (string) file_get_contents($file);

    // Only the header block, so a "Version:" inside code cannot be rewritten.
    $head = substr($body, 0, 8192);
    $tail = substr($body, 8192);

    $stamped = preg_replace($pattern, $replacement, $head, 1, $count);

    if (!is_string($stamped)) {
        fail('Cannot rewrite the ' . $label . ' in ' . slashes($file));
    }

    if ($count === 0) {
        warn('No ' . $label . ' found in ' . basename($file) . '; the build output keeps the committed value');

        return;
    }

    if (file_put_contents($file, $stamped . $tail) === false) {
        fail('Cannot write ' . slashes($file));
    }
}

function readHeaderValue(string $file, string $pattern): ?string
{
    if (!is_file($file)) {
        return null;
    }

    $head = substr((string) file_get_contents($file), 0, 8192);

    if (preg_match($pattern, $head, $found) !== 1) {
        return null;
    }

    $value = trim($found[1]);

    return $value === '' ? null : $value;
}

// ---------------------------------------------------------------------------
// Arguments and layout
// ---------------------------------------------------------------------------

$adapterDir = dirname(__DIR__);
$repoRoot   = dirname(__DIR__, 3);
$buildDir   = __DIR__;
$stagingDir = $buildDir . '/.generated';
$stageDir   = $buildDir . '/.stage';

$outDir    = $adapterDir . '/dist';
$version   = null;
$installTo = null;
$strict    = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--out=')) {
        $outDir = rtrim(substr($argument, 6), '/\\');
    } elseif (str_starts_with($argument, '--work=')) {
        $stagingDir = rtrim(substr($argument, 7), '/\\') . '/generated';
        $stageDir = rtrim(substr($argument, 7), '/\\') . '/stage';
    } elseif (str_starts_with($argument, '--version=')) {
        $version = substr($argument, 10);
    } elseif (str_starts_with($argument, '--install-to=')) {
        $installTo = rtrim(substr($argument, 13), '/\\');
    } elseif ($argument === '--strict') {
        $strict = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        out('Usage: php build/build.php [--out=<dir>] [--version=X.Y.Z] [--install-to=<plugins dir>] [--strict]');
        exit(0);
    } else {
        fail('Unknown argument: ' . $argument);
    }
}

if ($version !== null && preg_match('/^\d+\.\d+\.\d+([-.][A-Za-z0-9.]+)?$/', $version) !== 1) {
    fail('--version must look like X.Y.Z, got: ' . $version);
}

$pluginDir      = $adapterDir . '/vidyalms';
$i18nTarget     = $pluginDir . '/src/I18n';
$pluginFile     = $pluginDir . '/vidyalms.php';
$readmeFile     = $pluginDir . '/readme.txt';
$coreSrc        = $repoRoot . '/packages/core/src';
$coreAutoload   = $repoRoot . '/packages/core/vendor/autoload.php';
$coreVendor     = $repoRoot . '/packages/core/vendor';
$catalogue      = $repoRoot . '/packages/core/i18n/catalogue.php';
$schemaCompiler = $repoRoot . '/tooling/schema/compile.php';
$i18nCompiler   = $repoRoot . '/tooling/i18n/compile.php';

foreach (
    [
        'plugin directory' => $pluginDir,
        'core sources'     => $coreSrc,
    ] as $label => $required
) {
    if (!is_dir($required)) {
        fail('Missing ' . $label . ': ' . slashes($required));
    }
}

foreach (
    [
        'schema compiler'  => $schemaCompiler,
        'i18n compiler'    => $i18nCompiler,
        'string catalogue' => $catalogue,
    ] as $label => $required
) {
    if (!is_file($required)) {
        fail('Missing ' . $label . ': ' . slashes($required));
    }
}

if (!is_file($coreAutoload)) {
    fail(
        'packages/core/vendor/autoload.php is missing, and the schema compiler needs it. '
        . 'Run: composer install --working-dir=' . slashes($repoRoot . '/packages/core'),
    );
}

$headerVersion = readHeaderValue($pluginFile, '/^[ \t]*\*?[ \t]*Version:[ \t]*(.+)$/mi');
$readmeVersion = readHeaderValue($readmeFile, '/^[ \t]*Stable tag:[ \t]*(.+)$/mi');

$version ??= $headerVersion ?? $readmeVersion;

if ($version === null) {
    fail('No version: pass --version=X.Y.Z, or give vidyalms.php a "Version:" header.');
}

out('VidyaLMS WordPress packager');
out('  repository : ' . slashes($repoRoot));
out('  adapter    : ' . slashes($adapterDir));
out('  plugin     : ' . slashes($pluginDir));
out('  output     : ' . slashes($outDir));
out('  version    : ' . $version . ($headerVersion === null || $headerVersion === $version ? '' : ' (vidyalms.php says ' . $headerVersion . ')'));
out('  php        : ' . PHP_VERSION . ' at ' . slashes(PHP_BINARY));

ensureDirectory($outDir);
ensureDirectory($stagingDir);
runPhpScript($repoRoot . '/tooling/wordpress/exception-review.php', ['--verify']);

// ---------------------------------------------------------------------------
// Step 1: compile the schema and the string catalogue into the plugin
// ---------------------------------------------------------------------------

step('Step 1: compile schema and language catalogue');

$schemaStaging = $stagingDir . '/schema';
$i18nStaging   = $stagingDir . '/i18n';

removeTree($schemaStaging);
removeTree($i18nStaging);
ensureDirectory($schemaStaging);
ensureDirectory($i18nStaging);

runPhpScript($schemaCompiler, ['--out=' . $schemaStaging]);
runPhpScript($i18nCompiler, ['--out=' . $i18nStaging, '--wordpress=' . $i18nStaging . '/wordpress']);

copyFile($schemaStaging . '/wordpress/schema.php', $pluginDir . '/schema/schema.php', 'compiled WordPress schema');
out('   schema  -> ' . relative($pluginDir . '/schema/schema.php', $adapterDir));

// dbDelta reaches the new shape on its own, so what has to be checked here is
// the half it cannot do. Setup\Activator::SCHEMA_VERSION is what decides
// whether this site has an upgrade to run at all; if it still names the old
// version, a site that upgrades never runs dbDelta and never runs the
// migration either, and nothing says so.
$schemaVersion = (string) require $repoRoot . '/tooling/schema/version.php';
$schemaFloor   = (string) require $repoRoot . '/tooling/schema/floor.php';
$activator     = (string) file_get_contents($pluginDir . '/src/Setup/Activator.php');

if (preg_match("/SCHEMA_VERSION\s*=\s*'([^']+)'/", $activator, $found) !== 1) {
    fail('Cannot read SCHEMA_VERSION from ' . slashes($pluginDir . '/src/Setup/Activator.php'));
}

// A site can only upgrade from a version it already holds, and the oldest
// version any site holds is the install floor: the version of the first
// release, below which nothing shipped. dbDelta reaches the declared shape
// from any of them, so what has to hold here is that the number the activator
// records is one an install or an upgrade can genuinely have reached.
if (version_compare($schemaVersion, $schemaFloor, '<')) {
    fail(
        'tooling/schema/version.php says ' . $schemaVersion . ', which is below the install floor '
        . $schemaFloor . ' (tooling/schema/floor.php). No shipped site can be at it.',
    );
}

// The other host, asked from here on purpose. dbDelta will reach any shape, so
// nothing in this package can tell a real schema version from a typo: a
// WordPress build alone would happily stamp a number no release ever had. The
// Joomla update folder is where a version above the floor is declared to exist
// at all, so the two hosts either agree about which versions there are or this
// build stops. At the floor there is nothing to agree about, because the
// install creates it on both.
$joomlaUpdate = $repoRoot . '/packages/adapter-joomla/com_vidyalms/admin/sql/updates/mysql/'
    . $schemaVersion . '.sql';

if (version_compare($schemaVersion, $schemaFloor, '>') && !is_file($joomlaUpdate)) {
    fail(
        'tooling/schema/version.php says ' . $schemaVersion . ', which is above the install floor '
        . $schemaFloor . ' and has no update script on the Joomla host: ' . slashes($joomlaUpdate)
        . ' does not exist. A version one host cannot reach is not a version.',
    );
}

if ($found[1] !== $schemaVersion) {
    fail(
        'Setup\\Activator::SCHEMA_VERSION is ' . $found[1] . ' but tooling/schema/version.php says '
        . $schemaVersion . '. A site upgrading to ' . $schemaVersion . ' would run neither dbDelta nor'
        . ' the migrations for it.',
    );
}

$migrationDir  = $pluginDir . '/schema/migrations';
$migrationFile = $migrationDir . '/' . $schemaVersion . '.php';

out(
    '   schema  ' . $schemaVersion . ' -> Activator::SCHEMA_VERSION (floor ' . $schemaFloor . ')'
    . (is_file($migrationFile) ? ', ' . relative($migrationFile, $adapterDir) : ', no data migration'),
);

// A version file above the constant would never run: pending() stops at it.
// One below the floor would never run either, because a fresh activation
// records the floor and pending() starts above whatever is recorded.
foreach (is_dir($migrationDir) ? (array) scandir($migrationDir) : [] as $entry) {
    if (\is_string($entry) && preg_match('/^(\d+\.\d+\.\d+)\.php$/', $entry, $parts) === 1) {
        if (version_compare($parts[1], $schemaVersion, '>')) {
            fail(
                'schema/migrations/' . $entry . ' is above the schema version ' . $schemaVersion
                . ', so no site would ever run it.',
            );
        }

        if (version_compare($parts[1], $schemaFloor, '<')) {
            fail(
                'schema/migrations/' . $entry . ' is below the install floor ' . $schemaFloor
                . ', so no site would ever run it.',
            );
        }
    }
}

// The translator port resolves a core string key through catalogue.php at
// runtime and asks gettext.php for the translation, so both ship with the
// plugin and are not only build inputs. They live under src/ because they are
// code the plugin runs. No POT and no languages/ folder: the plugin is
// translated on translate.wordpress.org, which builds its template from the
// literal calls in gettext.php and delivers translations as language packs.
foreach (['catalogue.php' => 'string catalogue', 'gettext.php' => 'gettext extraction file'] as $file => $label) {
    copyFile($i18nStaging . '/wordpress/' . $file, $i18nTarget . '/' . $file, $label);
    out('   strings -> ' . relative($i18nTarget . '/' . $file, $adapterDir));
}

// Earlier builds wrote these into the plugin directory. Removing them here,
// rather than only skipping them when staging, keeps the working tree, the
// staged copy and an --install-to target identical.
foreach (RETIRED_GENERATED_PATHS as $retired) {
    $path = $pluginDir . '/' . $retired;

    if (!file_exists($path)) {
        continue;
    }

    if ($retired === 'languages') {
        // The only file a build ever put here is the POT. Anything else is
        // somebody's work, and translations now come from language packs, so
        // the build stops and says so instead of deleting it.
        if (is_file($path . '/vidyalms.pot') && !unlink($path . '/vidyalms.pot')) {
            fail('Cannot remove ' . slashes($path . '/vidyalms.pot'));
        }

        if (fileList($path) !== []) {
            fail(
                slashes($path) . ' holds files the build did not write. The plugin ships no translation files: '
                . 'translate.wordpress.org serves them as language packs. Move them out of the plugin directory.',
            );
        }
    }

    removeTree($path);
    out('   removed retired generated ' . relative($path, $adapterDir));
}

// ---------------------------------------------------------------------------
// Step 2: copy the core and vendor the PSR interfaces
// ---------------------------------------------------------------------------

step('Step 2: copy the core into vidyalms/core');

$coreTarget = $pluginDir . '/core';
removeTree($coreTarget);
ensureDirectory($coreTarget);

$copied = copyTree($coreSrc, $coreTarget);
out('   ' . $copied . ' files copied from ' . relative($coreSrc, $repoRoot));

// Before the generated autoloader is written into the same directory, so the
// comparison is against the source tree and nothing else.
assertTreesMatch($coreSrc, $coreTarget, 'packages/core/src');

// No PDF runtime. The core registers its PDF certificate renderer only when a
// PdfPort is supplied, and this plugin supplies one only through the
// vidyalms_pdf_port filter, which the Pro add-on answers with its own bundled
// renderer. Without it certificates render as HTML.

writeFile($coreTarget . '/autoload.php', coreAutoloaderSource());
out('   autoload -> ' . relative($coreTarget . '/autoload.php', $adapterDir));

$psrTarget = $pluginDir . '/vendor-psr';
removeTree($psrTarget);
ensureDirectory($psrTarget);

$psrMaps = [];

foreach (array_keys(PSR_PACKAGES) as $package) {
    $source = $coreVendor . '/' . $package . '/src';

    if (!is_dir($source)) {
        fail(
            'Missing ' . $package . ' at ' . slashes($source) . '. '
            . 'Run: composer install --working-dir=' . slashes($repoRoot . '/packages/core'),
        );
    }

    $map = [];

    foreach (fileList($source) as $relative) {
        if (!str_ends_with($relative, '.php')) {
            continue;
        }

        $target = $psrTarget . '/' . $package . '/src/' . $relative;
        ensureDirectory(dirname($target));

        if (!copy($source . '/' . $relative, $target)) {
            fail('Cannot copy ' . slashes($source . '/' . $relative));
        }

        $symbol = declaredSymbol($target);

        if ($symbol === null) {
            warn($package . '/src/' . $relative . ' declares no class, interface or trait and will not be autoloadable');
            continue;
        }

        $map[$symbol[0]] = $package . '/src/' . $relative;
    }

    // The licence file is part of the redistribution terms of both packages.
    foreach (['LICENSE', 'LICENSE.txt', 'LICENSE.md'] as $licence) {
        if (is_file($coreVendor . '/' . $package . '/' . $licence)) {
            copyFile(
                $coreVendor . '/' . $package . '/' . $licence,
                $psrTarget . '/' . $package . '/' . $licence,
                $package . ' licence',
            );
            break;
        }
    }

    if ($map === []) {
        fail('No PHP symbols found in ' . slashes($source));
    }

    ksort($map);
    $psrMaps[$package] = $map;
    out('   ' . $package . ' -> ' . count($map) . ' symbol(s)');
}

writeFile($psrTarget . '/autoload.php', psrAutoloaderSource($psrMaps));
out('   autoload -> ' . relative($psrTarget . '/autoload.php', $adapterDir));

// ---------------------------------------------------------------------------
// Step 2b: bundle the React apps (packages/ui) into vidyalms/assets/app
// ---------------------------------------------------------------------------

step('Step 2b: bundle the React apps');

$uiDir     = $repoRoot . '/packages/ui';
$uiDist    = $uiDir . '/dist-wordpress';
$appTarget = $pluginDir . '/assets/app';

// Native entry URLs are versioned by the host. All secondary chunks are
// content-hashed; validate the entire manifest graph, not historical filenames.
require_once $repoRoot . '/tooling/releases/ui-manifest.php';
$uiEntries = [];
try {
    $uiEntries = vidyalmsUiManifestFiles($uiDist);
    $distComplete = true;
} catch (RuntimeException | JsonException $error) {
    $distComplete = false;
    out('   incomplete UI bundle: ' . $error->getMessage());
}

$distStale = false;

if ($distComplete) {
    $sourcesNewest = newestMtime([
        $uiDir . '/src',
        $uiDir . '/scripts',
        $uiDir . '/package.json',
        $uiDir . '/vite.config.ts',
        $uiDir . '/tailwind.config.ts',
        $uiDir . '/postcss.config.js',
        $repoRoot . '/packages/api-sdk/src',
        $repoRoot . '/packages/api-sdk/package.json',
    ]);

    $distOldest = PHP_INT_MAX;

    foreach ($uiEntries as $entry) {
        $distOldest = min($distOldest, (int) filemtime($uiDist . '/' . $entry));
    }

    $distStale = $sourcesNewest > $distOldest;
}

if (!is_dir($uiDir)) {
    warn('packages/ui does not exist; the React apps are not shipped');
} else {
    if (!$distComplete || $distStale) {
        if (!npmAvailable()) {
            warn(
                'packages/ui/dist is ' . ($distComplete ? 'stale' : 'missing or incomplete')
                . ' and npm is not available; '
                . ($distComplete ? 'shipping the stale bundle' : 'the React apps are not shipped'),
            );
        } else {
            out('   dist is ' . ($distComplete ? 'stale' : 'missing or incomplete') . '; running npm run build');

            if (runCommand('npm run build:wordpress', $uiDir) !== 0) {
                fail('npm run build failed in ' . slashes($uiDir));
            }

            try {
                // Re-read: a rebuild can change every secondary chunk filename.
                $uiEntries = vidyalmsUiManifestFiles($uiDist);
                $distComplete = true;
            } catch (RuntimeException | JsonException $error) {
                fail('npm run build produced an incomplete UI bundle: ' . $error->getMessage());
            }
        }
    }

    removeTree($appTarget);

    if ($distComplete) {
        // A flat copy: the entries' relative ESM imports (./shared.js,
        // ./vendor.js) resolve against the same directory.
        ensureDirectory($appTarget);

        $shipped = 0;
        $mapsRead = 0;
        $mapReferences = 0;

        foreach (fileList($uiDist) as $file) {
            if (str_ends_with($file, '.js.map')) {
                // The maps stay out of the plugin: the readable source is in
                // the public repository. They are still the one record of
                // which modules went into each chunk, so they are read here to
                // prove the WordPress profile bundled no private React or
                // ReactDOM and uses the copies WordPress supplies.
                $map = json_decode((string) file_get_contents($uiDist . '/' . $file), true, 512, JSON_THROW_ON_ERROR);

                foreach (\is_array($map['sources'] ?? null) ? $map['sources'] : [] as $mapped) {
                    if (\is_string($mapped) && preg_match('~node_modules/(react|react-dom)/~', $mapped) === 1) {
                        fail('The WordPress interface bundles its own React or ReactDOM: ' . $file . ' maps ' . $mapped);
                    }
                }

                $mapsRead++;
                continue;
            }

            if (
                !str_ends_with($file, '.css')
                && !str_ends_with($file, '.js')
                && !str_ends_with($file, '.woff2')
            ) {
                continue;
            }

            if (str_ends_with($file, '.woff2')) {
                copyFile($uiDist . '/' . $file, $appTarget . '/' . $file, 'interface font');
            } else {
                // The trailing map reference would point at a file the plugin
                // does not ship.
                $contents = (string) file_get_contents($uiDist . '/' . $file);
                $stripped = stripSourceMappingUrl($contents);
                $mapReferences += $stripped === $contents ? 0 : 1;
                writeFile($appTarget . '/' . $file, $stripped);
            }

            $shipped++;
        }

        if ($mapsRead === 0) {
            warn('packages/ui/dist-wordpress has no source maps, so the React ownership check could not run');
        }

        out('   ' . $shipped . ' app files copied into ' . relative($appTarget, $adapterDir));
        out('   verified  ' . $mapsRead . ' source maps name no bundled React or ReactDOM; none are shipped');
        out('   stripped  ' . $mapReferences . ' source map references from the copied scripts and styles');

        // The bundle still contains the libraries it was compiled from, and
        // their licences travel with it, in the plugin root where a reader of
        // the ZIP finds them beside LICENSE.
        require_once __DIR__ . '/third-party-notices.php';
        writeFile($pluginDir . '/THIRD-PARTY-NOTICES.txt', vidyalmsFrontendNotices($repoRoot, PUBLIC_SOURCE_URL));
        out('   notices -> ' . relative($pluginDir . '/THIRD-PARTY-NOTICES.txt', $adapterDir));
    } else {
        // No bundle, nothing to give notice for; a stale file from an earlier
        // build must not describe an interface this build does not ship.
        removeTree($pluginDir . '/THIRD-PARTY-NOTICES.txt');
    }
}

// ---------------------------------------------------------------------------
// Step 3: stage, stamp and zip
// ---------------------------------------------------------------------------

step('Step 3: stage, stamp and zip');

if (!is_file($pluginFile)) {
    warn(
        relative($pluginFile, $adapterDir) . ' does not exist yet, so no plugin zip was built. '
        . 'The generated core, schema and language files are in place for whoever adds it.',
    );

    $packageZip = null;
} else {
    removeTree($stageDir);
    ensureDirectory($stageDir);

    $staged = $stageDir . '/vidyalms';
    $files  = copyTree($pluginDir, $staged);
    out('   staged  ' . $files . ' files into ' . relative($staged, $adapterDir));

    // Step 2b already copies the interface without maps. This sweep covers
    // everything else under assets/, hand-written scripts included, so no
    // map and no reference to one can reach the ZIP by another route.
    [$mapsRemoved, $referencesStripped] = dropSourceMaps($staged . '/assets');
    out('   assets  ' . $mapsRemoved . ' source map(s) removed, ' . $referencesStripped . ' map reference(s) stripped in the staged copy');

    // This shared alias file executes class_alias() at include time. Only the
    // WordPress distribution gets the host guard; the core stays CMS-neutral.
    $aliasFile = $staged . '/core/Registry/Notification/Audience/FiltersByVisibility.php';
    $alias = (string) file_get_contents($aliasFile);
    $alias = str_replace('declare(strict_types=1);', "declare(strict_types=1);\ndefined('ABSPATH') || exit;", $alias, $aliasGuards);
    if ($aliasGuards !== 1) fail('Expected one declaration in the shared visibility alias.');
    writeFile($aliasFile, $alias);

    stampHeader(
        $staged . '/vidyalms.php',
        '/^([ \t]*\*?[ \t]*Version:[ \t]*).+$/mi',
        '${1}' . $version,
        'plugin header version',
    );
    out('   stamped vidyalms.php   Version: ' . $version);

    // Native entry scripts and styles have stable names; their cache key is
    // VIDYALMS_VERSION, not the plugin header. Stamp both in the staged copy
    // so an upgrade cannot keep an old entry importing removed hashed chunks.
    $constantStamped = (string) file_get_contents($staged . '/vidyalms.php');
    $constantStamped = preg_replace_callback(
        "/^define\\('VIDYALMS_VERSION', '[^']*'\\);[ \\t]*\\r?$/m",
        static fn (): string => "define('VIDYALMS_VERSION', '" . $version . "');",
        $constantStamped,
        -1,
        $constantCount,
    );

    if (!is_string($constantStamped) || $constantCount !== 1) {
        fail('Expected exactly one VIDYALMS_VERSION definition in staged vidyalms.php');
    }

    if (file_put_contents($staged . '/vidyalms.php', $constantStamped) === false) {
        fail('Cannot write the staged vidyalms.php runtime version');
    }

    out('   stamped VIDYALMS_VERSION = ' . $version);

    $classStamped = (string) file_get_contents($staged . '/src/Plugin.php');
    $classStamped = preg_replace_callback(
        "/^([ \\t]*)public const VERSION = '[^']*';[ \\t]*\\r?$/m",
        static fn (array $match): string => $match[1] . "public const VERSION = '" . $version . "';",
        $classStamped,
        -1,
        $classCount,
    );

    if (!is_string($classStamped) || $classCount !== 1) {
        fail('Expected exactly one Plugin::VERSION in staged src/Plugin.php');
    }

    if (file_put_contents($staged . '/src/Plugin.php', $classStamped) === false) {
        fail('Cannot write the staged src/Plugin.php runtime version');
    }

    out('   stamped Plugin::VERSION = ' . $version);

    if (is_file($staged . '/readme.txt')) {
        stampHeader(
            $staged . '/readme.txt',
            '/^([ \t]*Stable tag:[ \t]*).+$/mi',
            '${1}' . $version,
            'readme stable tag',
        );
        out('   stamped readme.txt     Stable tag: ' . $version);
    } else {
        warn('readme.txt is missing; wordpress.org will not accept the plugin without it');
    }

    require_once $repoRoot . '/tooling/wordpress/scope-psr.php';
    out('   isolated PSR references in ' . vidyalmsScopeWordPressPsr($staged) . ' PHP files');
    $packageZip = $outDir . '/vidyalms-' . $version . '.zip';
    $entries    = makeZip($packageZip, $staged, 'vidyalms');
    out(sprintf('   %-28s %4d files %s', basename($packageZip), $entries, humanSize((int) filesize($packageZip))));
    if (filesize($packageZip) > WORDPRESS_SUBMISSION_MAX_BYTES) {
        fail('The WordPress ZIP exceeds the 10 MB (10,000,000 bytes) directory submission limit. Review packaged assets before releasing it.');
    }
    if (filesize($packageZip) > WORDPRESS_PACKAGE_BUDGET_BYTES) {
        warn(
            'The WordPress ZIP is ' . humanSize((int) filesize($packageZip)) . ', above its '
            . number_format(WORDPRESS_PACKAGE_BUDGET_BYTES) . ' byte budget. Check what grew before releasing it.',
        );
    }
}

// ---------------------------------------------------------------------------
// Step 4: install into a WordPress plugins directory
// ---------------------------------------------------------------------------

if ($installTo !== null) {
    step('Step 4: install into a WordPress plugins directory');

    if ($packageZip === null) {
        fail('Nothing to install: the plugin was not staged.');
    }

    if (!is_dir($installTo)) {
        fail('--install-to is not a directory: ' . slashes($installTo));
    }

    if (basename($installTo) !== 'plugins') {
        warn(slashes($installTo) . ' is not named "plugins"; check it is really the WordPress plugins directory');
    }

    $target = $installTo . '/vidyalms';

    // Never delete a directory that is not this plugin.
    if (is_dir($target) && !is_file($target . '/vidyalms.php') && fileList($target) !== []) {
        fail(slashes($target) . ' exists and is not a VidyaLMS plugin directory. Move it aside first.');
    }

    removeTree($target);
    ensureDirectory($target);

    $installed = copyTree($stageDir . '/vidyalms', $target);

    out('   wrote ' . $installed . ' files to ' . slashes($target));
    out('   activate it with:');
    out('     wp plugin activate vidyalms');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

step('Summary');

out('   generated ' . count($generated) . ' file(s) in the plugin directory');
out('   core      ' . $copied . ' files under ' . relative($coreTarget, $adapterDir));

foreach ($psrMaps as $package => $map) {
    out('   vendored  ' . $package . ' (' . count($map) . ' symbols)');
}

if ($packageZip !== null) {
    out('   built     ' . relative($packageZip, $repoRoot) . ' (' . humanSize((int) filesize($packageZip)) . ')');
}

out();
out(sprintf('Version %s, %d warning(s).', $version, count($warnings)));

if ($strict && $warnings !== []) {
    fail('--strict: the build finished with ' . count($warnings) . ' warning(s).');
}

exit(0);

/**
 * Asserts that a copied tree is byte for byte the tree it was copied from.
 *
 * The same rule the two Pro packagers already run, applied to the free core.
 * The copy is a build artefact living inside the plugin directory the packager
 * zips, which is exactly the shape a stale or hand-edited copy hides in: a
 * review reads it as source, a hand edit survives until the next packager run
 * overwrites it, and a copy that was never refreshed ships a version of the
 * code nobody has looked at. It runs before the generated autoloader is written
 * into the same directory, so the only thing compared is the copy.
 */
function assertTreesMatch(string $source, string $target, string $label): void
{
    $hashes = static function (string $root): array {
        $out = [];

        foreach (fileList($root) as $relative) {
            $out[$relative] = (string) hash_file('sha256', $root . '/' . $relative);
        }

        ksort($out);

        return $out;
    };

    $from = $hashes($source);
    $to = $hashes($target);

    if ($from === $to) {
        out('   verified  ' . count($from) . ' files match ' . $label . ' byte for byte');

        return;
    }

    $differences = [];

    foreach (array_keys($from + $to) as $file) {
        if (($from[$file] ?? null) !== ($to[$file] ?? null)) {
            $differences[] = (string) $file;
        }
    }

    fail(
        'The copy of ' . $label . ' does not match its source: '
        . implode(', ', array_slice($differences, 0, 5))
        . (count($differences) > 5 ? ' and ' . (count($differences) - 5) . ' more' : '')
    );
}
