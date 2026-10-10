<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades;

use Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Grades\StoredGradeCell;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The one writer and reader of the gradebook's three tables.
 *
 * Row mapping, JSON encoding and datetime formatting happen here and nowhere
 * else. The use cases above it hold {@see GradeItem}, {@see StoredGradeCell}
 * and {@see GradeChange}, and never an array of columns.
 *
 * **The change log is written by this class and by nothing else.** A caller
 * that could write a cell without writing its change would produce exactly the
 * row a dispute cannot explain, so {@see recordCell()} writes both or neither.
 */
final class GradeBookStore
{
    public const ITEMS = 'grade_items';
    public const CELLS = 'grade_cells';
    public const CHANGES = 'grade_changes';

    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * The item for one source, created on first use.
     *
     * A grading use case names the thing it graded and the maximum it was
     * graded out of; the item is looked up by that identity rather than passed
     * in, because the alternative is every caller holding an item id it has to
     * have got from somewhere.
     */
    public function itemForSource(GradeItem $item): GradeItem
    {
        $existing = $this->findItemBySource($item->courseId, $item->sourceKind, $item->sourceId);

        if ($existing !== null) {
            // The title and the maximum are refreshed, because the item is the
            // current configuration of the graded thing. Every stored cell
            // keeps its own snapshot, so nothing already graded is
            // reinterpreted by this write.
            if ($existing->title !== $item->title || $existing->gradeMax !== $item->gradeMax) {
                $this->database->update(self::ITEMS, [
                    'title' => $item->title,
                    'grade_max' => $item->gradeMax,
                    'updated_at' => $this->clock->now()->format(self::DATETIME),
                ], ['id' => $existing->id]);

                return $this->findItem((int) $existing->id) ?? $existing;
            }

            return $existing;
        }

        $now = $this->clock->now()->format(self::DATETIME);
        $id = $this->database->insert(self::ITEMS, [
            'course_id' => $item->courseId,
            'parent_item_id' => $item->parentItemId,
            'item_type' => $item->itemType,
            'source_kind' => $item->sourceKind,
            'source_id' => $item->sourceId,
            'title' => $item->title,
            'grade_max' => $item->gradeMax,
            'grade_min' => $item->gradeMin,
            'weight_bp' => $item->weightBp,
            'weight_overridden' => $item->weightOverridden ? 1 : 0,
            'extra_credit' => $item->extraCredit ? 1 : 0,
            'aggregation_key' => $item->aggregationKey,
            'aggregation_params' => $item->aggregationParams === []
                ? null
                : json_encode(
                    StoredShape::wrap($item->aggregationParams, $item->aggregationParamsVersion),
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
                ),
            'aggregation_params_version' => $item->aggregationParamsVersion,
            'empty_grade_policy' => $item->emptyGradePolicy,
            'drop_lowest' => $item->dropLowest,
            'display_mode' => $item->displayMode,
            'decimals' => $item->decimals,
            'hidden' => $item->hidden ? 1 : 0,
            'locked' => $item->locked ? 1 : 0,
            'position' => $item->position,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findItem($id) ?? throw new \RuntimeException('The grade item row vanished after insert.');
    }

    public function findItem(int $id): ?GradeItem
    {
        $row = $this->database->selectOne(Query::from(self::ITEMS)->where('id', '=', $id));

        return $row === null ? null : $this->hydrateItem($row);
    }

    public function findItemBySource(int $courseId, string $sourceKind, int $sourceId): ?GradeItem
    {
        $row = $this->database->selectOne(
            Query::from(self::ITEMS)
                ->where('course_id', '=', $courseId)
                ->where('source_kind', '=', $sourceKind)
                ->where('source_id', '=', $sourceId),
        );

        return $row === null ? null : $this->hydrateItem($row);
    }

    /**
     * @return list<GradeItem>
     */
    public function itemsForCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::ITEMS)
                ->where('course_id', '=', $courseId)
                ->orderBy('position', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        );

        return array_map(fn (array $row): GradeItem => $this->hydrateItem($row), $rows);
    }

