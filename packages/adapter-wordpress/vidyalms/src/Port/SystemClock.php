<?php

declare(strict_types=1);

/**
 * VidyaLMS: the clock port, UTC and nothing else.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

defined('ABSPATH') || exit;

/**
 * The only source of "now" in the running plugin.
 *
 * Always UTC, never the site timezone from the WordPress "timezone_string"
 * option: every timestamp the core stores is UTC and presentation converts at
 * the edge. Using current_time() here would write local times into columns the
 * completion engine compares against UTC.
 */
final class SystemClock implements ClockPort
{
    private \DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->utc);
    }
}
