<?php

declare(strict_types=1);

/**
 * VidyaLMS: bounded reads of one public storage file.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Files;

defined('ABSPATH') || exit;

/**
 * Exact-root, size-capped byte access for WpStorage::readPublic().
 *
 * A PDF certificate renderer reads its image elements through
 * ReadsPublicFiles, and the free plugin has to answer that port even though it
 * ships no renderer of its own: the Pro add-on's renderer runs against this
 * plugin's storage. The same rules lived in the PDF runtime the plugin used to
 * bundle, so the copy here keeps them identical: a relative path of plain
 * segments only, resolved inside the public root, never larger than the cap.
 */
final class BoundedPublicFile
{
    /** The largest cap a caller may ask for, 4 MiB. */
    public const MAXIMUM_BYTES = 4194304;

    private function __construct()
    {
    }

    /**
     * The bytes of $path under $root, refusing anything outside it or larger
     * than $maximumBytes.
     */
    public static function read(string $root, string $path, int $maximumBytes): string
    {
        $segments = explode('/', $path);

        if (
            $maximumBytes < 1 || $maximumBytes > self::MAXIMUM_BYTES || $path === ''
            || preg_match('~^[A-Za-z0-9_. -]+(?:/[A-Za-z0-9_. -]+)*$~D', $path) !== 1
            || \in_array('..', $segments, true) || \in_array('.', $segments, true)
        ) {
            throw new \InvalidArgumentException('Invalid bounded storage path.');
        }

        $base = realpath($root);
        $resolved = realpath($root . '/' . $path);
        $prefix = $base === false ? '' : str_replace('\\', '/', $base) . '/';
        $file = $resolved === false ? '' : str_replace('\\', '/', $resolved);

        if (PHP_OS_FAMILY === 'Windows') {
            $prefix = strtolower($prefix);
            $file = strtolower($file);
        }

        if ($base === false || $resolved === false || !str_starts_with($file, $prefix) || !is_file($resolved)) {
            throw new \RuntimeException('The public storage file is unavailable or outside its root.');
        }

        // A bounded stream rather than a whole-file read, so the size cap holds
        // even for a file that grows between the stat and the read.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = fopen($resolved, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Cannot open the public storage file.');
        }

        try {
            $stat = fstat($stream);

            if ($stat === false || $stat['size'] > $maximumBytes) {
                throw new \RuntimeException('The public storage file exceeds its byte limit.');
            }

            $bytes = stream_get_contents($stream, $maximumBytes + 1);

            if ($bytes === false || \strlen($bytes) > $maximumBytes) {
                throw new \RuntimeException('The public storage file exceeds its byte limit.');
            }

            return $bytes;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
    }
}
