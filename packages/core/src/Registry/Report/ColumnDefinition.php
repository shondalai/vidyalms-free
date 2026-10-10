<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

/**
 * One column of a tabular report, described once by the provider and read by
 * everything downstream.
 *
 * The export layer needs a type to write a canonical value, the screen needs a
 * label, and the suppression policy needs to know which columns carry counts.
 * A provider that formatted its own values would produce an export that is
 * simultaneously unopenable in a German spreadsheet and unparseable by a
 * script, which is the failure the two value profiles exist to prevent, so a
 * column declares what it is and never how it should look.
 */
final class ColumnDefinition
{
    /** A whole number that is not a count of people. */
    public const TYPE_INTEGER = 'integer';

    /** A count of subjects, which is what the suppression policy acts on. */
    public const TYPE_COUNT = 'count';

    public const TYPE_STRING = 'string';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_BOOLEAN = 'boolean';

    /** An RFC 3339 instant under the machine profile, locale formatted under display. */
    public const TYPE_DATETIME = 'datetime';

    /**
     * Integer minor units. A money column always names its currency column,
     * because nothing in this product sums across currencies and a figure
     * leaving a report without its currency is the defect that is invisible
     * until the first non-home-currency sale.
     */
    public const TYPE_MONEY_MINOR = 'money_minor';

    /**
     * @param list<string> $derivedFrom
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        /**
         * The sibling column carrying the ISO 4217 code, for a money column.
         */
        public readonly ?string $currencyKey = null,
        /**
         * The columns this one is computed from.
         *
         * A rate, a share or an average is arithmetic over cells that sit
         * beside it, and small-cell suppression that hides a count and
         * publishes the ratio taken on it has published the number it hid:
         * `completion_rate` 0.25 beside an entitled count of 12 names the
         * three completions the policy blanked. Naming the inputs is what lets
         * one stage blank the derivation with them, rather than each report
         * remembering to.
         *
         * @var list<string>
         */
        public readonly array $derivedFrom = [],
    ) {
        if ($key === '') {
            throw new \InvalidArgumentException('A report column needs a key.');
        }
    }

    public static function of(string $key, string $label, string $type = self::TYPE_STRING): self
    {
        return new self($key, $label, $type);
    }

    /** A count of people or subjects, which small-cell suppression may hide. */
    public static function count(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_COUNT);
    }

    public static function money(string $key, string $label, string $currencyKey): self
    {
        return new self($key, $label, self::TYPE_MONEY_MINOR, $currencyKey);
    }

    /**
     * A value computed from other columns in the same row, which is blanked
     * whenever any of them is.
     *
     * The type is free, because a derivation is not always a decimal: a date
     * that is only ever one person's when the count beside it is one is the
     * same disclosure by another route.
     *
     * @param list<string> $inputs
     */
    public static function derived(
        string $key,
        string $label,
        array $inputs,
        string $type = self::TYPE_DECIMAL,
        ?string $currencyKey = null,
    ): self {
        if ($inputs === []) {
            throw new \InvalidArgumentException('A derived report column names the columns it is computed from.');
        }

        return new self($key, $label, $type, $currencyKey, array_values($inputs));
    }

    /** Whether small-cell suppression applies to this column. */
    public function isSuppressible(): bool
    {
        return $this->type === self::TYPE_COUNT;
    }

    /** Whether this column is arithmetic over cells the policy may have hidden. */
    public function isDerived(): bool
    {
        return $this->derivedFrom !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'currency_key' => $this->currencyKey,
            'derived_from' => $this->derivedFrom === [] ? null : $this->derivedFrom,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
