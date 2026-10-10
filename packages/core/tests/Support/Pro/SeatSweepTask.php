<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/** @internal */
final class SeatSweepTask implements ScheduledTaskHandlerInterface
{
    public const KEY = 'pro.seats.sweep';

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        return 'seats swept';
    }
}
