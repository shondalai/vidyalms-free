<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Factory;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota;

defined('_JEXEC') or die;

/**
 * What PHP will actually accept, and how to say a byte count out loud.
 *
 * Two screens and one endpoint need the same two answers, and reporting a
 * configured ceiling of 64M while PHP silently truncates at 8M is how an
 * operator loses an afternoon, so the arithmetic lives in one place rather
 * than in each caller.
 *
 * WordPress has wp_max_upload_size() for exactly this; Joomla has nothing, so
 * the two directives are read here.
 *
 * @since 1.0.0
 */
final class UploadLimits
{
    public const EVENT_LEARNER_QUOTA = 'onVidyaLmsLearnerUploadLimits';

    private function __construct()
    {
    }

    /**
     * Site-owned per-learner limits, following the upload-policy event seam.
     * Listeners read userId and replace limits with setArgument('limits', $limits).
     * Keys are max_bytes, max_files and max_hourly_files, all positive integers.
     * Partial overrides inherit defaults; malformed entries retain their default.
     * This event is used only for attempts/submissions, never authoring uploads.
     *
     * @return array{max_bytes: int, max_files: int, max_hourly_files: int}
     */
    public static function learnerQuota(int $userId): array
    {
        $defaults = LocalUploadQuota::limits();
        try {
            $event = new GenericEvent(self::EVENT_LEARNER_QUOTA, ['limits' => $defaults, 'userId' => $userId]);
            Factory::getApplication()->getDispatcher()->dispatch(self::EVENT_LEARNER_QUOTA, $event);
            return LocalUploadQuota::limits($event->getArgument('limits'));
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * The largest upload this PHP will accept, in bytes, or zero when neither
     * directive sets a limit. A caller takes the smaller of this and its own
     * ceiling, and treats zero as "no opinion" rather than as a minimum.
     */
    public static function phpCeilingBytes(): int
    {
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * The effective ceiling: the caller's own, lowered to PHP's when PHP has
     * one.
     */
    public static function effectiveCeilingBytes(int $configured): int
    {
        $php = self::phpCeilingBytes();

        return $php > 0 ? min($configured, $php) : $configured;
    }

    /**
     * "8M", "512K", "1G" as PHP writes them, in bytes. Zero for "no limit",
     * which post_max_size uses and which must never become the minimum.
     */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
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
