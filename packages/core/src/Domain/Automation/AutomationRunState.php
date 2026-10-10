<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * Where one run of one rule for one subject has got to.
 *
 * pending_fanout is the intent row the trigger writes inside the transaction
 * that changed the state, and it is the only state a use case ever writes.
 * Audience resolution, condition re-evaluation and policy evaluation belong to
 * the binned fan-out task, because a cohort-starting rule over a five thousand
 * member cohort must not run five thousand evaluations inside the transaction
 * that starts the cohort.
 */
enum AutomationRunState: string
{
    case PendingFanout = 'pending_fanout';
    case FanningOut = 'fanning_out';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Halted = 'halted';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed
            || $this === self::Failed
            || $this === self::Halted
            || $this === self::Cancelled;
    }

    /**
     * Where a claim that expired returns the row to, or null when the state
     * carries no claim.
     *
     * The recovery move cannot be "fanning_out to fanning_out": the predicate
     * would be satisfied by the very value the update writes, so every runner
     * that saw the expired row would claim it. That is the lesson the
     * notification queue already records, copied rather than re-derived.
     */
    public function releasesTo(): ?self
    {
        return $this === self::FanningOut ? self::PendingFanout : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
