<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The only source of "now" in the running system. Always UTC, never the site's
 * display timezone: every timestamp the core stores is UTC, and presentation
 * converts at the edge.
 *
 * @since 1.0.0
 */
final class SystemClock implements ClockPort
{
    private readonly \DateTimeZone $utc;

    public function __construct()
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->utc);
    }
}
