<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The assignment vocabulary, the seat-holding set and the guarded edges.
 *
 * The eight designed states plus the two the built code introduced. `pending`
 * and `cancelled` are the designed `refuse_and_queue` behaviour under an
 * undesigned name: a durable, inspectable row for somebody a full pool could
 * not seat, and the withdrawal of one. They are kept rather than renamed,
 * because both already carry rows on live sites and both already carry the
 * accounting rule that makes them safe: a zero-delta ledger row and a place
 * outside {@see HOLDING}, so neither occupies capacity.
 *
 * **HOLDING is exactly the six seat-holding states the design names**, and
 * that is the load-bearing constant in the whole area: availability is
 * `purchased + granted - |HOLDING|` and must equal the signed sum of the
 * ledger. Adding a state to this list, or removing one, breaks the
 * reconciliation invariant on every pool that already has rows.
 */
final class SeatAssignmentState
{
    public const RESERVED = 'reserved';
    public const ASSIGNED = 'assigned';
    public const CONSUMED = 'consumed';
    public const COMPLETED = 'completed';
    public const RELEASED = 'released';
    public const RECLAIMED = 'reclaimed';
    public const FORFEITED = 'forfeited';
    public const EXPIRED = 'expired';

    /** An over-allocation row: a person the pool could not seat, kept rather than discarded. */
    public const PENDING = 'pending';

    /** A withdrawn over-allocation row. */
    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATES = [
        self::RESERVED,
        self::ASSIGNED,
        self::CONSUMED,
        self::COMPLETED,
        self::RELEASED,
        self::RECLAIMED,
        self::FORFEITED,
        self::EXPIRED,
        self::PENDING,
        self::CANCELLED,
    ];

    /**
     * The six states that occupy a seat.
     *
     * `expired` sits inside it unconditionally, because `seat_expired` carries
     * a zero delta and the seat is spent; a conditional membership would make
     * the two derivations of availability disagree by the number of expired
     * assignments by construction.
     *
     * @var list<string>
     */
    public const HOLDING = [
        self::RESERVED,
        self::ASSIGNED,
        self::CONSUMED,
        self::COMPLETED,
        self::FORFEITED,
        self::EXPIRED,
    ];

    /**
     * The states in which the holder still has access.
     *
     * Narrower than HOLDING: a forfeited or expired seat is still counted
     * against the pool and no longer opens the course.
     *
     * @var list<string>
     */
    public const ACTIVE = [self::ASSIGNED, self::CONSUMED, self::COMPLETED];

    /**
     * The states a live assignment can still leave.
     *
     * `pending` is here because promoting one is exactly what a capacity rise
     * is for.
     *
     * @var list<string>
     */
    public const OPEN = [self::RESERVED, self::ASSIGNED, self::CONSUMED, self::COMPLETED, self::PENDING];

    /** @var list<string> */
    public const TERMINAL = [self::RELEASED, self::RECLAIMED, self::FORFEITED, self::EXPIRED, self::CANCELLED];

    /** @var array<string, list<string>> */
    private const EDGES = [
        self::RESERVED => [self::ASSIGNED, self::EXPIRED, self::FORFEITED, self::CANCELLED],
        // assigned to completed is a real edge and not a shortcut. A course
        // whose completion rule is met with no lesson and no attempt event
        // completes without ever passing through `consumed`, and the progress
        // listener writes exactly that; the table forbade it while the
        // listener wrote it, which is what happens when a machine has no
        // caller. Routing the listener through `consumed` instead would
        // fabricate a start that never happened and put a seat_started row in
        // the ledger for a learner who started nothing.
        self::ASSIGNED => [self::CONSUMED, self::COMPLETED, self::RELEASED, self::RECLAIMED,
            self::FORFEITED, self::EXPIRED],
        self::CONSUMED => [self::COMPLETED, self::RELEASED, self::RECLAIMED, self::FORFEITED, self::EXPIRED],
        // Absorbing with respect to reclaim, and that is a guard rather than a
        // policy: taking a seat back from somebody who finished would revoke
        // access to a certificate they earned and leave the certificate
        // listener and the ledger in permanent disagreement. The pool term is
        // the only edge out.
        self::COMPLETED => [self::EXPIRED],
        self::PENDING => [self::ASSIGNED, self::CANCELLED],
        self::RELEASED => [],
        self::RECLAIMED => [],
        self::FORFEITED => [],
        self::EXPIRED => [],
        self::CANCELLED => [],
    ];

    private function __construct()
    {
    }

    public static function known(string $state): bool
    {
        return \in_array($state, self::STATES, true);
    }

    public static function holds(string $state): bool
    {
        return \in_array($state, self::HOLDING, true);
    }

    public static function permits(string $from, string $to): bool
    {
        return \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * The machine itself, for the spec generator.
     *
     * The published transitions were hand maintained beside a private
     * constant, so `tooling/organisations/spec.php --check` passed while the
     * code wrote an edge the document did not list. The generator reads this
     * and fails when the two disagree; the guards and the prose stay hand
     * written, because neither is derivable from a list of pairs.
     *
     * @return array<string, list<string>>
     */
    public static function edges(): array
    {
        return self::EDGES;
    }

    /** Whether the learner has touched the course, read from the row rather than from a progress query. */
    public static function started(string $state): bool
    {
        return \in_array($state, [self::CONSUMED, self::COMPLETED], true);
    }

    public static function completed(string $state): bool
    {
        return $state === self::COMPLETED;
    }
}
