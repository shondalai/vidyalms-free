<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Commerce;

/**
 * An amount in minor units beside the currency it is denominated in.
 *
 * Every monetary figure in the product is this type or a column pair shaped
 * like it (bigint minor units beside an explicit string:3 currency), never a
 * decimal and never a bare amount. A float cannot hold money and a shared
 * exponent assumption of 2 is a silent bug in JPY and in KWD, so the exponent
 * comes from the shipped ISO 4217 table below rather than from habit.
 *
 * Arithmetic between two currencies is refused rather than converted: a
 * conversion needs a rate, a rate needs a date, and a type that quietly picks
 * one is how a report sums across currencies. Where a conversion is genuinely
 * wanted the caller converts explicitly and records the rate it used.
 *
 * allocate() is Fowler's Money pattern verbatim, and it is the reason this
 * class exists rather than an int beside a string. Every proration, instalment
 * split, coupon distribution across lines, bundle revenue attribution and
 * tax-inclusive extraction goes through it, so a rounding remainder is always
 * assigned to a named recipient rather than lost. The order of the result is
 * part of the result and is therefore stored by the caller, not recomputed at
 * read time: a 70/30 split of 5 cents gives 4 and 1 while 30/70 gives 2 and 3,
 * and an invoice reprinted from a recomputation in the other order prints
 * different numbers from the one the buyer was sent.
 */
final class Money
{
    /**
     * The currencies ISO 4217 gives an exponent other than 2.
     *
     * A closed table shipped as core data rather than a lookup through a
     * library or a locale: the exponent decides what a stored integer means,
     * so it may not vary with what happens to be installed on the host.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
        // The two exponent-four codes. Neither settles anywhere, both are
        // units of account, and both are ISO 4217 all the same: a stored
        // integer read at exponent two is a hundred times too small, and a
        // table that is right for twenty-nine codes and silently wrong for two
        // is worse than one that says so.
        'CLF' => 4, 'UYW' => 4,
    ];

    /** Everything the table above does not name. */
    private const DEFAULT_EXPONENT = 2;

    public function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A currency is three uppercase ISO 4217 letters, got "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $currency,
            ));
        }
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * The ISO 4217 minor unit exponent for a currency.
     *
     * Public because the plan price form warns on an exponent mismatch (a
     * two-decimal amount typed for JPY) and because a shop bridge converting
     * a decimal at the plugin boundary needs the same number this class uses.
     */
    public static function exponentFor(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? self::DEFAULT_EXPONENT;
    }

    public function exponent(): int
    {
        return self::exponentFor($this->currency);
    }

    public function add(self $other): self
    {
        $this->requireSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->requireSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Scales by an exact ratio, rounding half away from zero.
     *
     * A ratio rather than a float because a percentage that arrives as 0.1
     * is not 0.1, and because the two integers are what a rate table, a
     * basis-point weight and a proration all actually hold.
     */
    public function multiplyByRatio(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new \InvalidArgumentException('A ratio cannot be taken over a zero denominator.');
        }

        $product = $this->minorUnits * $numerator;
        $sign = ($product < 0) !== ($denominator < 0) ? -1 : 1;
        $magnitude = intdiv(abs($product) * 2 + abs($denominator), abs($denominator) * 2);

        return new self($sign * $magnitude, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->requireSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    /**
     * Splits this amount over the given weights, assigning every remaining
     * minor unit to the leading monies **that carry a positive weight**, in
     * list order.
     *
     * The sum of the result always equals this amount exactly. The list order
     * is significant and the caller stores it, because recomputing an
     * allocation in a different order produces different numbers for the same
     * split.
     *
     * A zero weight is an exclusion and never a remainder recipient. The
     * justification for assigning the remainder at all is that it reaches a
     * named recipient rather than being lost, and a line the caller weighted
     * at zero is not a recipient: a coupon distributed across order lines with
     * one line deliberately excluded would otherwise charge that line a penny,
     * and 1 minor unit over [0, 1, 1] would pay the excluded line the whole of
     * it while both weighted lines got nothing.
     *
     * @param list<int> $weights
     *
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('An allocation needs at least one weight.');
        }

        $total = 0;

        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('An allocation weight cannot be negative.');
            }

            $total += $weight;
        }

        if ($total === 0) {
            throw new \InvalidArgumentException('An allocation over weights summing to zero has no answer.');
        }

        $remainder = $this->minorUnits;
        $shares = [];
        $recipients = [];

        foreach ($weights as $index => $weight) {
            $share = intdiv($this->minorUnits * $weight, $total);
            $shares[] = $share;
            $remainder -= $share;

            if ($weight > 0) {
                $recipients[] = $index;
            }
        }

        $step = $remainder < 0 ? -1 : 1;

        for ($i = 0; $remainder !== 0; $i++) {
            $shares[$recipients[$i % \count($recipients)]] += $step;
            $remainder -= $step;
        }

        return array_values(array_map(fn (int $share): self => new self($share, $this->currency), $shares));
    }

    /**
     * The amount as a plain decimal string at the currency's own exponent,
     * with a dot separator and no thousands separators.
     *
     * This is the machine value profile an export writes and never a display
     * string: rendering money for a person needs a locale, which is
     * LocalePort's business and not this object's.
     */
    public function decimalString(): string
    {
        $exponent = $this->exponent();

        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }

        $sign = $this->minorUnits < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minorUnits), $exponent + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    private function requireSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException(sprintf(
                'Money in %s and money in %s cannot be combined; convert explicitly and record the rate.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->currency,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $other->currency,
            ));
        }
    }
}
