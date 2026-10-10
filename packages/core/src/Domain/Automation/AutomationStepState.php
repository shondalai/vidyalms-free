<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * Where one queued step has got to. An unenumerated state is a stalled queue,
 * which is why the whole vocabulary is here and in the column comment.
 *
 * awaiting_approval is where a step whose action declares
 * RequiresApprovalBeforeEffect waits for a named person to release it. For an
 * AI action that is before the model is called, which is what makes the
 * human-in-the-loop rule a property of the engine rather than a promise.
 *
 * parked is Make's Break directive under a clearer name, and it is the shipped
 * default failure policy for any action whose effect is not reversible: it
 * keeps a failed run resumable with enough state to review rather than only
 * logged.
 */
enum AutomationStepState: string
{
    case Pending = 'pending';
    case AwaitingApproval = 'awaiting_approval';
    case Claimed = 'claimed';
    case Executing = 'executing';
    case Executed = 'executed';
    case Deferred = 'deferred';
    case Suppressed = 'suppressed';
    case Parked = 'parked';
    case FailedPermanent = 'failed_permanent';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Executed
            || $this === self::Suppressed
            || $this === self::FailedPermanent
            || $this === self::Cancelled;
    }

    /** Where an expired lease returns the row to, or null when it holds none. */
    public function releasesTo(): ?self
    {
        return $this === self::Claimed || $this === self::Executing ? self::Pending : null;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
