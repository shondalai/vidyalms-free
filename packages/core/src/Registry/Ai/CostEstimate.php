<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What a call is expected to cost, denominated in the unit the bridge
 * declares rather than in a metering service's own product word.
 *
 * The pair with CostRecord is deliberate: an estimate is what a ceiling is
 * checked against before the call, and a record is what the run settles
 * afterwards. An unknown estimate is not zero, and the ceiling check that
 * meets one falls back to the route's declared per-run maximum rather than
 * to an optimistic guess.
 */
final class CostEstimate
{
    private function __construct(
        public readonly string $unit,
        public readonly ?float $amount,
        public readonly bool $isUpperBound,
    ) {
    }

    public static function of(string $unit, float $amount, bool $isUpperBound = false): self
    {
        return new self($unit, $amount, $isUpperBound);
    }

    /** A bridge or an action that cannot say. Never rendered as zero. */
    public static function unknown(string $unit = CostRecord::UNIT_NONE): self
    {
        return new self($unit, null, false);
    }

    /** An action the design calls free, which is a real answer and not an absence. */
    public static function free(): self
    {
        return new self(CostRecord::UNIT_NONE, 0.0, true);
    }

    public function isKnown(): bool
    {
        return $this->amount !== null;
    }
}
