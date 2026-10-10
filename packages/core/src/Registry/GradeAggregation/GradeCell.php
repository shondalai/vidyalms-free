<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeAggregation;

/**
 * One graded thing, as a category aggregator sees it.
 *
 * It is not {@see \Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore},
 * and that is why `GradeAggregation` is a second seam rather than a reuse of
 * the quiz aggregator. An attempt score is one learner's try at one quiz; a
 * grade cell is one item's contribution to a category total and carries a
 * snapshotted maximum, a weight, an exclusion flag and a visibility flag that
 * an attempt score has nowhere to put.
 *
 * **The maximum is snapshotted onto the cell, never read from the item.**
 * Moodle writes `rawgrade`, `rawgrademax`, `rawgrademin` and `rawscaleid` onto
 * its grade rows precisely because a teacher may change the activity maximum
 * afterwards, so a grade row is self-describing and does not depend on the
 * item's current configuration to be interpreted.
 */
final class GradeCell
{
    /** The grader has finished with it and it moves the total. */
    public const STATUS_USED = 'used';

    /** Nothing was submitted or graded; what that means is a per-category setting. */
    public const STATUS_NOVALUE = 'novalue';

    /** The category rule dropped it, which is a Pro strategy's outcome. */
    public const STATUS_DROPPED = 'dropped';

    /** No provider could answer, which is the registry-miss state. */
    public const STATUS_UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $itemKey,
        public readonly ?float $rawGrade,
        public readonly float $rawGradeMax,
        public readonly float $rawGradeMin,
        /** Basis points, so a weight is an integer and two of them sum exactly. */
        public readonly int $weightBp,
        public readonly bool $excluded,
        public readonly bool $hidden,
        public readonly string $status,
    ) {
    }

    public static function of(
        string $itemKey,
        ?float $rawGrade,
        float $rawGradeMax,
        float $rawGradeMin = 0.0,
        int $weightBp = 10000,
        bool $excluded = false,
        bool $hidden = false,
    ): self {
        if ($itemKey === '') {
            throw new \InvalidArgumentException('A grade cell names its item.');
        }

        if ($rawGradeMax <= $rawGradeMin) {
            throw new \InvalidArgumentException('A grade cell maximum must be above its minimum.');
        }

        return new self(
            $itemKey,
            $rawGrade,
            $rawGradeMax,
            $rawGradeMin,
            $weightBp,
            $excluded,
            $hidden,
            $rawGrade === null ? self::STATUS_NOVALUE : self::STATUS_USED,
        );
    }

    /**
     * Whether this cell counts, under the category's own reading of an empty
     * grade.
     *
     * The reading is a parameter and never a constant. An empty grade is
     * genuinely ambiguous (not submitted, submitted but ungraded, manually
     * deleted), so "treat empty as zero" against "exclude empty" is a
     * per-category setting: a gradebook that hard-codes one interpretation
     * produces a total that is wrong for half its users.
     */
    public function counts(bool $emptyAsZero): bool
    {
        if ($this->excluded) {
            return false;
        }

        return $this->rawGrade !== null || $emptyAsZero;
    }

    /** The value this cell contributes, with an empty grade read as the minimum. */
    public function effectiveGrade(): float
    {
        return $this->rawGrade ?? $this->rawGradeMin;
    }

    /** Zero at the minimum, one at the maximum, which is where a weighted strategy starts. */
    public function normalised(): float
    {
        return ($this->effectiveGrade() - $this->rawGradeMin) / ($this->rawGradeMax - $this->rawGradeMin);
    }
}
