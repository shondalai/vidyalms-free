<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Grades;

/**
 * The two interchange vocabularies this gradebook adopts, rather than a third
 * of its own.
 *
 * **LTI AGS 2.0's two independent progress axes.** `activityProgress` is the
 * learner's side of a score and `gradingProgress` the grader's, and they move
 * separately: a learner may have Completed an activity that is still Pending
 * with a marker, and a marker may have FullyGraded work the learner never
 * Submitted. One "status" column cannot hold both, and every product that tries
 * ends up with a status vocabulary that means different things in different
 * rows.
 *
 * The rule that comes with them is the one worth stating: **a cell whose
 * `gradingProgress` is not FullyGraded does not move the visible total.** A
 * total that silently counted work nobody has finished marking is a total a
 * learner will act on and a marker will later contradict.
 *
 * AGS also rejects a score whose timestamp is older than the last accepted
 * score for that user and line item. That is out-of-order protection expressed
 * as a payload rule and it is worth copying verbatim, because a redelivered
 * webhook and a retried job both arrive out of order in exactly this shape;
 * {@see StoredGradeCell::accepts()} is where it lives.
 *
 * **OneRoster 1.2's `scoreStatus` vocabulary** is the second, and it answers a
 * different question from either axis: what kind of non-answer this is. A
 * missing piece of work, a late one and an exempt one are three different facts
 * about one empty cell, and a gradebook that cannot tell them apart cannot
 * explain its own total.
 */
final class GradeProgress
{
    /** LTI AGS 2.0 activityProgress. */
    public const ACTIVITY_INITIALIZED = 'Initialized';
    public const ACTIVITY_STARTED = 'Started';
    public const ACTIVITY_IN_PROGRESS = 'InProgress';
    public const ACTIVITY_SUBMITTED = 'Submitted';
    public const ACTIVITY_COMPLETED = 'Completed';

    /** @var list<string> */
    public const ACTIVITY_PROGRESS = [
        self::ACTIVITY_INITIALIZED,
        self::ACTIVITY_STARTED,
        self::ACTIVITY_IN_PROGRESS,
        self::ACTIVITY_SUBMITTED,
        self::ACTIVITY_COMPLETED,
    ];

    /** LTI AGS 2.0 gradingProgress. */
    public const GRADING_NOT_READY = 'NotReady';
    public const GRADING_FAILED = 'Failed';
    public const GRADING_PENDING = 'Pending';
    public const GRADING_PENDING_MANUAL = 'PendingManual';
    public const GRADING_FULLY_GRADED = 'FullyGraded';

    /** @var list<string> */
    public const GRADING_PROGRESS = [
        self::GRADING_NOT_READY,
        self::GRADING_FAILED,
        self::GRADING_PENDING,
        self::GRADING_PENDING_MANUAL,
        self::GRADING_FULLY_GRADED,
    ];

    /**
     * OneRoster 1.2 scoreStatus, spelled as OneRoster spells it, lower case and
     * with its spaces. A re-spelling would be a third vocabulary wearing a
     * borrowed name, which is worse than inventing one openly.
     */
    public const SCORE_EXEMPT = 'exempt';
    public const SCORE_FULLY_GRADED = 'fully graded';
    public const SCORE_NOT_SUBMITTED = 'not submitted';
    public const SCORE_PARTIALLY_GRADED = 'partially graded';
    public const SCORE_SUBMITTED = 'submitted';

    /** @var list<string> */
    public const SCORE_STATUSES = [
        self::SCORE_EXEMPT,
        self::SCORE_FULLY_GRADED,
        self::SCORE_NOT_SUBMITTED,
        self::SCORE_PARTIALLY_GRADED,
        self::SCORE_SUBMITTED,
    ];

    private function __construct(
        public readonly string $activity,
        public readonly string $grading,
        public readonly string $score,
    ) {
    }

    public static function of(string $activity, string $grading, string $score): self
    {
        if (!\in_array($activity, self::ACTIVITY_PROGRESS, true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not an activityProgress this gradebook knows.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $activity,
            ));
        }

        if (!\in_array($grading, self::GRADING_PROGRESS, true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a gradingProgress this gradebook knows.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $grading,
            ));
        }

        if (!\in_array($score, self::SCORE_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" is not a OneRoster scoreStatus.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $score,
            ));
        }

        return new self($activity, $grading, $score);
    }

    /** Nothing has happened yet, which is what a freshly created cell reads as. */
    public static function untouched(): self
    {
        return new self(self::ACTIVITY_INITIALIZED, self::GRADING_NOT_READY, self::SCORE_NOT_SUBMITTED);
    }

    /** A machine finished with it: an auto-marked quiz attempt. */
    public static function autoGraded(): self
    {
        return new self(self::ACTIVITY_COMPLETED, self::GRADING_FULLY_GRADED, self::SCORE_FULLY_GRADED);
    }

    /** A human finished with it: a marked assignment submission. */
    public static function humanGraded(): self
    {
        return new self(self::ACTIVITY_COMPLETED, self::GRADING_FULLY_GRADED, self::SCORE_FULLY_GRADED);
    }

    /** Submitted and waiting for a person. */
    public static function awaitingMarker(): self
    {
        return new self(self::ACTIVITY_SUBMITTED, self::GRADING_PENDING_MANUAL, self::SCORE_SUBMITTED);
    }

    /**
     * Whether a cell in this state moves the visible total.
     *
     * The rule is AGS's own and it is the reason the axis exists: work that is
     * not fully graded is not a nil mark, and counting it as one produces a
     * total that drops when a marker opens the file and rises when they close
     * it.
     */
    public function countsTowardsTotal(): bool
    {
        return $this->grading === self::GRADING_FULLY_GRADED;
    }
}
