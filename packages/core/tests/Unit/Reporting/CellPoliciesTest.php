<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSuppressionPolicy;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * The two policies every export and every screen goes through.
 *
 * Both were previously one inline expression in one export, which is the shape
 * a rule has when it is true of one route rather than of a product.
 */
final class CellPoliciesTest extends TestCase
{
    /**
     * The full OWASP trigger list, not the four the inline guard carried.
     *
     * @dataProvider dangerousCells
     */
    public function testEveryTriggerIsNeutralisedUnderTheSpreadsheetSafeProfile(string $value): void
    {
        self::assertTrue(CellSafetyPolicy::isDangerous($value), $value . ' is a formula trigger');
        self::assertSame(
            CellSafetyPolicy::PREFIX . $value,
            CellSafetyPolicy::spreadsheetSafe()->apply($value),
        );
        $safe = CellSafetyPolicy::spreadsheetSafe()->apply($value);
        self::assertFalse(CellSafetyPolicy::isDangerous($safe));
        self::assertSame($safe, CellSafetyPolicy::spreadsheetSafe()->apply($safe));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousCells(): iterable
    {
        yield 'equals' => ['=1+1'];
        yield 'plus' => ['+1'];
        yield 'minus' => ['-1'];
        yield 'at' => ['@SUM(A1)'];
        yield 'tab' => ["\tSUM"];
        yield 'carriage return' => ["\r=1"];
        yield 'line feed' => ["\n=1"];
        // Leading whitespace does not save it: " =1+1" is a formula in Excel
        // and a scan anchored on the first byte does not see it.
        yield 'leading space' => ['  =cmd|calc'];
        // The full-width forms a CJK locale produces, which a byte-wise scan
        // never sees and a spreadsheet still evaluates.
        yield 'full width equals' => ["\u{FF1D}1+1"];
        yield 'full width plus' => ["\u{FF0B}1"];
        yield 'full width minus' => ["\u{FF0D}1"];
        yield 'full width at' => ["\u{FF20}SUM"];
    }

    public function testAnOrdinaryValueIsUntouched(): void
    {
        foreach (['Lena Learner', '42', 'a=b', 'course/1'] as $value) {
            self::assertFalse(CellSafetyPolicy::isDangerous($value));
            self::assertSame($value, CellSafetyPolicy::spreadsheetSafe()->apply($value));
        }
    }

    /**
     * The verbatim profile exists because neutralising a value corrupts it for
     * anything downstream that parses it, and one profile would be wrong for
     * one of the two audiences.
     */
    public function testTheVerbatimProfileLeavesTheBytesExactlyAsStored(): void
    {
        self::assertSame('=1+1', CellSafetyPolicy::verbatim()->apply('=1+1'));
        self::assertTrue(CellSafetyPolicy::verbatim()->isVerbatim());
    }

    public function testAModeNobodyShipsIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CellSafetyPolicy::of('quote-and-hope');
    }

    // ── Suppression ──────────────────────────────────────────────────────

    public function testACountBelowTheMinimumIsSuppressed(): void
    {
        [$row, $hidden] = CellSuppressionPolicy::ofMinimum(5)->applyToRow(
            ['name' => 'Cohort A', 'enrolled' => 40, 'completed' => 2],
            self::columns(),
        );

        self::assertNull($row['completed']);
        self::assertSame(2, $hidden, 'one small cell takes a second with it');
    }

    /**
     * Complementary suppression: one hidden cell in a row whose others are
     * visible is a hidden cell anybody recovers by subtraction, so the next
     * smallest surviving count goes too.
     */
    public function testTheComplementaryCellIsSuppressedWithIt(): void
    {
        [$row] = CellSuppressionPolicy::ofMinimum(5)->applyToRow(
            ['name' => 'Cohort A', 'enrolled' => 40, 'active' => 9, 'completed' => 2],
            self::columns(),
        );

        self::assertNull($row['completed'], 'the small cell');
        self::assertNull($row['active'], 'and the next smallest, or the first is recoverable');
        self::assertSame(40, $row['enrolled']);
    }

    public function testTwoSmallCellsNeedNoThird(): void
    {
        [$row, $hidden] = CellSuppressionPolicy::ofMinimum(5)->applyToRow(
            ['name' => 'Cohort A', 'enrolled' => 40, 'active' => 3, 'completed' => 2],
            self::columns(),
        );

        self::assertSame(2, $hidden);
        self::assertSame(40, $row['enrolled']);
    }

    public function testAZeroIsNotASmallCell(): void
    {
        [$row, $hidden] = CellSuppressionPolicy::ofMinimum(5)->applyToRow(
            ['name' => 'Cohort A', 'enrolled' => 40, 'completed' => 0],
            self::columns(),
        );

        self::assertSame(0, $hidden, 'nobody is identified by a count of nobody');
        self::assertSame(0, $row['completed']);
    }

    public function testOnlyCountColumnsAreTouched(): void
    {
        [$row] = CellSuppressionPolicy::ofMinimum(500)->applyToRow(
            ['name' => 'Cohort A', 'enrolled' => 40, 'completed' => 2],
            self::columns(),
        );

        self::assertSame('Cohort A', $row['name'], 'a name is not a small cell');
    }

    public function testSuppressionOffIsAPolicyAndNotAnAbsence(): void
    {
        $policy = CellSuppressionPolicy::none();

        self::assertFalse($policy->isActive());

        [$row, $hidden] = $policy->applyToRow(['enrolled' => 1], self::columns());

        self::assertSame(0, $hidden);
        self::assertSame(1, $row['enrolled']);
    }

    /** The summary block is exactly as disclosing as a row and is where per-report suppression forgets to run. */
    public function testTheSameRuleAppliesToAFactsBlock(): void
    {
        [$facts, $hidden] = CellSuppressionPolicy::ofMinimum(5)
            ->applyToFacts(['courses' => 12, 'enrolled' => 40, 'completed' => 3], ['enrolled', 'completed']);

        self::assertGreaterThan(0, $hidden);
        self::assertNull($facts['completed']);
        self::assertSame(12, $facts['courses'], 'a key that is not a declared count is untouched');
    }

    public function testANegativeMinimumIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CellSuppressionPolicy::ofMinimum(-1);
    }

    /** @return list<ColumnDefinition> */
    private static function columns(): array
    {
        return [
            ColumnDefinition::of('name', 'Name'),
            ColumnDefinition::count('enrolled', 'Enrolled'),
            ColumnDefinition::count('active', 'Active'),
            ColumnDefinition::count('completed', 'Completed'),
        ];
    }
}
