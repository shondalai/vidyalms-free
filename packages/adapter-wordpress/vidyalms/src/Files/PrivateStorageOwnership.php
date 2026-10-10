<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Files;

defined('ABSPATH') || exit;

/** Standalone storage/lifecycle contract: no plugin bootstrap or core loader. */
final class PrivateStorageOwnership
{
    public const MARKER = '.vidyalms-owner.json';
    public const TOKEN_OPTION = 'vidyalms_storage_site_token';
    public const VERSION = 3;

    /** Resolve in the currently switched WordPress site; never creates a default root. */
    public static function resolveRoot(string $uploadsBase, string $configured): string
    {
        $configured = rtrim(str_replace('\\', '/', trim($configured)), '/');
        if (str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:/#', $configured) === 1) {
            return $configured . (is_multisite() ? '/sites/' . self::siteIdentity()['blog_id'] : '');
        }
        return rtrim(str_replace('\\', '/', $uploadsBase), '/') . '/' . $configured;
    }

    /** @return array{network_id: int, blog_id: int, site_url: string, installation: string} */
    public static function siteIdentity(): array
    {
        $blogId = (int) get_current_blog_id();
        $networkId = 0;
        if (is_multisite()) {
            // switch_to_blog() does not necessarily change the current network.
            $site = get_site($blogId);
            $networkId = $site === null ? 0 : (int) $site->network_id;
            if ($networkId < 1) {
                throw new \RuntimeException('Cannot identify the WordPress storage network.');
            }
        }
        if ($blogId < 1) {
            throw new \RuntimeException('Cannot identify the WordPress storage site.');
        }
        $url = rtrim(home_url('/'), '/');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Cannot identify the WordPress site address.');
        }
        $installation = @realpath(ABSPATH);
        if ($installation === false) {
            throw new \RuntimeException('Cannot identify the WordPress installation directory.');
        }
        $installation = rtrim(str_replace('\\', '/', $installation), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $installation = strtolower($installation);
        }
        return ['network_id' => $networkId, 'blog_id' => $blogId, 'site_url' => $url,
            'installation' => hash('sha256', $installation)];
    }

    /**
     * False means unmarked: no recursive ownership has been established.
     * A malformed, linked or foreign marker throws, including for ordinary I/O.
     * The caller must additionally enforce its private/public-root boundary.
     * @phpstan-impure Filesystem ownership can change between calls.
     */
    public static function isOwned(string $root): bool
    {
        $root = self::canonicalRoot($root);
        $marker = $root . '/' . self::MARKER;
        self::checkedRoot($marker);
        if (!file_exists($marker)) {
            return false;
        }
        if (!is_file($marker)) {
            throw new \RuntimeException('Invalid private storage ownership marker.');
        }
        // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($marker, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read private storage ownership.');
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new \RuntimeException('Cannot verify private storage ownership.');
            }
            $contents = stream_get_contents($handle, 4097);
            $markerData = is_string($contents) && strlen($contents) <= 4096 ? json_decode($contents, true) : null;
            $token = get_option(self::TOKEN_OPTION, false);
            $identity = self::siteIdentity();
            if (!is_array($markerData) || count($markerData) !== 6
                || ($markerData['version'] ?? null) !== self::VERSION
                || ($markerData['network_id'] ?? null) !== $identity['network_id']
                || ($markerData['blog_id'] ?? null) !== $identity['blog_id']
                || ($markerData['site_url'] ?? null) !== $identity['site_url']
                || ($markerData['installation'] ?? null) !== $identity['installation']
                || !self::validToken($token) || !self::validToken($markerData['site_token'] ?? null)
                || !hash_equals($token, $markerData['site_token'])) {
                throw new \RuntimeException('Private storage ownership does not match this WordPress site.');
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
        }
    }

    /** Only called after the host verified an external, writable private root. */
    public static function claimIfEmpty(string $root): bool
    {
        $root = self::canonicalRoot($root);
        if (self::isOwned($root)) {
            return true;
        }
        if (!self::emptyExceptMarker($root, false)) {
            return false;
        }
        $marker = $root . '/' . self::MARKER;
        // Exclusive create elects one first writer. Other writers verify its
        // shared-locked marker; a crashed partial marker fails closed.
        // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = @fopen($marker, 'x+b');
        if ($handle === false) {
            if (self::isOwned($root)) {
                return true;
            }
            throw new \RuntimeException('Cannot claim private storage ownership.');
        }
        $complete = false;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock private storage ownership.');
            }
            if (!self::emptyExceptMarker($root, true)) {
                return false;
            }
            $contents = self::markerContentsForCurrentSite();
            // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            if (!chmod($marker, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist private storage ownership.');
            }
            $complete = true;
            return true;
        } finally {
            flock($handle, LOCK_UN);
            // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($handle);
            if (!$complete) {
                // Never delete foreign files discovered during the empty-root check.
                // Local ownership requires exclusive creation, flock and 0600 mode, unavailable through WP_Filesystem.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink($marker);
            }
        }
    }

    /**
     * Server-owner migration helper: creates/reuses the token but writes no file.
     * Only install these bytes manually after verifying an entire nonempty root
     * belongs exclusively to this site and quiescing every writer.
     */
    public static function markerContentsForCurrentSite(): string
    {
        $token = get_option(self::TOKEN_OPTION, false);
        if ($token === false) {
            // option_name is unique: a concurrent winner supplies the token.
            add_option(self::TOKEN_OPTION, bin2hex(random_bytes(32)), '', false);
            $token = get_option(self::TOKEN_OPTION, false);
        }
        if (!self::validToken($token)) {
            throw new \RuntimeException('The private storage site token is missing or invalid.');
        }
        return json_encode(['version' => self::VERSION] + self::siteIdentity() + ['site_token' => $token], JSON_THROW_ON_ERROR) . "\n";
    }

    private static function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private static function emptyExceptMarker(string $root, bool $allowMarker): bool
    {
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$allowMarker || $entry->getFilename() !== self::MARKER) {
                return false;
            }
        }
        return true;
    }

    /** Never use an existing directory without proof that this installation owns it. */
    public static function assertOwnedOrEmpty(string $root): void
    {
        $root = self::canonicalRoot($root);
        if (!self::isOwned($root) && is_dir($root) && !self::emptyExceptMarker($root, false)) {
            throw new \RuntimeException('Private storage ownership must be verified by the server administrator before using this existing directory.');
        }
    }

    /** Kept standalone so uninstall does not need a generated core autoloader. */
    public static function canonicalRoot(string $path): string
    {
        $path = self::checkedRoot($path, false);
        $suffix = [];
        for ($ancestor = $path; ; $ancestor = dirname($ancestor)) {
            $real = @realpath($ancestor);
            if ($real !== false) {
                return self::checkedRoot(rtrim(str_replace('\\', '/', $real), '/')
                    . ($suffix === [] ? '' : '/' . implode('/', array_reverse($suffix))));
            }
            if ($ancestor === dirname($ancestor)) {
                throw new \RuntimeException('The storage root is outside the paths available to PHP.');
            }
            $suffix[] = basename($ancestor);
        }
    }

    private static function checkedRoot(string $path, bool $checkLinks = true): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (preg_match('#^(?:/[A-Za-z0-9_.-]|[A-Za-z]:/)#D', $path) !== 1 || str_contains($path, '//')
            || preg_match('/[\x00-\x1f\x7f]/', $path) || in_array('..', explode('/', $path), true)
            || in_array('.', explode('/', $path), true)) {
            throw new \RuntimeException('Unsafe private storage ownership path.');
        }
        $tail = preg_replace('#^[A-Za-z]:/#', '', ltrim($path, '/')) ?? '';
        foreach (explode('/', $tail) as $segment) {
            if ($segment === '' || preg_match('/[<>:"|?*]|[. ]$/', $segment)
                || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)) {
                throw new \RuntimeException('Unsafe private storage ownership path.');
            }
        }
        if (!$checkLinks) {
            return $path;
        }
        clearstatcache();
        for ($part = $path; $part !== dirname($part); $part = dirname($part)) {
            $real = realpath($part);
            $same = $real === false || (DIRECTORY_SEPARATOR === '\\'
                ? strcasecmp(str_replace('\\', '/', $real), $part) === 0 : str_replace('\\', '/', $real) === $part);
            if (is_link($part) || !$same) {
                throw new \RuntimeException('Private storage ownership refuses filesystem links or aliases.');
            }
        }
        return $path;
    }
}
