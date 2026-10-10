<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Storage;

/** Filesystem boundaries shared by the two local storage adapters. */
final class LocalStorageSafety
{
    public static function absolute(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (
            !preg_match('#^(?:/[A-Za-z0-9_.-]|[A-Za-z]:/)#D', $path)
            || preg_match('/[\x00-\x1f\x7f]/', $path)
            || str_contains($path, '//')
        ) {
            throw new \RuntimeException('Storage requires an absolute local directory.');
        }
        foreach (explode('/', preg_replace('#^[A-Za-z]:/#', '', ltrim($path, '/')) ?? '') as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[<>:"|?*]|[. ]$/', $part)) {
                throw new \RuntimeException('Unsafe storage directory.');
            }
        }
        // The host may deliberately place its root behind a deployment link
        // or junction. Resolve that trusted boundary once; child paths still
        // go through noLinks() and can never escape it through an upload.
        $suffix = [];
        for ($ancestor = $path; ; $ancestor = dirname($ancestor)) {
            $real = @realpath($ancestor);
            if ($real !== false) {
                return rtrim(str_replace('\\', '/', $real), '/')
                    . ($suffix === [] ? '' : '/' . implode('/', array_reverse($suffix)));
            }
            if ($ancestor === dirname($ancestor)) {
                throw new \RuntimeException('The storage directory is outside the paths available to PHP.');
            }
            $suffix[] = basename($ancestor);
        }
    }

    /** @param list<string> $publicRoots */
    public static function privateRoot(string $root, array $publicRoots): string
    {
        $root = self::absolute($root);
        foreach ($publicRoots as $public) {
            if ($public === '') {
                continue;
            }
            $public = rtrim(str_replace('\\', '/', $public), '/');
            $resolved = @realpath($public);
            foreach (array_unique([$public, $resolved === false ? $public : str_replace('\\', '/', $resolved)]) as $boundary) {
                if (self::within($root, $boundary) || self::within($boundary, $root)) {
                    throw new \RuntimeException('Private storage is disabled: configure VIDYALMS_PRIVATE_DIR outside every public root.');
                }
            }
        }
        return $root;
    }

    public static function relative(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\:<>"|?*]/', $path)) {
            throw new \InvalidArgumentException('Unsafe storage path.');
        }
        foreach (explode('/', $path) as $part) {
            if (
                $part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part)
            ) {
                throw new \InvalidArgumentException('Unsafe storage path.');
            }
        }
        return $path;
    }

    public static function path(string $root, string $path): string
    {
        $absolute = self::absolute($root) . '/' . self::relative($path);
        self::noLinks($absolute);
        return $absolute;
    }

    /** Internal metadata and secrets are never part of the download namespace. */
    public static function downloadable(string $path): string
    {
        $path = self::relative($path);
        foreach (explode('/', $path) as $part) {
            if (str_starts_with($part, '.')) {
                throw new \InvalidArgumentException('Internal storage files cannot be downloaded.');
            }
        }
        return $path;
    }

    public static function noLinks(string $path): void
    {
        clearstatcache();
        for ($current = $path; $current !== dirname($current); $current = dirname($current)) {
            if (@is_link($current)) {
                throw new \RuntimeException('Storage paths must not contain symbolic links.');
            }
            // Also detects Windows junctions when PHP does not report is_link().
            $real = @realpath($current);
            if ($real !== false && !self::equal(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $current), '/'))) {
                throw new \RuntimeException('Storage paths must not contain filesystem aliases.');
            }
        }
    }

    /** Read-only preflight: a missing root may be created beneath a writable parent. */
    public static function writableDirectory(string $root): bool
    {
        self::noLinks($root);
        for ($directory = $root; !is_dir($directory); $directory = dirname($directory)) {
            if (file_exists($directory) || $directory === dirname($directory)) {
                return false;
            }
        }
        // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        return is_writable($directory);
    }

    public static function write(string $absolute, string $contents, bool $private = true): void
    {
        self::noLinks($absolute);
        $folder = dirname($absolute);
        // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
        if (!is_dir($folder) && !mkdir($folder, $private ? 0700 : 0755, true) && !is_dir($folder)) {
            throw new \RuntimeException('Cannot create the storage directory.');
        }
        self::noLinks($absolute);
        $temporary = $folder . '/.vidyalms-' . bin2hex(random_bytes(16)) . '.tmp';
        // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create the storage file.');
        }
        try {
            // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
            chmod($temporary, $private ? 0600 : 0644);
            // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write the complete storage file.');
            }
            // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            $handle = null;
            self::noLinks($absolute);
            // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            if (!rename($temporary, $absolute)) {
                throw new \RuntimeException('Cannot publish the storage file.');
            }
        } finally {
            if (is_resource($handle)) {
                // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                fclose($handle);
            }
            if (is_file($temporary)) {
                // Private local storage requires exclusive writes, restrictive modes and atomic rename across both hosts.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($temporary);
            }
        }
    }

    private static function within(string $child, string $parent): bool
    {
        return self::equal($child, $parent) || self::equal(substr($child, 0, strlen($parent) + 1), $parent . '/');
    }

    private static function equal(string $left, string $right): bool
    {
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
