<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * A person changes one grade cell by hand, and says why.
 *
 * An override, an exclusion, a visibility change or a plain correction, all of
 * them one row on the append-only change log with the value before, the value
 * after, the actor, the reason and the request reference.
 *
 * **The reason is not optional.** {@see \Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange::byPerson()}
 * refuses a blank one, and that refusal is the whole value of the log: a
 * regrade somebody made without saying why is the row that costs most to read
 * back two years later, during the dispute the log exists for.
 *
 * **The category total is not recomputed here.** It is folded by the registered
 * `GradeAggregation` provider whenever it is read, so a site that installs a
 * weighted provider gets weighted totals with no change to this class, and a
 * site with no provider for its key gets `unknown` rather than a number nobody
 * asked for.
 */
final class RegradeGradeCell
{
    public function __construct(
        private readonly GradeBookStore $store,
        private readonly RecordGrade $grades,
        private readonly ReadGradebook $gradebook,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, int $cellId, array $body, ?string $requestRef = null): array
    {
        $row = $this->database->selectOne(Query::from(GradeBookStore::CELLS)->where('id', '=', $cellId));

        if ($row === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('GradeCell', $cellId);
        }

        $courseId = (int) $row['course_id'];
        $this->access->require($principal, $courseId, Capability::GRADE, Capability::TEACH, Capability::MANAGE);

        $item = $this->store->findItem((int) $row['grade_item_id'])
            ?? throw EntityNotFoundException::for('GradeItem', (int) $row['grade_item_id']);
        $cell = $this->store->findCell((int) $row['grade_item_id'], (int) $row['user_id'])
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('GradeCell', $cellId);

        $reason = \is_string($body['reason'] ?? null) ? trim((string) $body['reason']) : '';

        if ($reason === '') {
            throw ValidationException::forField(
                'reason',
                'A person changing a grade says why. The reason is what a dispute is read back through.',
            );
        }

        // A body that names nothing to change is refused rather than answered
        // with a silent no-op, because a regrade nobody can see is worse than
        // a refusal a person can read: the caller believes a mark moved.
        if (!self::touchesAnything($body)) {
            throw ValidationException::forField(
                'raw_grade',
                'A regrade changes something. This body changes nothing.',
            );
        }

        $grade = $this->gradeFrom($body, $cell->rawGrade, $item->gradeMin, $item->gradeMax);
        $status = $this->statusFrom($body, $cell->progress->score);
        $progress = GradeProgress::of(
            $cell->progress->activity,
            $grade === null ? $cell->progress->grading : GradeProgress::GRADING_FULLY_GRADED,
            $status,
        );

        // The change row this cell already had, so a write that moved nothing
        // can be told apart from one that did. Answering the previous row as
        // "the change you just made" told the caller a system recomputation
        // from last night was their own decision.
        $before = $this->latestChangeId($cell->id === null ? 0 : (int) $cell->id);

        $stored = $this->grades->byPerson(
            $item,
            (int) $row['user_id'],
            $principal->userId,
            $grade,
            // The maximum is snapshotted at write, so the row stays
            // self-describing when the item's maximum changes afterwards.
            $item->gradeMax,
            $progress,
            $reason,
            \is_string($body['feedback'] ?? null) ? (string) $body['feedback'] : null,
            $requestRef,
            [],
            $item->gradeMin,
            // The three keys the route publishes and the use case dropped.
            // `touchesAnything()` already listed all three, so a body naming
            // only one of them passed the "changes something" refusal and then
            // changed nothing, answered 200, and logged no row.
            self::flagFrom($body, 'excluded'),
            self::flagFrom($body, 'hidden'),
            self::flagFrom($body, 'clear_override') === true,
        );

        $latest = $this->latestChangeId((int) $stored->id);

        return [
            'cell' => $this->cellOf((int) $stored->id),
            // Null rather than the row that was already there. A regrade that
            // asked for what the cell already says wrote nothing, and the
            // caller has to be able to see that.
            'change' => $latest === null || $latest === $before ? null : $this->changeOf($latest),
            'totals' => $this->gradebook->totalsFor($courseId, (int) $row['user_id']),
        ];
    }

    /** The id of the newest change row on one cell, or null when it has none. */
    private function latestChangeId(int $cellId): ?int
    {
        if ($cellId <= 0) {
            return null;
        }

        $changes = $this->store->changesFor($cellId);

        if ($changes === []) {
            return null;
        }

        $newest = $changes[array_key_last($changes)];

        return $newest->id === null ? null : (int) $newest->id;
    }

    /**
     * One boolean the body may or may not carry.
     *
     * Absent is null and never false, because a body that says nothing about
     * a flag is asking for it to stay as it is; reading absence as false would
     * make every regrade un-exclude a cell somebody excluded on purpose.
     *
     * @param array<string, mixed> $body
     */
    private static function flagFrom(array $body, string $key): ?bool
    {
        if (!\array_key_exists($key, $body)) {
            return null;
        }

        $value = $body[$key];

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value !== 0;
        }

        if (\is_string($value)) {
            return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw ValidationException::forField($key, sprintf('"%s" is true or false.', $key));
    }

    /**
     * Whether this body asks for any change at all.
     *
     * @param array<string, mixed> $body
     */
    private static function touchesAnything(array $body): bool
    {
        foreach (['raw_grade', 'excluded', 'hidden', 'score_status', 'feedback', 'clear_override'] as $key) {
            if (\array_key_exists($key, $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The new score status, refused rather than dropped when it is outside the
     * OneRoster vocabulary.
     *
     * Silently keeping the old value would tell the caller their change landed
     * while the row says something else, which is the shape of every reporting
     * defect this vocabulary was adopted to avoid.
     *
     * @param array<string, mixed> $body
     */
    private static function statusFrom(array $body, string $current): string
    {
        if (!\array_key_exists('score_status', $body)) {
            return $current;
        }

        $status = $body['score_status'];

        if (!\is_string($status) || !\in_array($status, GradeProgress::SCORE_STATUSES, true)) {
            throw ValidationException::forField('score_status', sprintf(
                'A score status is one of %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', GradeProgress::SCORE_STATUSES),
            ));
        }

        return $status;
    }

    /**
     * The new grade, clamped to the item's own range.
     *
     * A grade above the maximum is a typing mistake far more often than it is a
     * deliberate bonus, and extra credit is a column on the item rather than a
     * number outside the range.
     *
     * @param array<string, mixed> $body
     */
    private function gradeFrom(array $body, ?float $current, float $min, float $max): ?float
    {
        if (!\array_key_exists('raw_grade', $body)) {
            return $current;
        }

        $value = $body['raw_grade'];

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw ValidationException::forField('raw_grade', 'A grade is a number or nothing.');
        }

        return max($min, min($max, (float) $value));
    }

    /**
     * The cell as the read model renders it, so a write answers exactly what a
     * read of the same row would.
     *
     * @return array<string, mixed>
     */
    private function cellOf(int $cellId): array
    {
        $row = $this->database->selectOne(Query::from(GradeBookStore::CELLS)->where('id', '=', $cellId));

        return $row === null ? [] : $this->gradebook->renderOneCell($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function changeOf(int $changeId): array
    {
        $row = $this->database->selectOne(Query::from(GradeBookStore::CHANGES)->where('id', '=', $changeId));

        return $row === null ? [] : $this->gradebook->renderOneChange($row);
    }
}
