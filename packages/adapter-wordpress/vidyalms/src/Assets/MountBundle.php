<?php

declare(strict_types=1);

/**
 * VidyaLMS: one client bundle contributed beside a mount.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Assets;

defined('ABSPATH') || exit;

/**
 * A typed bundle descriptor, with the same members as the Joomla adapter's
 * MountAssetBundle.
 *
 * The two adapters used to publish structurally different seams: Joomla a
 * typed registry of value objects, WordPress an untyped array of four string
 * keys. An add-on shipping for both hosts therefore wrote two unrelated
 * integrations, and only the Joomla one could grow a fact without touching
 * every listener.
 *
 * The array form is still accepted, because it is what shipped, and
 * fromArray() is the one place that reads it.
 */
final class MountBundle
{
    /**
     * @param string      $handle  the script handle, unique across the site
     * @param string      $src     absolute URL to the JavaScript file
     * @param string|null $version cache-busting version, or null for none
     * @param string|null $style   absolute URL to a stylesheet loaded with it
     * @param bool        $module  false to load it as a classic script
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $src,
        public readonly ?string $version = null,
        public readonly ?string $style = null,
        public readonly bool $module = true
    ) {
        if (trim($handle) === '' || trim($src) === '') {
            throw new \InvalidArgumentException('A contributed bundle needs both a handle and a src.');
        }
    }

    /**
     * The array shape the filter shipped with, read in one place.
     *
     * @param array<string, mixed> $bundle
     */
    public static function fromArray(array $bundle): self
    {
        $handle = \is_string($bundle['handle'] ?? null) ? trim($bundle['handle']) : '';
        $src = \is_string($bundle['src'] ?? null) ? trim($bundle['src']) : '';
        $version = \is_string($bundle['version'] ?? null) && $bundle['version'] !== ''
            ? $bundle['version']
            : null;
        $style = \is_string($bundle['style'] ?? null) && trim($bundle['style']) !== ''
            ? trim($bundle['style'])
            : null;

        return new self(
            $handle,
            $src,
            $version,
            $style,
            !isset($bundle['module']) || $bundle['module'] !== false
        );
    }
}
