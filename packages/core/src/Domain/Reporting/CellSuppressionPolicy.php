<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * Small-cell suppression, applied identically on screen and in the export.
 *
 * The name is deliberate and is not `SuppressionPolicy`. Suppression in this
 * product already means two unrelated things: a notification is suppressed when
 * a policy denies it, and a cell is suppressed when it names too few people.
 * One class called `SuppressionPolicy` sitting beside `NotificationPolicy`
 * would be read as the first by everyone who met it second.
 *
 * The minimum is a setting rather than a constant. The education-statistics
 * convention the US Department of Education's Privacy Technical Assistance
 * Center documents is that 5 or 10 are common and **no federal threshold is
 * mandated**, so the institution chooses one and documents it. Five is the
 * shipped default and `reports.suppression_min_cell` is where a site changes
 * it.
 *
 * **Complementary suppression is the half that is always forgotten.** If one
 * cell in a row is suppressed and only one, the number is recoverable by
 * subtracting the visible cells from the row total, so the next smallest value
 * has to go too. A policy that hides the small cell and leaves the arithmetic
 * intact has published the number it hid. The complement is chosen from the
 * counts that were themselves above the minimum: a zero sorts first and hides
 * nothing, so paying the complement out of one costs a real figure and buys no
 * ambiguity at all.
 *
 * **Derived columns go with their inputs, for the same reason one sentence
 * further on.** A rate is arithmetic over the cells beside it, so a suppressed
 * numerator published as a share of a visible denominator is one division away
 * from being read back. A column says what it is computed from through
 * {@see ColumnDefinition::derived()}, and this stage blanks it whenever any of
 * those inputs was blanked. That is the whole reason the rule lives in one
 * class: eleven reports each remembering to hide their own ratio is ten reports
 * that remember and one that does not.
 *
 * It applies on screen and in the CSV because an aggregate safe on screen and
 * unsafe in a download is the same disclosure. GDPR Recital 26 puts truly
 * anonymous information outside the regulation, but the test is identification
 * by all means reasonably likely to be used, **including singling out**, which
 * small cohorts routinely fail.
 */
final class CellSuppressionPolicy
{
    /** What a suppressed cell reads as. */
    public const REDACTION = null;

    /** The convention's lower common choice, and the shipped default. */
    public const DEFAULT_MINIMUM = 5;

    private function __construct(public readonly int $minimum)
    {
    }

    /** Suppression switched off, for a run whose rows name nobody. */
    public static function none(): self
    {
        return new self(0);
    }

    public static function ofMinimum(int $minimum): self
    {
        if ($minimum < 0) {
            throw new \InvalidArgumentException('A minimum cell size is zero or more.');
        }

        return new self($minimum);
    }

    public function isActive(): bool
    {
        return $this->minimum > 0;
    }

    /**
     * One row, with every count below the minimum removed and the
     * complementary cell removed with it.
     *
     * Only columns declaring themselves counts are touched. A learner name is
     * not a small cell and blanking it would be a different feature wearing
     * this one's name.
     *
     * @param array<string, mixed>   $row
     * @param list<ColumnDefinition> $columns
     *
     * @return array{0: array<string, mixed>, 1: int} the row and how many cells were suppressed
     */
    public function applyToRow(array $row, array $columns): array
    {
        if (!$this->isActive()) {
            return [$row, 0];
        }

        $counts = [];

        foreach ($columns as $column) {
            if (!$column->isSuppressible() || !\array_key_exists($column->key, $row)) {
                continue;
            }

            $value = $row[$column->key];

            if (is_numeric($value)) {
                $counts[$column->key] = (int) $value;
            }
        }

        $below = array_keys(array_filter(
            $counts,
            fn (int $value): bool => $value > 0 && $value < $this->minimum,
        ));

        if ($below === []) {
            return [$row, 0];
        }

        // Complementary suppression. One hidden cell in a row whose other
        // cells are visible is a hidden cell anybody can compute, so the next
        // smallest surviving count goes with it.
        //
        // A zero is never a candidate. Zero sorts first, so a row carrying one
        // always paid its complement out of a cell that was public information
        // anyway: the second cell was blanked, the reader lost a real figure
        // and the small count stayed recoverable from the counts still on the
        // page. The complement has to be a count that was actually hiding
        // something.
        if (\count($below) === 1) {
            $survivors = array_filter(
                array_diff_key($counts, array_flip($below)),
                fn (int $value): bool => $value >= $this->minimum,
            );

            if ($survivors !== []) {
                asort($survivors);
                $below[] = (string) array_key_first($survivors);
            }
        }

        foreach ($below as $key) {
            $row[$key] = self::REDACTION;
        }

        // The arithmetic taken on the cells that were just hidden. A rate
        // published beside its own suppressed numerator is the number it hid:
        // 0.075 beside a started count of 40 names the three conversions the
        // policy blanked, exactly, by one division. The columns say what they
        // are computed from, so this stage blanks them here rather than in
        // eleven reports that each have to remember.
        $hidden = array_flip($below);
        $derived = 0;

        foreach ($columns as $column) {
            if (!$column->isDerived() || !\array_key_exists($column->key, $row)) {
                continue;
            }

            $inputs = array_intersect_key($hidden, array_flip($column->derivedFrom));

            if ($row[$column->key] === self::REDACTION || $inputs === []) {
                continue;
            }

            $row[$column->key] = self::REDACTION;
            $derived++;
        }

        return [$row, \count($below) + $derived];
    }

    /**
     * The same rule over a flat map of facts, for the scalar half of a report.
     *
     * A summary block is exactly as disclosing as a row and is exactly where
     * per-report suppression forgets to run.
     *
     * @param array<string, mixed> $facts
     * @param list<string>         $countKeys
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public function applyToFacts(array $facts, array $countKeys): array
    {
        $columns = array_map(
            static fn (string $key): ColumnDefinition => ColumnDefinition::count($key, $key),
            $countKeys,
        );

        return $this->applyToRow($facts, $columns);
    }
}
