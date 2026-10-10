<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

final class FrozenClock implements ClockPort
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-15 10:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->add(new \DateInterval($interval));
    }

    public function set(string $now): void
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }
}
