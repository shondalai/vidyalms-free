<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Grades\StoredGradeCell;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The one way a grade is written, and therefore the one place a grade change
 * is recorded.
 *
 * Every path into the gradebook comes through here: the listener that hears a
 * quiz attempt graded, the listener that hears a submission marked, and a
 * person overriding a mark by hand. That is what makes the change log complete
 * rather than mostly complete, and a change log that is mostly complete is
 * worth nothing during the one dispute it exists for.
 */
final class RecordGrade
{
    public function __construct(
        private readonly GradeBookStore $store,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * The product recorded a grade: an attempt was marked, a submission was
     * graded, a recalculation ran.
     *
     * @param array<string, mixed> $payload what produced it, for the log
     */
    public function bySystem(
        GradeItem $item,
        int $userId,
        ?float $rawGrade,
        float $rawGradeMax,
        GradeProgress $progress,
        string $reason,
        ?string $requestRef = null,
        array $payload = [],
        ?\DateTimeImmutable $scoredAt = null,
        float $rawGradeMin = 0.0,
    ): StoredGradeCell {
        return $this->write(
            $item,
            $userId,
            $rawGrade,
            $rawGradeMax,
            $rawGradeMin,
            $progress,
            null,
            $reason,
            $requestRef,
            $payload,
            $scoredAt,
            false,
        );
    }

    /**
     * A person changed a grade, and said why.
     *
     * The reason is required by {@see GradeChange::byPerson()} rather than
     * here, so a caller that reaches the log another way is held to the same
     * rule.
     *
     * @param array<string, mixed> $payload
     */
    public function byPerson(
        GradeItem $item,
        int $userId,
        int $actorId,
        ?float $rawGrade,
        float $rawGradeMax,
        GradeProgress $progress,
        string $reason,
        ?string $feedback = null,
        ?string $requestRef = null,
        array $payload = [],
        float $rawGradeMin = 0.0,
        /**
         * The three decisions only a person makes. Trailing and defaulted, so
         * every existing caller is unchanged; the regrade route publishes all
         * three and had nothing to pass them to.
         */
        ?bool $excluded = null,
        ?bool $hidden = null,
        bool $clearOverride = false,
    ): StoredGradeCell {
        return $this->write(
            $item,
            $userId,
            $rawGrade,
            $rawGradeMax,
            $rawGradeMin,
            $progress,
            $actorId,
            $reason,
            $requestRef,
            $payload,
            null,
            // An explicit release is not also an override: a caller clearing
            // one must not have a fresh stamp written in the same statement.
            !$clearOverride,
            $feedback,
            $excluded,
            $hidden,
            $clearOverride,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(
        GradeItem $item,
        int $userId,
        ?float $rawGrade,
        float $rawGradeMax,
        float $rawGradeMin,
        GradeProgress $progress,
        ?int $actorId,
        string $reason,
        ?string $requestRef,
        array $payload,
        ?\DateTimeImmutable $scoredAt,
        bool $overridden,
        ?string $feedback = null,
        ?bool $excluded = null,
        ?bool $hidden = null,
        bool $clearOverride = false,
    ): StoredGradeCell {
        $stored = $this->store->itemForSource($item);
        $itemId = $stored->id ?? throw new \RuntimeException('A stored grade item has no id.');
        $existing = $this->store->findCell($itemId, $userId);
        $now = $this->clock->now();
        $at = $scoredAt ?? $now;

        if ($existing !== null && !$existing->accepts($at)) {
            // AGS's out-of-order rule, and the answer is silence rather than an
            // error: a redelivered webhook and a retried job are ordinary, and
            // throwing here would turn a duplicate into an incident.
            return $existing;
        }

        if ($existing !== null && $actorId === null && $existing->overriddenAt !== null) {
            // A human decided this mark, and a recomputation is not a person.
            // `overridden_at` was written by the regrade path and read by no
            // branch anywhere, so the next progress recalculation over the same
            // unchanged attempts wrote the machine's number back over it and
            // logged the reversal as a system change with no actor. That is the
            // one defect the column exists to prevent, and the contract already
            // published the rule: an overridden cell keeps its override until
            // it is cleared. `clear_override` is the one path that drops the
            // stamp, and it goes through byPerson().
            return $existing;
        }

        $before = $existing ?? StoredGradeCell::empty($itemId, $userId, $item->courseId, $rawGradeMax, $rawGradeMin);
        $after = $before->scored(
            $rawGrade,
            $rawGradeMax,
            $rawGradeMin,
            $progress,
            $actorId,
            $at,
            $feedback,
            $overridden,
            $excluded,
            $hidden,
            $clearOverride,
        );

        if ($existing !== null && $this->unchanged($before, $after)) {
            // A change log carrying an entry for every recomputation is a log
            // nobody can read during the dispute it exists for. Nothing moved,
            // so nothing is written and the stored cell is answered as it
            // stands.
            return $existing;
        }

        $change = $actorId === null
            ? GradeChange::bySystem(
                $before->id ?? 0,
                $itemId,
                $item->courseId,
                $userId,
                $reason,
                $now,
                $before->rawGrade,
                $after->rawGrade,
                $before->rawGradeMax,
                $after->rawGradeMax,
                $before->progress->score,
                $after->progress->score,
                $requestRef,
                $payload,
            )
            : GradeChange::byPerson(
                $before->id ?? 0,
                $itemId,
                $item->courseId,
                $userId,
                $actorId,
                $reason,
                $now,
                $before->rawGrade,
                $after->rawGrade,
                $before->rawGradeMax,
                $after->rawGradeMax,
                $before->progress->score,
                $after->progress->score,
                $requestRef,
                $payload,
            );

        return $this->store->recordCell($after, $change);
    }

    /**
     * Whether this write would say anything the stored cell does not already
     * say.
     *
     * The feedback text is part of the comparison, because a marker who changed
     * only their words changed the thing the learner actually reads.
     */
    private function unchanged(StoredGradeCell $before, StoredGradeCell $after): bool
    {
        return $before->rawGrade === $after->rawGrade
            && $before->rawGradeMax === $after->rawGradeMax
            && $before->rawGradeMin === $after->rawGradeMin
            && $before->progress->activity === $after->progress->activity
            && $before->progress->grading === $after->progress->grading
            && $before->progress->score === $after->progress->score
            && $before->feedback === $after->feedback
            && $before->excluded === $after->excluded
            // Both were absent, and both are things a person changes on
            // purpose. A regrade that hid a cell or released an override and
            // moved nothing else answered "nothing changed" and wrote no log
            // row, which is a decision with no record of who made it.
            //
            // The override is compared on its presence and not on its instant,
            // deliberately: a second identical regrade re-stamps the same
            // decision and says nothing new, while releasing one takes the
            // stamp off and says a great deal.
            && $before->hidden === $after->hidden
            && ($before->overriddenAt === null) === ($after->overriddenAt === null);
    }
}
