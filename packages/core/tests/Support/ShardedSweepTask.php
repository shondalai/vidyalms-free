<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/** The four-bin shape a sweep that enumerates rows uses. */
final class ShardedSweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'test.sharded_sweep';

    public function key(): string
    {
        return self::TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        return 'ran';
    }

    public function binCount(): int
    {
        return 4;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'test:' . self::TYPE . ':' . $periodBucket . ':' . $binIndex;
    }
}
