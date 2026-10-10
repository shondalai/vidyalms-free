<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptTotals;

final class AttemptTotalsTest extends TestCase
{
    public function testPercentageIsRoundedToTwoDecimals(): void
    {
        $totals = AttemptTotals::fromPoints(1.0, 3.0);

        self::assertSame(33.33, $totals->percentage);
    }

    public function testZeroMaxScoreYieldsZeroPercentage(): void
    {
        $totals = AttemptTotals::fromPoints(0.0, 0.0);

        self::assertSame(0.0, $totals->percentage);
    }

    public function testPassedComparesAgainstThePassMark(): void
    {
        $totals = AttemptTotals::fromPoints(7.0, 10.0);

        self::assertTrue($totals->passed(70));
        self::assertFalse($totals->passed(71));
    }

    public function testZeroPassMarkAlwaysPasses(): void
    {
        self::assertTrue(AttemptTotals::fromPoints(0.0, 10.0)->passed(0));
    }

    public function testLatestGradeRowPerQuestionWins(): void
    {
        $when = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        $totals = AttemptTotals::fromGradeHistory([
            new AttemptGrade(1, 5, 11, null, 0.0, 4.0, null, $when),
            new AttemptGrade(2, 5, 12, null, 2.0, 2.0, null, $when),
            new AttemptGrade(3, 5, 11, 99, 3.0, 4.0, 'Partial credit on review.', $when),
        ]);

        self::assertSame(5.0, $totals->score);
        self::assertSame(6.0, $totals->maxScore);
        self::assertSame(83.33, $totals->percentage);
    }
}
