<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Progress;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;

final class LessonProgressTest extends TestCase
{
    private function now(string $time = '2026-01-15 10:00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testStartOpensInProgressWithFirstAccess(): void
    {
        $now = $this->now();
        $progress = LessonProgress::start(1, 2, 3, $now);

        self::assertSame(LessonProgress::STATUS_IN_PROGRESS, $progress->status());
        self::assertSame($now, $progress->firstAccessedAt());
        self::assertSame(0.0, $progress->progressPercent());
        self::assertSame(0, $progress->secondsSpent());
        self::assertNull($progress->completedAt());
        self::assertFalse($progress->isCompleted());
    }

    public function testHeartbeatSetsFirstAccessOnlyOnce(): void
    {
        $first = $this->now();
        $later = $this->now('2026-01-15 11:00:00');

        $progress = LessonProgress::restore(1, 2, 3, LessonProgress::STATUS_NOT_STARTED, 0.0, 0, null, null, null, null);
        $progress->recordHeartbeat($first);

        self::assertSame(LessonProgress::STATUS_IN_PROGRESS, $progress->status());
        self::assertSame($first, $progress->firstAccessedAt());
        self::assertSame($first, $progress->lastHeartbeatAt());

        $progress->recordHeartbeat($later);

        self::assertSame($first, $progress->firstAccessedAt());
        self::assertSame($later, $progress->lastHeartbeatAt());
    }

    public function testProgressPercentIsMonotonic(): void
    {
        $progress = LessonProgress::start(1, 2, 3, $this->now());

        $progress->raiseProgressPercent(80.0);
        self::assertSame(80.0, $progress->progressPercent());

        $progress->raiseProgressPercent(40.0);
        self::assertSame(80.0, $progress->progressPercent());

        $progress->raiseProgressPercent(250.0);
        self::assertSame(100.0, $progress->progressPercent());

        $progress->raiseProgressPercent(-10.0);
        self::assertSame(100.0, $progress->progressPercent());
    }

    public function testSecondsAccumulateAndNegativeIsIgnored(): void
    {
        $progress = LessonProgress::start(1, 2, 3, $this->now());

        $progress->addSecondsSpent(15);
        $progress->addSecondsSpent(15);
        $progress->addSecondsSpent(-100);

        self::assertSame(30, $progress->secondsSpent());
    }

    public function testCompleteTransitionsExactlyOnce(): void
    {
        $now = $this->now();
        $progress = LessonProgress::start(1, 2, 3, $now);

        self::assertTrue($progress->complete($now));
        self::assertTrue($progress->isCompleted());
        self::assertSame($now, $progress->completedAt());
        self::assertSame(100.0, $progress->progressPercent());

        $later = $this->now('2026-01-16 09:00:00');
        self::assertFalse($progress->complete($later));
        self::assertSame($now, $progress->completedAt());
    }

    public function testCompletedNeverRegresses(): void
    {
        $now = $this->now();
        $progress = LessonProgress::start(1, 2, 3, $now);
        $progress->complete($now);

        $progress->raiseProgressPercent(10.0);
        $progress->recordHeartbeat($this->now('2026-01-16 09:00:00'));

        self::assertTrue($progress->isCompleted());
        self::assertSame(100.0, $progress->progressPercent());
        self::assertSame($now, $progress->completedAt());
    }

    public function testCompleteOnUntouchedRowSetsFirstAccess(): void
    {
        $now = $this->now();
        $progress = LessonProgress::restore(1, 2, 3, LessonProgress::STATUS_NOT_STARTED, 0.0, 0, null, null, null, null);

        self::assertTrue($progress->complete($now));
        self::assertSame($now, $progress->firstAccessedAt());
    }

    public function testMoveToClampsNegativePosition(): void
    {
        $progress = LessonProgress::start(1, 2, 3, $this->now());

        $progress->moveTo(90);
        self::assertSame(90, $progress->positionSeconds());

        $progress->moveTo(30);
        self::assertSame(30, $progress->positionSeconds());

        $progress->moveTo(-5);
        self::assertSame(0, $progress->positionSeconds());
    }
}
