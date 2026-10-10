<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;

/**
 * The value object every commerce amount is expressed in.
 *
 * The two properties worth the most here are the ones a float cannot hold:
 * an exponent that comes from ISO 4217 rather than from habit, and an
 * allocation whose remainder is always assigned to a named recipient in a
 * stated order rather than lost.
 */
final class MoneyTest extends TestCase
{
    public function testTheExponentComesFromTheTableAndNotFromHabit(): void
    {
        self::assertSame(2, Money::exponentFor('EUR'));
        self::assertSame(0, Money::exponentFor('JPY'));
        self::assertSame(0, Money::exponentFor('KRW'));
        self::assertSame(3, Money::exponentFor('KWD'));
        self::assertSame(3, Money::exponentFor('BHD'));
        // The two exponent-four codes. Neither settles anywhere and both are
        // ISO 4217 all the same: falling through to two read a stored integer
        // in either a hundred times too small.
        self::assertSame(4, Money::exponentFor('CLF'));
        self::assertSame(4, Money::exponentFor('UYW'));
        // Anything the table does not name is two, which is the majority
        // case and is what the design says the rest of ISO 4217 is.
        self::assertSame(2, Money::exponentFor('AUD'));
    }

    public function testACurrencyIsThreeUppercaseLettersAndNothingElse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Money(100, 'eur0');
    }

    public function testArithmeticAcrossTwoCurrenciesIsRefusedAtTheTypeLevel(): void
    {
        $euros = new Money(100, 'EUR');

        $this->expectException(\InvalidArgumentException::class);
        $euros->add(new Money(100, 'USD'));
    }

    public function testAddSubtractAndCompareStayInMinorUnits(): void
    {
        $a = new Money(2500, 'EUR');
        $b = new Money(499, 'EUR');

        self::assertSame(2999, $a->add($b)->minorUnits);
        self::assertSame(2001, $a->subtract($b)->minorUnits);
        self::assertSame(1, $a->compare($b));
        self::assertSame(-1, $b->compare($a));
        self::assertSame(0, $a->compare(new Money(2500, 'EUR')));
        self::assertTrue(Money::zero('EUR')->isZero());
        self::assertFalse($a->isZero());
    }

    public function testMultiplyByRatioRoundsHalfUpAndNeverTouchesAFloat(): void
    {
        self::assertSame(333, (new Money(1000, 'EUR'))->multiplyByRatio(1, 3)->minorUnits);
        // 1000 * 2 / 3 = 666.66..., which rounds to 667 rather than truncating.
        self::assertSame(667, (new Money(1000, 'EUR'))->multiplyByRatio(2, 3)->minorUnits);
        self::assertSame(-667, (new Money(-1000, 'EUR'))->multiplyByRatio(2, 3)->minorUnits);
    }

    public function testAllocateSpreadsTheRemainderOverTheLeadingMoniesInListOrder(): void
    {
        $fiveCents = new Money(5, 'EUR');

        self::assertSame([4, 1], self::minor($fiveCents->allocate([70, 30])));
        self::assertSame([2, 3], self::minor($fiveCents->allocate([30, 70])));
        // The whole is conserved in every split, which is the property the
        // pattern exists for.
        self::assertSame(5, array_sum(self::minor($fiveCents->allocate([1, 1, 1]))));
    }

    public function testAllocateRefusesWeightsThatSumToNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Money(5, 'EUR'))->allocate([0, 0]);
    }

    public function testAZeroWeightIsAnExclusionAndNeverARemainderRecipient(): void
    {
        // A coupon distributed across order lines with one line deliberately
        // excluded. Before the fix the excluded line was paid the whole cent
        // and both weighted lines got nothing.
        self::assertSame([0, 1, 0], self::minor((new Money(1, 'EUR'))->allocate([0, 1, 1])));
        self::assertSame([0, 34, 33, 33], self::minor((new Money(100, 'EUR'))->allocate([0, 1, 1, 1])));
        self::assertSame([0, 3, 2], self::minor((new Money(5, 'EUR'))->allocate([0, 3, 4])));

        // Negative remainders take the same rule, and the sum still holds.
        self::assertSame([0, -1, 0], self::minor((new Money(-1, 'EUR'))->allocate([0, 1, 1])));

        foreach ([[0, 1, 1], [0, 1, 1, 1], [1, 0, 2], [3, 0, 0, 4]] as $weights) {
            foreach ([1, 5, 7, 100, 999, -13] as $amount) {
                $shares = self::minor((new Money($amount, 'EUR'))->allocate($weights));
                self::assertSame($amount, array_sum($shares), 'an allocation stopped conserving its total');

                foreach ($weights as $index => $weight) {
                    if ($weight === 0) {
                        self::assertSame(0, $shares[$index], 'an excluded line was paid a remainder unit');
                    }
                }
            }
        }
    }

    public function testFormattingIsMinorUnitsOverTheCurrencyExponent(): void
    {
        self::assertSame('25.00', (new Money(2500, 'EUR'))->decimalString());
        self::assertSame('2500', (new Money(2500, 'JPY'))->decimalString());
        self::assertSame('2.500', (new Money(2500, 'KWD'))->decimalString());
        self::assertSame('-0.05', (new Money(-5, 'EUR'))->decimalString());
    }

    /**
     * @param list<Money> $monies
     *
     * @return list<int>
     */
    private static function minor(array $monies): array
    {
        return array_map(static fn (Money $money): int => $money->minorUnits, $monies);
    }
}
