<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * The two things the AI seam was still missing while it is free to change.
 *
 * The cancellation signal is the one member a task cannot be given
 * retroactively: a long-running task's inner loop has to have been written to
 * ask, and a member added after tasks exist is a member every one of them
 * ignores. And the cost unit carried a metering service's own product word
 * into a core provider-facing contract, which is exactly the leak the
 * vocabulary rule for this package exists to catch.
 */
final class AiSeamCorrectionTest extends TestCase
{
    public function testATaskCanAskWhetherItsRunHasBeenStopped(): void
    {
        $context = $this->context();

        self::assertFalse($context->isCancelled());

        $stopped = $this->context(CancellationSignal::cancelled());

        self::assertTrue($stopped->isCancelled());
    }

    public function testTheSignalIsAskedRatherThanCapturedAtConstruction(): void
    {
        $stopped = false;
        $context = $this->context(CancellationSignal::from(static function () use (&$stopped): bool {
            return $stopped;
        }));

        self::assertFalse($context->isCancelled());

        // A stop control hit after the task started is the case the signal
        // exists for, so a value read once at construction would be useless.
        $stopped = true;

        self::assertTrue($context->isCancelled());
    }

    public function testACallerWithNothingToCancelAgainstGetsTheHonestDefault(): void
    {
        self::assertFalse($this->context()->isCancelled());
        self::assertFalse(CancellationSignal::never()->isCancelled());
    }

    public function testACostUnitNamesNoMeteringVendorsProductWord(): void
    {
        foreach (self::units() as $unit) {
            self::assertStringNotContainsString('credit', $unit);
        }

        self::assertSame('provider_units', CostRecord::UNIT_PROVIDER_UNITS);
    }

    /**
     * The docblock an implementer reads and the constants the core defines
     * are one list or they are a defect: the interface told the implementer
     * to return a unit the core does not declare, and a returned "credits"
     * would have been stored and reported by everything downstream.
     */
    public function testTheBridgeContractNamesExactlyTheUnitsTheCoreDeclares(): void
    {
        $docblock = (new \ReflectionMethod(ModelBridgeInterface::class, 'unit'))->getDocComment();

        self::assertIsString($docblock);
        self::assertStringNotContainsString('credit', $docblock);
        self::assertGreaterThan(0, preg_match_all('/`([a-z_]+)`/', $docblock, $matches));

        $named = array_values(array_unique($matches[1]));
        $declared = self::units();
        sort($named);
        sort($declared);

        self::assertSame($declared, $named);
    }

    /**
     * @return list<string>
     */
    private static function units(): array
    {
        return [
            CostRecord::UNIT_PROVIDER_UNITS,
            CostRecord::UNIT_TOKENS,
            CostRecord::UNIT_USD_MICROS,
            CostRecord::UNIT_NONE,
        ];
    }

    public function testAnUnknownCostIsNotZero(): void
    {
        // A bridge that meters nothing must not record zero spend and pass
        // every ceiling, which is what the vendor unit's arrival caused.
        $unknown = CostRecord::unknown(CostRecord::UNIT_PROVIDER_UNITS);

        self::assertFalse($unknown->isKnown());
        self::assertTrue(CostRecord::none()->isKnown());
    }

    private function context(?CancellationSignal $cancellation = null): AiTaskContext
    {
        return AiTaskContext::create(
            'caller-1',
            'run-1',
            new \DateTimeImmutable('2026-05-05 12:00:00'),
            new \DateTimeImmutable('2026-05-05 12:05:00'),
            null,
            null,
            1,
            'en-GB',
            null,
            false,
            1,
            [],
            $cancellation,
        );
    }
}
