<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationRegistry;
use Shondalai\VidyaLMS\Core\Registry\GradeDisplay\GradeDisplayRegistry;

/**
 * The three reads over the gradebook: its structure, its cells and its history.
 *
 * **Every page is keyset paged.** The cells sort on `(user_id, grade_item_id)`
 * and the change log on `(occurred_at, id)`, both stable, indexed and unique.
 * Offset paging over a table being written while the page is read produces
 * duplicates and gaps, and nothing in the answer says it did.
 *
 * **The arithmetic is not here.** Both totals a learner gets are folded by a
 * registered `GradeAggregation` provider through {@see CourseGradeTotals}, so a
 * site that installs a weighted strategy changes this answer without changing
 * this class. Where no provider answers a category's key, the total reports
 * `unknown` rather than a number: a guessed total is a grade dispute and an
 * absent one is a support ticket.
 */
final class ReadGradebook
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    public function __construct(
        private readonly GradeBookStore $store,
        private readonly CourseGradeTotals $totals,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
        private readonly GradeAggregationRegistry $aggregations,
        /**
         * How a grade is written down, which is a registry rather than a
         * constant because `display_mode` has stored three vocabularies since
         * the gradebook shipped and no build answered any of them.
         *
         * Optional so a caller built before this seam existed still
         * constructs; a null registry renders the raw number, which is the
         * behaviour every such caller already had.
         */
        private readonly ?GradeDisplayRegistry $displays = null,
    ) {
    }

    /**
     * The course's grade structure.
     *
     * @return array<string, mixed>
     */
    public function items(Principal $principal, int $courseId): array
    {
        $this->access->require($principal, $courseId, Capability::TEACH, Capability::GRADE, Capability::MANAGE);

        $items = [];
        $courseTotal = null;

        foreach ($this->store->itemsForCourse($courseId) as $item) {
            $rendered = $this->renderItem($item);
            $items[] = $rendered;

            if ($item->itemType === GradeItem::TYPE_COURSE_TOTAL) {
                $courseTotal = $rendered;
            }
        }

        return ['course_id' => $courseId, 'items' => $items, 'course_total' => $courseTotal];
    }

    /**
     * One page of cells, keyset paged on (user_id, grade_item_id).
     *
     * @return array<string, mixed>
     */
    public function cells(
        Principal $principal,
        int $courseId,
        ?int $userId = null,
        ?int $gradeItemId = null,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $this->access->require($principal, $courseId, Capability::TEACH, Capability::GRADE, Capability::MANAGE);

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = Query::from(GradeBookStore::CELLS)
            ->where('course_id', '=', $courseId)
            ->orderBy('user_id', Query::SORT_ASC)
            ->orderBy('grade_item_id', Query::SORT_ASC)
            ->limit($limit + 1);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', '=', $userId);
        }

        if ($gradeItemId !== null && $gradeItemId > 0) {
            $query->where('grade_item_id', '=', $gradeItemId);
        }

        $decoded = KeysetCursor::decode($cursor);

        if ($decoded !== null) {
            $afterUser = $decoded->part(0);
            $afterItem = $decoded->part(1);

            if (\is_int($afterUser) && \is_int($afterItem)) {
                // The exact lexicographic comparison on the pair, so a learner
                // whose cells straddle a page boundary loses none of them.
                $query->whereAnyOf(
                    Predicate::compare('user_id', '>', $afterUser),
                    Predicate::allOf(
                        Predicate::compare('user_id', '=', $afterUser),
                        Predicate::compare('grade_item_id', '>', $afterItem),
                    ),
                );
            }
        }

        $rows = $this->database->selectAll($query);
        $more = \count($rows) > $limit;
        $rows = $more ? \array_slice($rows, 0, $limit) : $rows;
        $cells = [];
        $learners = [];

        foreach ($rows as $row) {
            $cells[] = $this->renderOneCell($row);
            $learners[(int) $row['user_id']] = true;
        }

        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'course_id' => $courseId,
            'cells' => $cells,
            'totals' => array_map(
                fn (int $learner): array => $this->totalsFor($courseId, $learner),
                array_keys($learners),
            ),
            'next_cursor' => $more && $last !== null
                ? KeysetCursor::of([(int) $last['user_id'], (int) $last['grade_item_id']])->encode()
                : null,
        ];
    }

    /**
     * One page of the change log, keyset paged on (occurred_at, id).
     *
     * @return array<string, mixed>
     */
    public function changes(
        Principal $principal,
        int $courseId,
        ?int $userId = null,
        ?int $gradeItemId = null,
        ?int $actorId = null,
        ?string $from = null,
        ?string $until = null,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $this->access->require($principal, $courseId, Capability::TEACH, Capability::GRADE, Capability::MANAGE);

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = Query::from(GradeBookStore::CHANGES)
            ->where('course_id', '=', $courseId)
            ->orderBy('occurred_at', Query::SORT_DESC)
            ->orderBy('id', Query::SORT_DESC)
            ->limit($limit + 1);

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', '=', $userId);
        }

        if ($gradeItemId !== null && $gradeItemId > 0) {
            $query->where('grade_item_id', '=', $gradeItemId);
        }

        if ($actorId !== null && $actorId > 0) {
            $query->where('actor_id', '=', $actorId);
        }

        if ($from !== null && $from !== '') {
            $query->where('occurred_at', '>=', $this->instant($from));
        }

        if ($until !== null && $until !== '') {
            $query->where('occurred_at', '<=', $this->instant($until));
        }

        $decoded = KeysetCursor::decode($cursor);

        if ($decoded !== null) {
            $at = $decoded->part(0);
            $id = $decoded->part(1);

            if (\is_string($at) && \is_int($id)) {
                $query->whereAnyOf(
                    Predicate::compare('occurred_at', '<', $at),
                    Predicate::allOf(
                        Predicate::compare('occurred_at', '=', $at),
                        Predicate::compare('id', '<', $id),
                    ),
                );
            }
        }

        $rows = $this->database->selectAll($query);
        $more = \count($rows) > $limit;
        $rows = $more ? \array_slice($rows, 0, $limit) : $rows;
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'course_id' => $courseId,
            'changes' => array_map(fn (array $row): array => $this->renderOneChange($row), $rows),
            'next_cursor' => $more && $last !== null
                ? KeysetCursor::of([(string) $last['occurred_at'], (int) $last['id']])->encode()
                : null,
        ];
    }

    /**
     * Canvas's two totals, both named.
     *
     * `current` counts graded work only; `final` counts outstanding work as
     * zero. Shipping one number and leaving the reader to infer which it is is
     * the most common gradebook complaint in both Moodle and Canvas support
     * traffic.
     *
     * @return array<string, mixed>
     */
    public function totalsFor(int $courseId, int $userId): array
    {
        $current = $this->totals->courseTotal($courseId, $userId);
        $final = $this->totals->courseTotal($courseId, $userId, true);
        $key = $this->rootKey($courseId);
        $display = $this->rootDisplay($courseId);
        $range = $current ?? $final;

        return [
            'user_id' => $userId,
            'current' => $current === null || $current->empty ? null : $this->decimal($current->value),
            'final' => $final === null || $final->empty ? null : $this->decimal($final->value),
            'grade_max' => $this->decimal($range === null ? 0.0 : $range->maximum),
            // The other half of the pair, because the two totals are computed
            // over different sets of cells and so are marked out of different
            // maxima. `current` is graded work alone; `final` counts what is
            // outstanding. Publishing one maximum for both made the final
            // percentage unreadable on screen, and a percentage a reader
            // cannot reproduce is the complaint the pair exists to answer.
            'final_grade_max' => $this->decimal($final === null ? 0.0 : $final->maximum),
            // "unknown" is what an unresolved provider key answers, and it is
            // never a number: a total computed under a rule nobody asked for is
            // worse than no total, because nobody can tell it happened.
            'aggregation_status' => $current === null ? 'unknown' : ($current->empty ? 'novalue' : 'used'),
            'aggregation_key' => $key,
            // The same two numbers as text, under whatever the course total's
            // display_mode says. Both are published rather than only the one a
            // screen happens to show, because the pair is the point: a reader
            // comparing "82%" with "B-" has to be able to see they are the
            // same grade.
            'display_mode' => $display[0],
            'current_display' => $current === null || $current->empty
                ? null
                : $this->render($display, $current->value, $current->maximum, $current->percentage()),
            'final_display' => $final === null || $final->empty
                ? null
                : $this->render($display, $final->value, $final->maximum, $final->percentage()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderItem(GradeItem $item): array
    {
        return [
            'id' => $item->id,
            'course_id' => $item->courseId,
            'parent_item_id' => $item->parentItemId,
            'item_type' => $item->itemType,
            'source_kind' => $item->sourceKind,
            'source_id' => $item->sourceId,
            'title' => $item->title,
            // Decimal strings, because a maximum crossing JSON as a float is a
            // maximum that can come back different.
            'grade_max' => $this->decimal($item->gradeMax),
            'grade_min' => $this->decimal($item->gradeMin),
            'weight_bp' => $item->weightBp,
            'weight_overridden' => $item->weightOverridden,
            'extra_credit' => $item->extraCredit,
            'aggregation_key' => $item->aggregationKey,
            'aggregation_label' => $this->aggregations->has($item->aggregationKey)
                ? $this->aggregations->get($item->aggregationKey)->label()
                : null,
            'aggregation_available' => $this->aggregations->has($item->aggregationKey),
            'aggregation_params' => $item->aggregationParams === [] ? null : $item->aggregationParams,
            'aggregation_params_version' => $item->aggregationParamsVersion,
            'empty_grade_policy' => $item->emptyGradePolicy,
            'drop_lowest' => $item->dropLowest,
            'display_mode' => $item->displayMode,
            // The same pair the aggregation key carries, for the same reason:
            // a screen offering a picker needs the registered label, and a
            // screen rendering a stored mode nobody installed has to be able
            // to say so rather than showing a number that looks right.
            'display_label' => $this->displays !== null && $this->displays->has($item->displayMode)
                ? $this->displays->get($item->displayMode)->label()
                : null,
            'display_available' => $this->displays !== null && $this->displays->has($item->displayMode),
            'decimals' => $item->decimals,
            'hidden' => $item->hidden,
            'locked' => $item->locked,
            'position' => $item->position,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function renderOneCell(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'grade_item_id' => (int) $row['grade_item_id'],
            'course_id' => (int) $row['course_id'],
            'user_id' => (int) $row['user_id'],
            'raw_grade' => $row['raw_grade'] === null ? null : $this->decimal((float) $row['raw_grade']),
            'raw_grade_max' => $this->decimal((float) $row['raw_grade_max']),
            'raw_grade_min' => $this->decimal((float) $row['raw_grade_min']),
            'final_grade' => $row['final_grade'] === null ? null : $this->decimal((float) $row['final_grade']),
            'activity_progress' => (string) $row['activity_progress'],
            'grading_progress' => (string) $row['grading_progress'],
            'score_status' => (string) $row['score_status'],
            'aggregation_status' => (string) $row['aggregation_status'],
            'aggregation_weight_bp' => (int) $row['aggregation_weight_bp'],
            'excluded' => (bool) $row['excluded'],
            'hidden' => (bool) $row['hidden'],
            'locked' => (bool) $row['locked'],
            'overridden_at' => $this->atom($row['overridden_at'] ?? null),
            'feedback' => $row['feedback'] === null ? null : (string) $row['feedback'],
            'graded_by' => isset($row['graded_by']) ? (int) $row['graded_by'] : null,
            'graded_at' => $this->atom($row['graded_at'] ?? null),
            'scored_at' => $this->atom($row['scored_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function renderOneChange(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'grade_cell_id' => (int) $row['grade_cell_id'],
            'grade_item_id' => (int) $row['grade_item_id'],
            'course_id' => (int) $row['course_id'],
            'user_id' => (int) $row['user_id'],
            'grade_before' => $row['grade_before'] === null ? null : $this->decimal((float) $row['grade_before']),
            'grade_after' => $row['grade_after'] === null ? null : $this->decimal((float) $row['grade_after']),
            'max_before' => $row['max_before'] === null ? null : $this->decimal((float) $row['max_before']),
            'max_after' => $row['max_after'] === null ? null : $this->decimal((float) $row['max_after']),
            'status_before' => $row['status_before'] === null ? null : (string) $row['status_before'],
            'status_after' => $row['status_after'] === null ? null : (string) $row['status_after'],
            'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'request_ref' => $row['request_ref'] === null ? null : (string) $row['request_ref'],
            'occurred_at' => (string) $this->atom($row['occurred_at'] ?? null),
        ];
    }

    /**
     * The course total's display mode and precision, or the schema defaults
     * when no course total row exists yet.
     *
     * @return array{0: string, 1: int}
     */
    private function rootDisplay(int $courseId): array
    {
        foreach ($this->store->itemsForCourse($courseId) as $item) {
            if ($item->itemType === GradeItem::TYPE_COURSE_TOTAL) {
                return [$item->displayMode, $item->decimals];
            }
        }

        return ['real', 2];
    }

    /**
     * @param array{0: string, 1: int} $display
     */
    private function render(array $display, float $value, float $maximum, ?float $percentage): string
    {
        if ($this->displays === null) {
            return $this->decimal($value);
        }

        return $this->displays->render($display[0], $value, $maximum, $percentage, $display[1]);
    }

    private function rootKey(int $courseId): string
    {
        foreach ($this->store->itemsForCourse($courseId) as $item) {
            if ($item->itemType === GradeItem::TYPE_COURSE_TOTAL) {
                return $item->aggregationKey;
            }
        }

        return 'natural';
    }

    private function decimal(float $value): string
    {
        return number_format($value, 5, '.', '');
    }

    private function atom(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function instant(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
