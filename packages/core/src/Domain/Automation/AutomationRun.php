<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * One run of one rule for one subject at one occurrence.
 *
 * The unique index over (rule_id, occurrence_key, subject_type, subject_id,
 * dry_run) is the re-entry guard, expressed as an index rather than as a
 * check, which is the platform-level answer to duplicate runs. dry_run is in
 * it because one code path serves the preview and the real run, so without it
 * a preview writes the row, the real trigger fires minutes later, the unique
 * refuses the insert, and with reentry defaulting to never the subject is
 * suppressed permanently by the act of previewing.
 *
 * cascade_depth and rule_chain are real columns because the loop ceiling is
 * checked before every enqueue and a JSON read there would run per subject.
 */
final class AutomationRun
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $ruleId,
        public readonly int $ruleVersion,
        public readonly string $triggerKey,
        public readonly string $eventReference,
        public readonly string $occurrenceKey,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $actorUserId,
        public readonly bool $dryRun = false,
        public readonly AutomationRunState $state = AutomationRunState::PendingFanout,
        public readonly int $cascadeDepth = 0,
        public readonly ?CascadeChain $ruleChain = null,
        public readonly int $matchedCount = 0,
        public readonly int $executedCount = 0,
        public readonly int $skippedCount = 0,
        public readonly int $failedCount = 0,
        public readonly ?string $log = null,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $finishedAt = null,
        /**
         * The person the occurrence is about, which is not always the
         * subject.
         *
         * `EntitlementExpiringSweep` yields a learner as the recipient and the
         * course as the subject, and with no column for the first the run was
         * written from the subject alone: `notify` then mailed the course id
         * as if it were a user, so a learner's expiry notice was queued to
         * whoever happened to hold that integer. It is trailing and nullable
         * so every existing construction still reads, and null means the
         * subject is the only person named.
         */
        public readonly ?int $recipientUserId = null,
    ) {
    }

    /**
     * Who a step of this run acts for: the occurrence's recipient when it has
     * one, and the subject only when the subject is a person.
     *
     * Composed here so the fan-out, the step machine and every action answer
     * it the same way, which is the failure mode a fallback spelled in three
     * places always eventually has.
     */
    public function actsForUserId(): ?int
    {
        if ($this->recipientUserId !== null && $this->recipientUserId > 0) {
            return $this->recipientUserId;
        }

        return $this->subjectType === 'user' && $this->subjectId !== null && $this->subjectId > 0
            ? $this->subjectId
            : null;
    }

    public function chain(): CascadeChain
    {
        return $this->ruleChain ?? CascadeChain::empty();
    }
}
