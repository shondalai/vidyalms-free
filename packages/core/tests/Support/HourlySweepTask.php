<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/** A one-bin hourly sweep, the shape every free sweep that cannot shard has. */
final class HourlySweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'test.hourly_sweep';

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
        return 1;
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
