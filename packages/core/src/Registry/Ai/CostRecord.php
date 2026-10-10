<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What a run cost, denominated in a unit the bridge declares rather than in
 * credits.
 *
 * A metering service's own product unit is a vendor word, and putting it on a
 * provider-facing contract is how a bring-your-own-key bridge that meters
 * nothing records zero spend forever and passes every ceiling. So the unit is
 * carried beside the amount, and the amount may be unknown: a bridge that
 * cannot say what it spent says so rather than saying zero.
 *
 * UNIT_PROVIDER_UNITS is the slot a metering service's own product unit
 * occupies. It is spelled that way rather than after any one service's name
 * because the core's vocabulary rule forbids a vendor word here, and that
 * rule exists precisely because this contract is where the word leaked. A
 * bridge whose upstream denominates in its own product unit declares this
 * one; what the unit is worth is the bridge's business and never the core's.
 */
final class CostRecord
{
    /**
     * **Amounts are whole units of the declared unit, and the rounding happens
     * here.**
     *
     * The domain carries a float and every stored column is an unsigned
     * integer: `ai_runs.cost_reserved`, `ai_runs.cost_charged`,
     * `ai_run_attempts.cost_charged` and `ai_routes.max_cost_per_run`. Without
     * a stated rule a bridge denominating in `provider_units` or `usd_micros`
     * with a fractional amount was truncated or rounded by whichever driver
     * was underneath, and the windowed sum then under-counted against a
     * ceiling that had been compared as floats.
     *
     * So the rule is one named place: an amount is rounded half up to a whole
     * unit at construction, and a bridge whose upstream meters in fractions
     * declares a finer unit rather than sending a fraction of a coarse one.
     * `usd_micros` exists for exactly that reason.
     */
    public const UNIT_PROVIDER_UNITS = 'provider_units';
    public const UNIT_TOKENS = 'tokens';
    public const UNIT_USD_MICROS = 'usd_micros';
    public const UNIT_NONE = 'none';

    private function __construct(
        public readonly string $unit,
        public readonly ?float $amount,
    ) {
    }

    public static function of(string $unit, ?float $amount): self
    {
        // Rounded here and nowhere else, so the float the ceiling compares and
        // the integer the column stores are the same number.
        return new self($unit, $amount === null ? null : round($amount));
    }

    /**
     * A run that spent nothing measurable. Distinct from an unknown amount,
     * which is what a non-metering bridge answers.
     */
    public static function none(): self
    {
        return new self(self::UNIT_NONE, 0.0);
    }

    public static function unknown(string $unit): self
    {
        return new self($unit, null);
    }

    public function isKnown(): bool
    {
        return $this->amount !== null;
    }
}
