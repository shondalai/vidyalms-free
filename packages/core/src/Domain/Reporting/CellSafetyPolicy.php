<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

/**
 * Neutralises spreadsheet formulas in human-readable exports.
 * Machine exports can explicitly select the verbatim policy instead.
 */
final class CellSafetyPolicy
{
    public const MODE_SPREADSHEET_SAFE = 'spreadsheet-safe';

    public const MODE_VERBATIM = 'verbatim';

    /**
     * The ASCII triggers. `-` is here because a leading minus starts a formula
     * in Excel exactly as `=` does, and a negative number is therefore
     * deliberately neutralised too: an export writes money as integer minor
     * units in a machine profile, so a leading minus in a text cell is a value
     * somebody typed rather than an amount we computed.
     */
    private const ASCII_TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * The full-width forms of the same four operators, U+FF1D, U+FF0B, U+FF0D
     * and U+FF20. A locale that produces them produces a cell a spreadsheet
     * still evaluates, and a scan written over single bytes never sees one.
     */
    private const WIDE_TRIGGERS = ["\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}"];

    /** An apostrophe makes the text safe and repeat application idempotent. */
    public const PREFIX = "'";

    private function __construct(public readonly string $mode)
    {
    }

    public static function spreadsheetSafe(): self
    {
        return new self(self::MODE_SPREADSHEET_SAFE);
    }

    /** The bytes as stored, for a pipeline rather than a person. */
    public static function verbatim(): self
    {
        return new self(self::MODE_VERBATIM);
    }

    /** @throws \InvalidArgumentException on a mode nobody ships */
    public static function of(string $mode): self
    {
        return match ($mode) {
            self::MODE_SPREADSHEET_SAFE => self::spreadsheetSafe(),
            self::MODE_VERBATIM => self::verbatim(),
            default => throw new \InvalidArgumentException(sprintf(
                'A cell safety mode is "%s" or "%s"; "%s" is neither.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MODE_SPREADSHEET_SAFE,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MODE_VERBATIM,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $mode,
            )),
        };
    }

    /**
     * Whether this value would be read as a formula.
     *
     * Two passes, and the second is the one a single-pass implementation gets
     * wrong. A tab, a carriage return or a line feed is a trigger **in its own
     * right**, so the raw first character is tested first; then leading
     * whitespace is skipped and the operators are tested again, because
     * " =1+1" is a formula in Excel and a scan anchored on the first byte does
     * not see it. Skipping the whitespace first instead would turn "\tSUM",
     * which is a trigger, into "SUM", which is not.
     */
    public static function isDangerous(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (self::startsWithTrigger($value)) {
            return true;
        }

        $trimmed = ltrim($value, " \t\r\n\0\x0B\x0C");

        return $trimmed !== '' && self::startsWithTrigger($trimmed);
    }

    private static function startsWithTrigger(string $value): bool
    {
        foreach ([...self::ASCII_TRIGGERS, ...self::WIDE_TRIGGERS] as $trigger) {
            if (str_starts_with($value, $trigger)) {
                return true;
            }
        }

        return false;
    }

    /** The cell as it should be written under this policy. */
    public function apply(string $value): string
    {
        if ($this->mode === self::MODE_VERBATIM) {
            return $value;
        }

        return self::isDangerous($value) ? self::PREFIX . $value : $value;
    }

    /**
     * @param array<int|string, string> $cells
     *
     * @return array<int|string, string>
     */
    public function applyAll(array $cells): array
    {
        return array_map($this->apply(...), $cells);
    }

    public function isVerbatim(): bool
    {
        return $this->mode === self::MODE_VERBATIM;
    }
}
