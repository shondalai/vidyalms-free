<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Grades;

/**
 * One graded thing, or one category of them, or the course total.
 *
 * A category is an item of its own type rather than a fourth table. It carries
 * the aggregation key, the aggregation parameters and the per-category reading
 * of an empty grade, which is everything a category needs; splitting it out
 * would mean two tables having to agree about one tree, and the day they
 * disagree is the day a total loses a column nobody can find.
 *
 * **The item's own maximum is configuration and the cell's is a fact.** The
 * pair exists because an instructor may raise a quiz from ten marks to twenty
 * next term, and the grade a learner got last term is still out of ten. Moodle
 * writes `rawgrade`, `rawgrademax`, `rawgrademin` and `rawscaleid` onto its
 * grade rows for precisely this reason; the maximum below is what a NEW cell
 * snapshots and never what an old one is reinterpreted against.
 */
final class GradeItem
{
    public const TYPE_ACTIVITY = 'activity';
    public const TYPE_CATEGORY_TOTAL = 'category_total';
    public const TYPE_COURSE_TOTAL = 'course_total';

    /** @var list<string> */
    public const TYPES = [self::TYPE_ACTIVITY, self::TYPE_CATEGORY_TOTAL, self::TYPE_COURSE_TOTAL];

    /** What produced the item, so "which items break if this quiz goes" is a query. */
    public const SOURCE_QUIZ = 'quiz';
    public const SOURCE_ASSIGNMENT = 'assignment';
    public const SOURCE_MANUAL = 'manual';

    /** An empty grade is left out of the total. */
    public const EMPTY_EXCLUDE = 'exclude';

    /** An empty grade is folded in as the minimum. */
    public const EMPTY_AS_ZERO = 'as_zero';

    /** @var list<string> */
    public const EMPTY_POLICIES = [self::EMPTY_EXCLUDE, self::EMPTY_AS_ZERO];

    /**
     * @param array<string, mixed> $aggregationParams the provider's own shape, body only
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $courseId,
        public readonly ?int $parentItemId,
        public readonly string $itemType,
        public readonly string $sourceKind,
        public readonly int $sourceId,
        public readonly string $title,
        public readonly float $gradeMax,
        public readonly float $gradeMin,
        public readonly int $weightBp,
        public readonly bool $weightOverridden,
        public readonly bool $extraCredit,
        public readonly string $aggregationKey,
        public readonly array $aggregationParams,
        public readonly int $aggregationParamsVersion,
        public readonly string $emptyGradePolicy,
        public readonly int $dropLowest,
        public readonly string $displayMode,
        public readonly int $decimals,
        public readonly bool $hidden,
        public readonly bool $locked,
        public readonly int $position,
    ) {
    }

    /**
     * The item a grading use case creates for the thing it just graded.
     *
     * @param array<string, mixed> $aggregationParams
     */
    public static function forSource(
        int $courseId,
        string $sourceKind,
        int $sourceId,
        string $title,
        float $gradeMax,
        ?int $parentItemId = null,
        float $gradeMin = 0.0,
        int $weightBp = 10000,
        int $position = 0,
        string $aggregationKey = 'natural',
        array $aggregationParams = [],
        string $emptyGradePolicy = self::EMPTY_EXCLUDE,
    ): self {
        if ($gradeMax <= $gradeMin) {
            throw new \InvalidArgumentException('A grade item maximum is above its minimum.');
        }

        return new self(
            null,
            $courseId,
            $parentItemId,
            self::TYPE_ACTIVITY,
            $sourceKind,
            $sourceId,
            $title,
            $gradeMax,
            $gradeMin,
            $weightBp,
            false,
            false,
            $aggregationKey,
            $aggregationParams,
            1,
            $emptyGradePolicy,
            0,
            'real',
            2,
            false,
            false,
            $position,
        );
    }

    /**
     * The course total, which is the root of the tree and the item every
     * category folds into.
     */
    public static function courseTotal(
        int $courseId,
        string $title,
        string $aggregationKey = 'natural',
        string $emptyGradePolicy = self::EMPTY_EXCLUDE,
    ): self {
        return new self(
            null,
            $courseId,
            null,
            self::TYPE_COURSE_TOTAL,
            self::SOURCE_MANUAL,
            0,
            $title,
            100.0,
            0.0,
            10000,
            false,
            false,
            $aggregationKey,
            [],
            1,
            $emptyGradePolicy,
            0,
            'real',
            2,
            false,
            false,
            0,
        );
    }

    /** Whether this item is folded rather than graded. */
    public function isTotal(): bool
    {
        return $this->itemType !== self::TYPE_ACTIVITY;
    }

    /** The per-category reading of an empty grade, as the aggregation context wants it. */
    public function treatsEmptyAsZero(): bool
    {
        return $this->emptyGradePolicy === self::EMPTY_AS_ZERO;
    }
}
