<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\WordPress\Files\PrivateStorageOwnership;

defined('ABSPATH') || exit;

/** Uninstall-only cleanup; never follows links or erases an arbitrary custom root. */
final class PrivateFiles
{
    public static function deleteForSite(): void
    {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            throw new \RuntimeException('Cannot locate VidyaLMS files for deletion.');
        }
        // Resolve the server-selected uploads boundary once, then reject every
        // link below it. Deployment links must not weaken child-file checks.
        $uploads['basedir'] = PrivateStorageOwnership::canonicalRoot($uploads['basedir']);
        $legacy = $uploads['basedir'] . '/vidyalms-private';
        if ((defined(Settings::PRIVATE_DIR_CONSTANT) && constant(Settings::PRIVATE_DIR_CONSTANT) !== null)
            || get_option('vidyalms_automatic_private_storage', null) !== null) {
            $configured = Settings::privateStorageDir();
            if ($configured === 'vidyalms-private') {
                throw new \RuntimeException('Cannot verify the automatic private storage location; restore its configuration before deleting data.');
            }
            $root = PrivateStorageOwnership::canonicalRoot(PrivateStorageOwnership::resolveRoot($uploads['basedir'], $configured));
            if (PrivateStorageOwnership::isOwned($root)) {
                self::assertExternalRoot($root, $uploads['basedir']);
                // Check the whole tree before removing anything; retain the
                // ownership proof until every other entry has been removed.
                self::checkTree($root);
                self::deleteTree($root, true);
            } else {
                PrivateStorageOwnership::assertOwnedOrEmpty($root);
            }
        }
        // Historical per-site folders belong entirely to this plugin,
        // including unattached uploads. Never delete their shared parent.
        self::deleteTree($legacy);
        self::deleteTree(rtrim(str_replace('\\', '/', $uploads['basedir']), '/') . '/vidyalms');
    }

    private static function assertExternalRoot(string $root, string $uploadsBase): void
    {
        foreach ([ABSPATH, $uploadsBase, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content',
            defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '', defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
            // OS document root is boundary data, not display text; LocalStorageSafety validates the real path.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            wp_unslash((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))] as $public) {
            if ($public === '') {
                continue;
            }
            foreach ([$public, realpath($public)] as $boundary) {
                if ($boundary === false) {
                    continue;
                }
                $boundary = rtrim(str_replace('\\', '/', $boundary), '/') . '/';
                $candidate = $root . '/';
                if (DIRECTORY_SEPARATOR === '\\') {
                    $candidate = strtolower($candidate);
                    $boundary = strtolower($boundary);
                }
                if (str_starts_with($candidate, $boundary) || str_starts_with($boundary, $candidate)) {
                    throw new \RuntimeException('Uninstall refuses recursive deletion of a public storage root.');
                }
            }
        }
    }

    private static function checkTree(string $root): void
    {
        self::checkedPath($root);
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = self::checkedPath($entry->getPathname());
            if ($entry->isDir()) {
                self::checkTree($path);
            }
        }
    }

    public static function deleteTree(string $root, bool $markerLast = false): void
    {
        $root = self::checkedPath($root);
        if (!is_dir($root)) {
            return;
        }
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($markerLast && $entry->getFilename() === PrivateStorageOwnership::MARKER) {
                continue;
            }
            $path = self::checkedPath($entry->getPathname());
            if ($entry->isDir()) {
                self::deleteTree($path);
            } else {
                self::deleteFile($path);
            }
        }
        if ($markerLast) {
            self::deleteFile(self::checkedPath($root . '/' . PrivateStorageOwnership::MARKER));
        }
        // Deletes only files under a verified, owned external private root; no remote filesystem is involved.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
        if (!rmdir($root)) {
            throw new \RuntimeException('Cannot remove the VidyaLMS private directory.');
        }
    }

    private static function deleteFile(string $path): void
    {
        if (is_file($path)) {
            wp_delete_file($path);
            clearstatcache(true, $path);
            if (is_file($path)) {
                throw new \RuntimeException('Cannot remove a VidyaLMS private file; data has been preserved.');
            }
        }
    }

    private static function checkedPath(string $path): string
    {
        clearstatcache();
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#^(?:/[A-Za-z0-9_.-]|[A-Za-z]:/)#D', $path) !== 1
            || str_contains($path, '//') || preg_match('/[\x00-\x1f\x7f]/', $path)
            || \in_array('..', explode('/', $path), true) || \in_array('.', explode('/', $path), true)) {
            throw new \RuntimeException('Unsafe VidyaLMS private storage path.');
        }
        for ($part = $path; $part !== \dirname($part); $part = \dirname($part)) {
            $real = realpath($part);
            $same = $real === false || (DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp(str_replace('\\', '/', $real), $part) === 0
                : str_replace('\\', '/', $real) === $part);
            if (is_link($part) || !$same) {
                throw new \RuntimeException('Uninstall refuses filesystem links and aliases.');
            }
        }
        return $path;
    }
}
