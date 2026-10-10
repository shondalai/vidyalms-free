<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * The packager helpers, shared by every VidyaLMS Joomla packager.
 *
 * build/build.php (pkg_vidyalms) and packages/pro-joomla/build/build.php
 * (pkg_vidyalms_pro) require this file. Neither owns a copy: two packagers
 * that each carry their own zip writer, their own exclude list and their own
 * manifest restamper drift, and a release then differs between the free and
 * the Pro lane for reasons nobody can see in a diff.
 *
 * Nothing here knows which package is being built. Every function is either
 * pure or writes only where the caller points it, and the two globals
 * ($warnings and $skipped) are declared by the calling script, which is what
 * makes warn() and the --strict gate work the same way in both.
 *
 * Not a Joomla file: it runs before anything is installed, so no _JEXEC guard.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "packager.php runs on the command line only.\n");
    exit(1);
}

const EXCLUDED_NAMES = ['.git', '.gitignore', '.gitkeep', '.gitattributes', '.idea', '.vscode', 'node_modules', '.DS_Store', 'Thumbs.db'];

const INI_BLOCK_BEGIN = '; >>> BEGIN VIDYALMS GENERATED CORE STRINGS';
const INI_BLOCK_END   = '; <<< END VIDYALMS GENERATED CORE STRINGS';

require_once __DIR__ . '/php-distribution.php';

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
    $files = [];

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if (in_array($item->getFilename(), EXCLUDED_NAMES, true)) {
            continue;
        }

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

/**
 * @param array<string, string> $extraFiles zip entry name => absolute source path
 * @param array<string, string> $extraContents zip entry name => literal content
 */
function makeZip(string $zipPath, ?string $sourceDirectory, array $extraFiles = [], array $extraContents = []): int
{
    if (is_file($zipPath) && !unlink($zipPath)) {
        fail('Cannot replace ' . slashes($zipPath));
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('Cannot create ' . slashes($zipPath));
    }

    $entries = 0;

    if ($sourceDirectory !== null) {
        foreach (fileList($sourceDirectory) as $relative) {
            // An entry supplied by content wins, so a caller can substitute a
            // rewritten manifest without shipping the file twice.
            if (isset($extraContents[$relative])) {
                continue;
            }

            // Script and stylesheet source maps stay out of both editions,
            // whatever directory they were copied into (php-distribution.php
            // says why, and why font encoding maps are not among them).
            if (!joomlaDistributionShips($relative)) {
                continue;
            }

            if (str_ends_with($relative, '.php') && !str_starts_with($relative, 'pdf-runtime/')) {
                $zip->addFromString($relative, joomlaDistributionPhp((string) file_get_contents($sourceDirectory . '/' . $relative), $relative));
            } elseif (str_ends_with($relative, '.js') || str_ends_with($relative, '.css')) {
                // The comment naming the dropped map is removed from the zipped
                // copy only; the bundle on disk stays what the UI build wrote.
                $body = (string) file_get_contents($sourceDirectory . '/' . $relative);
                $shipped = joomlaDistributionAsset($body, $relative);
                $shipped === $body
                    ? $zip->addFile($sourceDirectory . '/' . $relative, $relative)
                    : $zip->addFromString($relative, $shipped);
            } else {
                $zip->addFile($sourceDirectory . '/' . $relative, $relative);
            }
            $entries++;
        }
    }

    foreach ($extraFiles as $entry => $absolute) {
        if (!joomlaDistributionShips($entry)) {
            continue;
        }

        if (str_ends_with($entry, '.php')) {
            $zip->addFromString($entry, joomlaDistributionPhp((string) file_get_contents($absolute), $entry));
        } else {
            $zip->addFile($absolute, $entry);
        }
        $entries++;
    }

    foreach ($extraContents as $entry => $content) {
        $zip->addFromString($entry, joomlaDistributionPhp($content, $entry));
        $entries++;
    }

    if (!$zip->close()) {
        fail('Cannot write ' . slashes($zipPath));
    }

    return $entries;
}

/**
 * Returns the extension manifest of a child extension rewritten to the release
 * version, keyed by its path inside the zip, or an empty array when there is
 * nothing to change. A child directory holds exactly one manifest: the XML at
 * its root carrying an <extension> element with a <version>.
 *
 * @return array<string, string>
 */
function restampManifest(string $sourceDirectory, string $version): array
{
    foreach (glob($sourceDirectory . '/*.xml') ?: [] as $candidate) {
        $body = (string) file_get_contents($candidate);

        if (!str_contains($body, '<extension') || preg_match('/<version>([^<]*)<\/version>/', $body, $found) !== 1) {
            continue;
        }

        if (trim($found[1]) === $version) {
            return [];
        }

        $rewritten = preg_replace(
            '/<version>[^<]*<\/version>/',
            '<version>' . $version . '</version>',
            $body,
            1,
        );

        if ($rewritten === null) {
            fail('Cannot rewrite the version in ' . slashes($candidate));
        }

        return [basename($candidate) => $rewritten];
    }

    return [];
}

/**
 * Returns media/joomla.asset.json rewritten so every "version" member carries
 * the release version, keyed by its path inside the zip, or an empty array
 * when the file is absent or already current. The app bundles ship with
 * unhashed file names, so this version is what cache-busts them; like the
 * manifests, the rewrite happens on the copy inside the zip only.
 *
 * @return array<string, string>
 */
function restampAssetJson(string $sourceDirectory, string $version): array
{
    $assetJson = $sourceDirectory . '/media/joomla.asset.json';

    if (!is_file($assetJson)) {
        return [];
    }

    $body = (string) file_get_contents($assetJson);

    $rewritten = preg_replace(
        '/("version"\s*:\s*")[^"]*(")/',
        '${1}' . $version . '${2}',
        $body,
    );

    if (!\is_string($rewritten) || $rewritten === $body) {
        return [];
    }

    return ['media/joomla.asset.json' => $rewritten];
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
 * Rebuild the marked generated block inside a hand-written INI file. Everything
 * outside the markers is left exactly as the component author wrote it, and a
 * catalogue the component has not written yet is never created here.
 */
function mergeGeneratedIni(string $targetIni, string $generated, string $sourceLabel): void
{
    $block = INI_BLOCK_BEGIN . "\n"
        . '; Compiled from ' . $sourceLabel . " by build/build.php.\n"
        . "; Everything between these markers is rebuilt on every build; edit the catalogue, not this block.\n"
        . rtrim($generated) . "\n"
        . INI_BLOCK_END;

    $existing = (string) file_get_contents($targetIni);

    $pattern = '/\R*' . preg_quote(INI_BLOCK_BEGIN, '/') . '.*?' . preg_quote(INI_BLOCK_END, '/') . '\R*/s';
    $stripped = preg_replace($pattern, "\n", $existing);

    if (!is_string($stripped)) {
        fail('Cannot rewrite the generated block in ' . slashes($targetIni));
    }

    file_put_contents($targetIni, rtrim($stripped) . "\n\n" . $block . "\n");
}