    public function findCell(int $gradeItemId, int $userId): ?StoredGradeCell
    {
        $row = $this->database->selectOne(
            Query::from(self::CELLS)
                ->where('grade_item_id', '=', $gradeItemId)
                ->where('user_id', '=', $userId),
        );

        return $row === null ? null : $this->hydrateCell($row);
    }

    /**
     * Every cell one learner holds in one course.
     *
     * @return list<StoredGradeCell>
     */
    public function cellsFor(int $courseId, int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::CELLS)
                ->where('course_id', '=', $courseId)
                ->where('user_id', '=', $userId)
                ->orderBy('grade_item_id', Query::SORT_ASC),
        );

        return array_map(fn (array $row): StoredGradeCell => $this->hydrateCell($row), $rows);
    }

    /**
     * Writes the cell and its change entry together.
     *
     * The pair is the whole point. A cell written without its change is the row
     * a dispute cannot explain, and a change written without its cell is a log
     * of something that did not happen, so this method is the only writer of
     * either table and it writes both.
     *
     * @return StoredGradeCell the stored cell
     */
    public function recordCell(StoredGradeCell $cell, GradeChange $change): StoredGradeCell
    {
        $now = $this->clock->now()->format(self::DATETIME);
        $row = [
            'grade_item_id' => $cell->gradeItemId,
            'user_id' => $cell->userId,
            'course_id' => $cell->courseId,
            'raw_grade' => $cell->rawGrade,
            'raw_grade_max' => $cell->rawGradeMax,
            'raw_grade_min' => $cell->rawGradeMin,
            'final_grade' => $cell->finalGrade,
            'activity_progress' => $cell->progress->activity,
            'grading_progress' => $cell->progress->grading,
            'score_status' => $cell->progress->score,
            'aggregation_status' => $cell->aggregationStatus,
            'aggregation_weight_bp' => $cell->aggregationWeightBp,
            'excluded' => $cell->excluded ? 1 : 0,
            'hidden' => $cell->hidden ? 1 : 0,
            'locked' => $cell->locked ? 1 : 0,
            'overridden_at' => $cell->overriddenAt?->format(self::DATETIME),
            'feedback' => $cell->feedback,
            'graded_by' => $cell->gradedBy,
            'graded_at' => $cell->gradedAt?->format(self::DATETIME),
            'scored_at' => $cell->scoredAt?->format(self::DATETIME),
            'updated_at' => $now,
        ];

        $existing = $this->findCell($cell->gradeItemId, $cell->userId);

        if ($existing === null) {
            $row['created_at'] = $now;
            $cellId = $this->database->insert(self::CELLS, $row);
        } else {
            $cellId = (int) $existing->id;
            $this->database->update(self::CELLS, $row, ['id' => $cellId]);
        }

        $this->database->insert(self::CHANGES, [
            'grade_cell_id' => $cellId,
            'grade_item_id' => $change->gradeItemId,
            'course_id' => $change->courseId,
            'user_id' => $change->userId,
            'grade_before' => $change->gradeBefore,
            'grade_after' => $change->gradeAfter,
            'max_before' => $change->maxBefore,
            'max_after' => $change->maxAfter,
            'status_before' => $change->statusBefore,
            'status_after' => $change->statusAfter,
            'actor_id' => $change->actorId,
            'reason' => $change->reason,
            'request_ref' => $change->requestRef,
            'payload' => $change->payload === []
                ? null
                : json_encode(
                    StoredShape::wrap($change->payload, $change->payloadVersion),
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
                ),
            'payload_version' => $change->payloadVersion,
            // No created_at: the table opts out of timestamps, because an
            // append-only log row is never updated and occurred_at is the only
            // instant it has.
            'occurred_at' => $change->occurredAt->format(self::DATETIME),
        ]);

        return $this->findCell($cell->gradeItemId, $cell->userId)
            ?? throw new \RuntimeException('The grade cell row vanished after a write.');
    }

    /**
     * The change log for one cell, newest last, which is the order a dispute is
     * read in.
     *
     * @return list<GradeChange>
     */
    public function changesFor(int $gradeCellId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::CHANGES)
                ->where('grade_cell_id', '=', $gradeCellId)
                ->orderBy('occurred_at', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        );

        return array_map(fn (array $row): GradeChange => $this->hydrateChange($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateItem(array $row): GradeItem
    {
        $params = [];

        if (\is_string($row['aggregation_params'] ?? null) && $row['aggregation_params'] !== '') {
            $decoded = json_decode((string) $row['aggregation_params'], true);
            $params = \is_array($decoded) ? StoredShape::bodyOf($decoded) : [];
        }

        return new GradeItem(
            (int) $row['id'],
            (int) $row['course_id'],
            isset($row['parent_item_id']) ? (int) $row['parent_item_id'] : null,
            (string) $row['item_type'],
            (string) $row['source_kind'],
            (int) $row['source_id'],
            (string) $row['title'],
            (float) $row['grade_max'],
            (float) $row['grade_min'],
            (int) $row['weight_bp'],
            (bool) $row['weight_overridden'],
            (bool) $row['extra_credit'],
            (string) $row['aggregation_key'],
            $params,
            (int) $row['aggregation_params_version'],
            (string) $row['empty_grade_policy'],
            (int) $row['drop_lowest'],
            (string) $row['display_mode'],
            (int) $row['decimals'],
            (bool) $row['hidden'],
            (bool) $row['locked'],
            (int) $row['position'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCell(array $row): StoredGradeCell
    {
        return new StoredGradeCell(
            (int) $row['id'],
            (int) $row['grade_item_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            $row['raw_grade'] === null ? null : (float) $row['raw_grade'],
            (float) $row['raw_grade_max'],
            (float) $row['raw_grade_min'],
            $row['final_grade'] === null ? null : (float) $row['final_grade'],
            GradeProgress::of(
                (string) $row['activity_progress'],
                (string) $row['grading_progress'],
                (string) $row['score_status'],
            ),
            (string) $row['aggregation_status'],
            (int) $row['aggregation_weight_bp'],
            (bool) $row['excluded'],
            (bool) $row['hidden'],
            (bool) $row['locked'],
            $this->moment($row['overridden_at'] ?? null),
            $row['feedback'] === null ? null : (string) $row['feedback'],
            isset($row['graded_by']) ? (int) $row['graded_by'] : null,
            $this->moment($row['graded_at'] ?? null),
            $this->moment($row['scored_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateChange(array $row): GradeChange
    {
        $payload = [];

        if (\is_string($row['payload'] ?? null) && $row['payload'] !== '') {
            $decoded = json_decode((string) $row['payload'], true);
            $payload = \is_array($decoded) ? StoredShape::bodyOf($decoded) : [];
        }

        return new GradeChange(
            (int) $row['id'],
            (int) $row['grade_cell_id'],
            (int) $row['grade_item_id'],
            (int) $row['course_id'],
            (int) $row['user_id'],
            $row['grade_before'] === null ? null : (float) $row['grade_before'],
            $row['grade_after'] === null ? null : (float) $row['grade_after'],
            $row['max_before'] === null ? null : (float) $row['max_before'],
            $row['max_after'] === null ? null : (float) $row['max_after'],
            $row['status_before'] === null ? null : (string) $row['status_before'],
            $row['status_after'] === null ? null : (string) $row['status_after'],
            isset($row['actor_id']) ? (int) $row['actor_id'] : null,
            $row['reason'] === null ? null : (string) $row['reason'],
            $row['request_ref'] === null ? null : (string) $row['request_ref'],
            $payload,
            (int) $row['payload_version'],
            $this->moment($row['occurred_at'] ?? null) ?? $this->clock->now(),
        );
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
