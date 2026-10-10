<?php

declare(strict_types=1);

/**
 * VidyaLMS: what PHP will actually accept, and how to say a byte count.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Files;

use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota;

defined('ABSPATH') || exit;

/**
 * The two answers the upload endpoint needs about size.
 *
 * wp_max_upload_size() is WordPress's own reading of upload_max_filesize,
 * post_max_size and the site's filters, and is what the media library obeys,
 * so it is the ceiling here too.
 *
 * formatBytes() deliberately does not use size_format(). The endpoint's
 * refusals are compared byte for byte with the Joomla adapter's by the parity
 * suite, and size_format() localises its separators, so the two hosts would
 * disagree about the same file on the same day for no reason a reader could
 * see. The arithmetic here is the Joomla adapter's arithmetic.
 */
final class UploadLimits
{
    public const FILTER_LEARNER_QUOTA = 'vidyalms_learner_upload_limits';

    private function __construct()
    {
    }

    /**
     * Site-owned per-learner limits. Filter signature: (array $limits, int $userId): array.
     * Keys are max_bytes, max_files and max_hourly_files, all positive integers.
     * Partial overrides inherit defaults; malformed entries retain their default.
     * This hook is used only for attempts/submissions, never authoring uploads.
     *
     * @return array{max_bytes: int, max_files: int, max_hourly_files: int}
     */
    public static function learnerQuota(int $userId): array
    {
        $defaults = LocalUploadQuota::limits();
        try {
            return LocalUploadQuota::limits(apply_filters('vidyalms_learner_upload_limits', $defaults, $userId));
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * The largest upload this site will accept, in bytes, or zero when nothing
     * sets a limit. A caller takes the smaller of this and its own ceiling, and
     * treats zero as "no opinion" rather than as a minimum.
     */
    public static function phpCeilingBytes(): int
    {
        $php = \function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;

        return max(0, $php);
    }

    /**
     * The effective ceiling: the caller's own, lowered to the site's when the
     * site has one.
     */
    public static function effectiveCeilingBytes(int $configured): int
    {
        $php = self::phpCeilingBytes();

        return $php > 0 ? min($configured, $php) : $configured;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
