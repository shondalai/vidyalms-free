<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * One answer to "what URL does this stored asset path resolve to".
 *
 * It is shared rather than duplicated because the two call sites that resolve
 * a certificate's stored asset paths, the element render context and the page
 * background, disagreed: elements refused an absolute URL, a protocol-relative
 * URL and a data URI, and the page background interpolated whatever an author
 * typed straight into background-image. One of the two was a beacon
 * disclosing every viewer's address and user agent to a third-party host, on
 * an artefact this design treats as evidentiary and privacy-bounded.
 *
 * A path that tries to climb out of the root resolves to nothing rather than
 * to a file above it, so the element renders empty, which is the
 * visible-failure direction the renderer already uses.
 */
final class AssetPath
{
    private function __construct()
    {
    }

    /**
     * The URL a stored path resolves to inside the site's asset root, or the
     * empty string when it names anything outside it.
     */
    public static function resolve(string $path, string $assetBaseUrl): string
    {
        $normalised = self::relative($path);

        return $normalised === '' ? '' : rtrim($assetBaseUrl, '/') . '/' . $normalised;
    }

    /**
     * The stored path with . and .. resolved away, or the empty string when it
     * names anything outside the asset root.
     *
     * A caller that has no base URL to resolve against uses this rather than
     * resolve() with an empty base, because prefixing an empty base yields a
     * root-relative URL, which is a guess about where the site is mounted.
     */
    public static function relative(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0")) {
            return '';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) === 1 || str_starts_with($path, '//')) {
            return '';
        }

        return self::normalise($path);
    }

    /**
     * Whether a stored value names a path inside the asset root at all, which
     * is what a save-time check asks before the render ever happens.
     */
    public static function isRelative(string $path): bool
    {
        return self::relative($path) !== '';
    }

    /**
     * Resolves . and .. inside the path and refuses one that climbs above the
     * root, which is the only reason this exists: string concatenation alone
     * lets "../../.." out of the asset namespace entirely.
     */
    private static function normalise(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', ltrim($path, '/'))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return '';
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
