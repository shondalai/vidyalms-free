<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Clock;

use Psr\Clock\ClockInterface;

/**
 * Time, injectable so tests are deterministic. All core timestamps are UTC.
 */
interface ClockPort extends ClockInterface
{
}
