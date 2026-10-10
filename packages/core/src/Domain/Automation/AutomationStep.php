<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * One queued step: the outbox row of the automation engine.
 *
 * This is notification_queue's shape with action_key where channel_key was and
 * no recipient, and the reasoning transfers line for line. occurrence_key is a
 * real column and not only a digest input, because without it every occurrence
 * of a repeating schedule collides and a window bounded at eleven occurrences
 * enqueues one and silently drops ten. The claim triple exists because both
 * hosts are documented multi-firers, so two runners over one pending row is the
 * normal case, and a dedupe digest deduplicates rows rather than effects.
 *
 * idem_key is the same digest the idempotency_keys row is claimed under, so the
 * ledger and the queue agree on what one effect is.
 */
final class AutomationStep
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $runId,
        public readonly int $ruleId,
        public readonly int $ruleVersion,
        public readonly int $stepIndex,
        public readonly string $actionKey,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $params,
        public readonly int $paramsVersion,
        public readonly string $occurrenceKey,
        public readonly string $dedupeKey,
        public readonly string $idemKey,
        public readonly \DateTimeImmutable $runAt,
        public readonly AutomationStepState $state = AutomationStepState::Pending,
        public readonly int $attempts = 0,
        public readonly ?\DateTimeImmutable $nextAttemptAt = null,
        public readonly int $cascadeDepth = 0,
        public readonly bool $dryRun = false,
        public readonly ?string $suppressedReason = null,
        public readonly ?string $lastError = null,
        public readonly ?string $claimToken = null,
        public readonly ?\DateTimeImmutable $leaseExpiresAt = null,
        public readonly ?\DateTimeImmutable $executedAt = null,
        /**
         * The person this step acts for, carried down from the run because a
         * sweep occurrence names a recipient the subject columns cannot hold.
         * Trailing and nullable so every existing construction still reads.
         */
        public readonly ?int $recipientUserId = null,
    ) {
    }

    /** Who this step acts for: the recipient, or the subject when it is a person. */
    public function actsForUserId(): ?int
    {
        if ($this->recipientUserId !== null && $this->recipientUserId > 0) {
            return $this->recipientUserId;
        }

        return $this->subjectType === 'user' && $this->subjectId !== null && $this->subjectId > 0
            ? $this->subjectId
            : null;
    }
}
