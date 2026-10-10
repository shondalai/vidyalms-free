<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Grades;

use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * One learner's grade for one item, as it is stored.
 *
 * It is named apart from
 * {@see \Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell} on
 * purpose. That one is the view an aggregation provider is handed: an item key,
 * a value, a snapshotted maximum, a weight and two flags, and nothing a
 * provider has no business seeing. This one is the row, and it carries the
 * marker, the feedback, the two AGS axes, the OneRoster status and the instant
 * the score was recorded at. Handing a provider the row would let a third-party
 * aggregation strategy read a marker's identity and a learner's feedback text to
 * compute an average, which is a disclosure nobody asked for.
 * {@see forAggregation()} is the one crossing between them.
 *
 * **`rawGradeMax` is snapshotted here and is never read from the item.** It is
 * the column an implementer is tempted to drop and the one that must not be: an
 * instructor may change the item maximum afterwards, and without the snapshot an
 * old grade cannot be interpreted at all.
 */
final class StoredGradeCell
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $gradeItemId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly ?float $rawGrade,
        public readonly float $rawGradeMax,
        public readonly float $rawGradeMin,
        public readonly ?float $finalGrade,
        public readonly GradeProgress $progress,
        public readonly string $aggregationStatus,
        public readonly int $aggregationWeightBp,
        public readonly bool $excluded,
        public readonly bool $hidden,
        public readonly bool $locked,
        public readonly ?\DateTimeImmutable $overriddenAt,
        public readonly ?string $feedback,
        public readonly ?int $gradedBy,
        public readonly ?\DateTimeImmutable $gradedAt,
        public readonly ?\DateTimeImmutable $scoredAt,
    ) {
    }

    /**
     * A cell that has never been graded, which is what a roster read answers
     * for a learner who has not started.
     */
    public static function empty(int $gradeItemId, int $userId, int $courseId, float $max, float $min = 0.0): self
    {
        return new self(
            null,
            $gradeItemId,
            $userId,
            $courseId,
            null,
            $max,
            $min,
            null,
            GradeProgress::untouched(),
            GradeCell::STATUS_NOVALUE,
            0,
            false,
            false,
            false,
            null,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * The same cell carrying a new score.
     *
     * The maximum travels with the score rather than being kept from before,
     * because a regrade against a changed item maximum is a different fact and
     * the pair has to stay consistent inside one row.
     */
    public function scored(
        ?float $rawGrade,
        float $rawGradeMax,
        float $rawGradeMin,
        GradeProgress $progress,
        ?int $gradedBy,
        \DateTimeImmutable $at,
        ?string $feedback = null,
        bool $overridden = false,
        /**
         * The two flags a person may change by hand, or null to keep what is
         * stored. They are trailing and defaulted, so every listener writing a
         * grade is unchanged; the regrade route publishes both and had nothing
         * to hand them to, so they were accepted and silently dropped.
         */
        ?bool $excluded = null,
        ?bool $hidden = null,
        /**
         * Releases a standing override, which is the only way the stamp comes
         * off. It is separate from `overridden` rather than a third state of
         * it, because clearing an override and setting one are two different
         * decisions and a caller that wanted neither must change nothing.
         */
        bool $clearOverride = false,
    ): self {
        return new self(
            $this->id,
            $this->gradeItemId,
            $this->userId,
            $this->courseId,
            $rawGrade,
            $rawGradeMax,
            $rawGradeMin,
            $rawGrade,
            $progress,
            $rawGrade === null ? GradeCell::STATUS_NOVALUE : GradeCell::STATUS_USED,
            $this->aggregationWeightBp,
            $excluded ?? $this->excluded,
            $hidden ?? $this->hidden,
            $this->locked,
            $clearOverride ? null : ($overridden ? $at : $this->overriddenAt),
            $feedback ?? $this->feedback,
            $gradedBy,
            $at,
            $at,
        );
    }

    /**
     * Whether a score recorded at this instant may be written over what is
     * already here.
     *
     * This is AGS's own out-of-order protection, copied verbatim: a score whose
     * timestamp is older than the last accepted score for that user and line
     * item is rejected. A redelivered webhook, a retried job and a queue that
     * reordered two messages all arrive in exactly this shape, and without the
     * rule the answer depends on which of two identical retries lands last.
     *
     * Equal timestamps are accepted, because two events inside one second are
     * ordinary and refusing the second would drop a real regrade.
     */
    public function accepts(\DateTimeImmutable $scoredAt): bool
    {
        return $this->scoredAt === null || $scoredAt >= $this->scoredAt;
    }

    /**
     * The percentage this cell reads as, or null when it has no grade or the
     * grader has not finished with it.
     *
     * The second condition is the AGS rule that comes with the axis: a cell
     * whose gradingProgress is not FullyGraded does not move the visible total,
     * and a percentage is the visible total of one cell.
     */
    public function percentage(): ?float
    {
        if ($this->rawGrade === null || !$this->progress->countsTowardsTotal()) {
            return null;
        }

        $span = $this->rawGradeMax - $this->rawGradeMin;

        if ($span <= 0.0) {
            return null;
        }

        return ($this->rawGrade - $this->rawGradeMin) / $span * 100;
    }

    /**
     * The view an aggregation provider is handed.
     *
     * A cell the grader has not finished with is passed as an empty one rather
     * than as its provisional number, which is the same AGS rule stated where
     * the arithmetic happens: the provider then applies the category's own
     * reading of an empty grade to it, which is the only place that decision
     * belongs.
     */
    public function forAggregation(string $itemKey, int $weightBp = 10000): GradeCell
    {
        return GradeCell::of(
            $itemKey,
            $this->progress->countsTowardsTotal() ? $this->rawGrade : null,
            $this->rawGradeMax,
            $this->rawGradeMin,
            $weightBp,
            $this->excluded,
            $this->hidden,
        );
    }
}
