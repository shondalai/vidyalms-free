<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

/**
 * The queue's state machine, enumerated, because an unenumerated state is a
 * stalled queue.
 *
 * The drain selects pending rows whose run_at has passed and whose
 * next_attempt_at is null or passed, plus sending rows whose lease has
 * expired. A pending_fanout row belongs to the fan-out task and to nothing
 * else.
 *
 * A deferral writes deferred with a next attempt instant and the row returns
 * to pending at that instant, rather than being re-selected on the next
 * drain, which is a documented deadlock read from the other side.
 */
enum QueueState: string
{
    case PendingFanout = 'pending_fanout';
    /** Claimed by one fan-out runner, so a second runner does not expand it. */
    case FanningOut = 'fanning_out';
    /**
     * The intent row's own terminal state. It borrowed "sent" once, which
     * counted every occurrence as a delivery on the queue screen: an intent
     * row has no recipient and no channel and nothing was ever sent from it.
     */
    case FannedOut = 'fanned_out';
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Deferred = 'deferred';
    case Suppressed = 'suppressed';
    case FailedPermanent = 'failed_permanent';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Sent
            || $this === self::FannedOut
            || $this === self::Suppressed
            || $this === self::FailedPermanent
            || $this === self::Cancelled;
    }

    /**
     * The state a claimed row returns to when its lease expires, or null when
     * the state carries no lease.
     */
    public function releasesTo(): ?self
    {
        return match ($this) {
            self::Sending => self::Pending,
            self::FanningOut => self::PendingFanout,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
