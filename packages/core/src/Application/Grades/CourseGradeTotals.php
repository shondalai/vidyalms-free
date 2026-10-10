<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationRegistry;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;

/**
 * The only arithmetic over grade cells in the product.
 *
 * There is no fold, no sum and no average of a grade anywhere else. Every
 * category total, every course total and the cached course average all come
 * through {@see GradeAggregationRegistry}, so a site that installs a weighted
 * strategy gets it everywhere at once, and a site that installs none gets a
 * visible absence rather than a silent fallback. A second place that added up
 * grades would be a second answer to one question, and the day the two disagree
 * is the day a learner notices before anybody else does.
 *
 * **Canvas's two totals, both named.** `current` counts graded work only;
 * `final` counts outstanding work as zero. Shipping one number and leaving the
 * reader to infer which it is is the most common gradebook complaint in both
 * Moodle and Canvas support traffic, so both are computed and both are named.
 * The difference is nothing but the category's reading of an empty grade, which
 * is why it is a parameter of the context rather than two code paths.
 */
final class CourseGradeTotals
{
    public function __construct(
        private readonly GradeBookStore $store,
        private readonly GradeAggregationRegistry $aggregations,
    ) {
    }

    /**
     * The course total for one learner, folded by the course total item's own
     * aggregation provider.
     *
     * @param list<int>|null $limitToSourceIds when given, only items whose
     *                                         source id is in this list count;
     *                                         an unreachable lesson's quiz has
     *                                         left the course and must stop
     *                                         steering the number
     */
    public function courseTotal(
        int $courseId,
        int $userId,
        bool $countOutstandingAsZero = false,
        ?string $sourceKind = null,
        ?array $limitToSourceIds = null,
    ): ?CategoryTotal {
        $items = [];

        foreach ($this->store->itemsForCourse($courseId) as $item) {
            if ($item->isTotal()) {
                continue;
            }

            if ($sourceKind !== null && $item->sourceKind !== $sourceKind) {
                continue;
            }

            if ($limitToSourceIds !== null && !\in_array($item->sourceId, $limitToSourceIds, true)) {
                continue;
            }

            $items[(int) $item->id] = $item;
        }

        if ($items === []) {
            return null;
        }

        $cells = [];

        foreach ($this->store->cellsFor($courseId, $userId) as $cell) {
            $item = $items[$cell->gradeItemId] ?? null;

            if ($item === null) {
                continue;
            }

            $cells[] = $cell->forAggregation('item:' . $cell->gradeItemId, $item->weightBp);
        }

        if ($cells === []) {
            return null;
        }

        $root = $this->rootOf($courseId, $items);
        // No declared range, so the strategy derives one from the cells that
        // counted, which is what `natural`'s own docblock says it does.
        //
        // This used to pass the sum of every cell's maximum, including the
        // cells that do not count. `current` counts graded work only, so its
        // counted fraction was mapped into the whole course's range and came
        // out above `final` on the same marks: two quizzes at 80 and 40 out of
        // 100, plus an ungraded 50-mark essay, read current 150 and final 120.
        // A learner cannot reproduce 150 by adding up anything in front of
        // them, which is the one property natural exists to have.
        $context = GradeAggregationContext::create(
            'course:' . $courseId,
            $countOutstandingAsZero || $root->treatsEmptyAsZero(),
            0.0,
            null,
        );

        // The miss direction is the registry's own and it is closed and
        // visible: an unresolved key answers null, every cell reads as unknown,
        // and the screen names the missing provider. It never falls back to
        // another strategy, because a total computed under a rule nobody asked
        // for is a grade dispute nobody can trace.
        return $this->aggregations->total($root->aggregationKey, $cells, $context);
    }

    /**
     * The cached course average, as a percentage, computed from grade cells.
     *
     * `course_progress.quiz_average` used to be the only cached number in the
     * product and it was computed by summing attempt percentages in the
     * progress recalculation itself. It is now derived from the gradebook,
     * through the aggregation registry like everything else, so the number on
     * the progress bar and the number in the gradebook cannot disagree.
     *
     * Null means the gradebook has nothing to say: no items, no cells, or no
     * provider registered under the course total's aggregation key. A caller
     * with a legacy path of its own keeps it for exactly that case.
     *
     * @param list<int>|null $limitToSourceIds
     */
    public function averagePercentage(
        int $courseId,
        int $userId,
        string $sourceKind = GradeItem::SOURCE_QUIZ,
        ?array $limitToSourceIds = null,
    ): ?float {
        $total = $this->courseTotal($courseId, $userId, false, $sourceKind, $limitToSourceIds);

        if ($total === null) {
            return null;
        }

        $percentage = $total->percentage();

        return $percentage === null ? null : round($percentage, 2);
    }

    /**
     * The course total item, or a synthetic one carrying the shipped defaults.
     *
     * A course whose gradebook was filled by the listeners alone has activity
     * items and no root, and refusing to total it would mean the free build's
     * own path could never produce a number. The synthetic root is `natural`
     * with the excluding reading of an empty grade, which is what the shipped
     * average already did.
     *
     * @param array<int, GradeItem> $items
     */
    private function rootOf(int $courseId, array $items): GradeItem
    {
        foreach ($this->store->itemsForCourse($courseId) as $item) {
            if ($item->itemType === GradeItem::TYPE_COURSE_TOTAL) {
                return $item;
            }
        }

        return GradeItem::courseTotal($courseId, 'Course total');
    }
}
