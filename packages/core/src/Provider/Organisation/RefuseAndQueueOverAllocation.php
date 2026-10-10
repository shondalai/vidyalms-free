<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatOverAllocationOutcome;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationInterface;

/**
 * The one free over-allocation stance, and the one the field agrees on.
 *
 * Microsoft Entra refuses the assignment and records a durable row carrying
 * the named value `CountViolation`, lists them in an Errors and issues tab
 * and offers a Reprocess action. That is what this returns: a bulk enrolment
 * of five hundred into a pool of four hundred and eighty leaves exactly
 * twenty inspectable rows and zero discarded people.
 *
 * `max_pending` bounds the queue, because a runaway importer that keeps
 * asking is otherwise a way to write unbounded rows into a table an
 * administrator has to read. Past the bound the answer is still a refusal;
 * what changes is that the row is not kept, so the list stays usable.
 */
final class RefuseAndQueueOverAllocation implements SeatOverAllocationInterface
{
    public const KEY = 'refuse_and_queue';

    /** The default the published schema states, used when the pool configures none. */
    public const DEFAULT_MAX_PENDING = 1000;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.seat_over_allocation.refuse_and_queue';
    }

    public function decide(SeatDecisionContext $context): SeatOverAllocationOutcome
    {
        return SeatOverAllocationOutcome::queue();
    }

    /**
     * How many refused rows this pool keeps.
     *
     * Read from the pool's own policy_params, so a site that wants a smaller
     * or larger queue configures the pool rather than the code.
     */
    public function maxPending(SeatDecisionContext $context): int
    {
        $configured = $context->params['max_pending'] ?? null;

        return \is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_MAX_PENDING;
    }
}
