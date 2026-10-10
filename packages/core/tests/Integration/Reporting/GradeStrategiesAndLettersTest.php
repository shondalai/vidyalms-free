<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\DropLowestGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\ExtraCreditGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\NaturalGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\WeightedMeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeDisplay\LetterGradeDisplay;
use Shondalai\VidyaLMS\Core\Provider\GradeDisplay\PercentageGradeDisplay;
use Shondalai\VidyaLMS\Core\Provider\GradeDisplay\RealGradeDisplay;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The three strategies and the three display modes the gradebook's own columns
 * already implied, over the same three-cell fixture `natural` and `mean` are
 * pinned on.
 *
 * `weight_bp`, `drop_lowest` and `display_mode` were written to the database,
 * read back into `GradeItem` and rendered into the API answer from the day the
 * gradebook shipped, and nothing folded or formatted any of them: a site
 * setting weights got a plain mean, a site setting `drop_lowest` got every
 * cell counted, and a site setting `letter` got a decimal. Those are not
 * missing features, they are stored settings the build could not honour, which
 * is why all six ship free.
 *
 * One fixture across every strategy on purpose. Three items out of 10, 20 and
 * 30 scored 5, 20 and 15 is 40 of 60 under `natural` and the same 40 under
 * `mean`; the value of the fixture is that the strategies that disagree do so
 * for a reason a reader can name.
 */
final class GradeStrategiesAndLettersTest extends TestCase
{
    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new CoreKernel(TestPorts::create()->ports);
    }

    /**
     * @return list<GradeCell>
     */
    private static function fixture(int $weightA = 10000, int $weightB = 10000, int $weightC = 10000): array
    {
        return [
            GradeCell::of('a', 5.0, 10.0, 0.0, $weightA),
            GradeCell::of('b', 20.0, 20.0, 0.0, $weightB),
            GradeCell::of('c', 15.0, 30.0, 0.0, $weightC),
        ];
    }

    /** Every one of the six is registered by the free build, with no Pro attached. */
    public function testTheFreeBuildRegistersAllSixProviders(): void
    {
        foreach (
            [
                NaturalGradeAggregation::KEY,
                MeanGradeAggregation::KEY,
                WeightedMeanGradeAggregation::KEY,
                DropLowestGradeAggregation::KEY,
                ExtraCreditGradeAggregation::KEY,
            ] as $key
        ) {
            self::assertTrue($this->kernel->registries->gradeAggregations->has($key), $key . ' is not registered.');
        }

        foreach ([RealGradeDisplay::KEY, PercentageGradeDisplay::KEY, LetterGradeDisplay::KEY] as $key) {
            self::assertTrue($this->kernel->registries->gradeDisplays->has($key), $key . ' is not registered.');
        }
    }

    /**
     * With equal weights the weighted mean is the mean, which is the property
     * that says the two are one family and not two arithmetics.
     */
    public function testWeightedMeanWithEqualWeightsIsTheMean(): void
    {
        $weighted = $this->total(WeightedMeanGradeAggregation::KEY, self::fixture());
        $mean = $this->total(MeanGradeAggregation::KEY, self::fixture());

        self::assertNotNull($weighted);
        self::assertNotNull($mean);
        self::assertEqualsWithDelta($mean->value, $weighted->value, 0.0001);
        self::assertEqualsWithDelta(40.0, $weighted->value, 0.0001);
    }

    /**
     * And with unequal weights it is not, in the direction the weights point.
     *
     * The middle item is the full-marks one, so tripling its weight has to
     * raise the total. A strategy that ignored `weight_bp` would answer the
     * mean's 40 here, which is exactly the defect this provider closes.
     */
    public function testWeightedMeanFollowsTheWeightsOnTheCells(): void
    {
        $total = $this->total(WeightedMeanGradeAggregation::KEY, self::fixture(10000, 30000, 10000));

        self::assertNotNull($total);
        // (0.5*1 + 1.0*3 + 0.5*1) / 5 = 0.8, over a range of 60.
        self::assertEqualsWithDelta(48.0, $total->value, 0.0001);
        self::assertSame(30000, $total->cellWeightBp['b']);
    }

    /** A category whose every counted weight is zero has no mean, and says so. */
    public function testWeightedMeanWithNoWeightAtAllIsEmptyRatherThanADivisionByZero(): void
    {
        $total = $this->total(WeightedMeanGradeAggregation::KEY, self::fixture(0, 0, 0));

        self::assertNotNull($total);
        self::assertTrue($total->empty);
        self::assertNull($total->percentage());
    }

    /**
     * Dropping is by fraction and never by raw points, and the dropped cell is
     * named rather than silently missing.
     *
     * Over the fixture the two half-marks cells are `a` (5 of 10) and `c` (15
     * of 30). A rule comparing raw points would drop `a` first and then `b`,
     * because 5 < 15 < 20; the right answer drops one of the two halves and
     * keeps the full-marks cell.
     */
    public function testDropLowestRemovesTheWorstFractionAndNamesIt(): void
    {
        $total = $this->total(
            DropLowestGradeAggregation::KEY,
            self::fixture(),
            GradeAggregationContext::create('c1', false, 0.0, null, [DropLowestGradeAggregation::PARAM_DROP => 1]),
        );

        self::assertNotNull($total);
        self::assertSame(GradeCell::STATUS_DROPPED, $total->cellStatus['a']);
        self::assertSame(GradeCell::STATUS_USED, $total->cellStatus['b']);
        self::assertSame(GradeCell::STATUS_USED, $total->cellStatus['c']);
        // (1.0 + 0.5) / 2 = 0.75 over the surviving range of 50.
        self::assertEqualsWithDelta(37.5, $total->value, 0.0001);
        self::assertEqualsWithDelta(75.0, (float) $total->percentage(), 0.001);
    }

    /** With no count configured it is the plain mean over the same fixture. */
    public function testDropLowestWithNoCountIsTheMean(): void
    {
        $dropped = $this->total(DropLowestGradeAggregation::KEY, self::fixture());
        $mean = $this->total(MeanGradeAggregation::KEY, self::fixture());

        self::assertNotNull($dropped);
        self::assertNotNull($mean);
        self::assertEqualsWithDelta($mean->value, $dropped->value, 0.0001);
    }

    /**
     * At least one cell survives a count that would remove them all, because a
     * learner who submitted everything must not read "nothing counted".
     */
    public function testDropLowestNeverDropsEveryCell(): void
    {
        $total = $this->total(
            DropLowestGradeAggregation::KEY,
            self::fixture(),
            GradeAggregationContext::create('c1', false, 0.0, null, [DropLowestGradeAggregation::PARAM_DROP => 9]),
        );

        self::assertNotNull($total);
        self::assertFalse($total->empty);
        // Only the best cell is left: 20 of 20.
        self::assertEqualsWithDelta(20.0, $total->value, 0.0001);
        self::assertEqualsWithDelta(100.0, (float) $total->percentage(), 0.001);
    }

    /**
     * Extra credit adds to the numerator and not to the denominator, which is
     * the one rule that cannot be written as a weight.
     *
     * Over the fixture, `natural` answers 40 of 60. Marking the twenty-mark
     * item as bonus leaves a maximum of 40 and the same 40 marks earned, which
     * is a full total; a weight-based reading of the same intent would either
     * remove those marks or keep them in the maximum.
     */
    public function testExtraCreditRaisesTheTotalWithoutRaisingTheMaximum(): void
    {
        $plain = $this->total(NaturalGradeAggregation::KEY, self::fixture());
        $bonus = $this->total(
            ExtraCreditGradeAggregation::KEY,
            self::fixture(),
            GradeAggregationContext::create('c1', false, 0.0, null, [
                ExtraCreditGradeAggregation::PARAM_ITEMS => ['b'],
            ]),
        );

        self::assertNotNull($plain);
        self::assertNotNull($bonus);
        self::assertEqualsWithDelta(60.0, $plain->maximum, 0.0001);
        self::assertEqualsWithDelta(40.0, $bonus->maximum, 0.0001);
        self::assertEqualsWithDelta(40.0, $bonus->value, 0.0001);
        self::assertEqualsWithDelta(100.0, (float) $bonus->percentage(), 0.001);
    }

    /** And the total is capped at the maximum, so a bonus cannot exceed 100 per cent. */
    public function testExtraCreditIsCappedAtTheCategoryMaximum(): void
    {
        $total = $this->total(
            ExtraCreditGradeAggregation::KEY,
            self::fixture(),
            GradeAggregationContext::create('c1', false, 0.0, null, [
                ExtraCreditGradeAggregation::PARAM_ITEMS => ['b', 'c'],
            ]),
        );

        self::assertNotNull($total);
        // Only `a` counts towards the maximum, so 10; 40 marks were earned.
        self::assertEqualsWithDelta(10.0, $total->maximum, 0.0001);
        self::assertEqualsWithDelta(10.0, $total->value, 0.0001);
        self::assertEqualsWithDelta(100.0, (float) $total->percentage(), 0.001);
    }

    /** With nothing marked as bonus it is `natural` exactly. */
    public function testExtraCreditWithNoBonusItemsIsNatural(): void
    {
        $bonus = $this->total(ExtraCreditGradeAggregation::KEY, self::fixture());
        $natural = $this->total(NaturalGradeAggregation::KEY, self::fixture());

        self::assertNotNull($bonus);
        self::assertNotNull($natural);
        self::assertEqualsWithDelta($natural->value, $bonus->value, 0.0001);
        self::assertEqualsWithDelta($natural->maximum, $bonus->maximum, 0.0001);
    }

    /**
     * The three display modes over one grade, so a reader can see that 40 of
     * 60 is 66.67 per cent is a D.
     */
    public function testTheThreeDisplayModesWriteTheSameGradeThreeWays(): void
    {
        $displays = $this->kernel->registries->gradeDisplays;

        self::assertSame('40.00', $displays->render(RealGradeDisplay::KEY, 40.0, 60.0, 66.6667, 2));
        self::assertSame('66.67%', $displays->render(PercentageGradeDisplay::KEY, 40.0, 60.0, 66.6667, 2));
        self::assertSame('D', $displays->render(LetterGradeDisplay::KEY, 40.0, 60.0, 66.6667, 2));
    }

    /** Every band of the published scale, read at its own floor and just under it. */
    public function testEveryLetterBandIsReachable(): void
    {
        $displays = $this->kernel->registries->gradeDisplays;
        $seen = [];

        foreach (LetterGradeDisplay::BANDS as [$floor, $letter]) {
            $seen[] = $displays->render(LetterGradeDisplay::KEY, $floor, 100.0, $floor, 0);
            self::assertSame($letter, $seen[\count($seen) - 1]);
        }

        self::assertSame(\count(LetterGradeDisplay::BANDS), \count(array_unique($seen)));
        self::assertSame('A', $displays->render(LetterGradeDisplay::KEY, 100.0, 100.0, 100.0, 0));
        self::assertSame('F', $displays->render(LetterGradeDisplay::KEY, 0.0, 100.0, 59.9, 0));
    }

    /**
     * Nothing counted has no letter and no percentage, because an F for an
     * unsubmitted assignment is the wrong answer.
     */
    public function testAnEmptyCategoryHasNoLetterAndNoPercentage(): void
    {
        $displays = $this->kernel->registries->gradeDisplays;

        self::assertSame(LetterGradeDisplay::EMPTY, $displays->render(LetterGradeDisplay::KEY, 0.0, 60.0, null, 2));
        self::assertSame(
            PercentageGradeDisplay::EMPTY,
            $displays->render(PercentageGradeDisplay::KEY, 0.0, 60.0, null, 2),
        );
    }

    /**
     * The display registry's miss direction is the opposite of the aggregation
     * registry's, and both are deliberate: a missing display cannot change a
     * total, so it shows the number; a missing aggregator can only be guessed
     * at, so it refuses.
     */
    public function testAnUnregisteredDisplayShowsTheNumberWhileAnUnregisteredAggregatorRefuses(): void
    {
        self::assertSame(
            '40.00',
            $this->kernel->registries->gradeDisplays->render('nobody-installed-this', 40.0, 60.0, 66.6667, 2),
        );
        self::assertNull($this->total('nobody-installed-this-either', self::fixture()));
    }

    /**
     * @param list<GradeCell> $cells
     */
    private function total(
        string $key,
        array $cells,
        ?GradeAggregationContext $context = null,
    ): ?\Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal {
        return $this->kernel->registries->gradeAggregations->total(
            $key,
            $cells,
            $context ?? GradeAggregationContext::create('c1'),
        );
    }
}
