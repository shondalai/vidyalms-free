<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The six stored pool states and the edges between them.
 *
 * It is a class rather than an inline literal inside the saving use case for
 * two reasons. The spec generator nominates this exact constant as the source
 * of the published machine, so the document stops being a second, drifting
 * copy of the vocabulary. And a state machine expressed as an in_array of two
 * values is not a machine: four of the six states were unreachable, which is
 * how a pool that a seller has suspended, a term that has ended and a pool an
 * administrator has closed all looked identical to a console.
 *
 * `exhausted` is deliberately absent. It is available equals zero, computed on
 * read, and a stored copy would drift from the ledger on every release.
 */
final class SeatPoolState
{
    public const DRAFT = 'draft';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const EXPIRED = 'expired';
    public const CLOSED = 'closed';

    /** @var list<string> */
    public const STATES = [
        self::DRAFT,
        self::AWAITING_PAYMENT,
        self::ACTIVE,
        self::SUSPENDED,
        self::EXPIRED,
        self::CLOSED,
    ];

    /** Nothing leaves a closed pool, which is what terminal means. */
    public const TERMINAL = [self::CLOSED];

    /**
     * The states in which a pool may still take a new assignment.
     *
     * A suspended pool keeps every seat it has already given out and accepts
     * no new one, which is the whole of what a seller's hold means.
     */
    public const SPENDABLE = [self::ACTIVE];

    /**
     * The guarded edges, exactly as the published machine draws them.
     *
     * @var array<string, list<string>>
     */
    private const EDGES = [
        self::DRAFT => [self::AWAITING_PAYMENT, self::ACTIVE, self::CLOSED],
        self::AWAITING_PAYMENT => [self::ACTIVE, self::CLOSED],
        self::ACTIVE => [self::SUSPENDED, self::EXPIRED, self::CLOSED],
        self::SUSPENDED => [self::ACTIVE, self::EXPIRED, self::CLOSED],
        self::EXPIRED => [self::CLOSED],
        self::CLOSED => [],
    ];

    /**
     * The ledger event a transition writes, or null where the edge is not one
     * the published vocabulary names an event for.
     *
     * The four this wave makes reachable are here; a funding transition
     * carries its own quantity and is written by the quantity path instead,
     * because a funding event is the one kind that does not carry a zero
     * delta.
     *
     * @var array<string, string>
     */
    private const ENTRY_EVENTS = [
        self::SUSPENDED => 'pool_suspended',
        self::ACTIVE => 'pool_resumed',
        self::EXPIRED => 'pool_expired',
        self::CLOSED => 'pool_closed',
    ];

    private function __construct()
    {
    }

    public static function known(string $state): bool
    {
        return \in_array($state, self::STATES, true);
    }

    public static function spendable(string $state): bool
    {
        return \in_array($state, self::SPENDABLE, true);
    }

    /** Whether a pool may move from one state to another; a no-op move is always allowed. */
    public static function permits(string $from, string $to): bool
    {
        if ($from === $to) {
            return self::known($from);
        }

        return \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /**
     * The states a pool may be created in.
     *
     * A creation used to accept any known state, because the edge guard was
     * conditioned on there being a previous row, so a pool could be written
     * straight into `expired` or `suspended` and be funded on the way in. The
     * machine says both are reachable only from `active`, and a console that
     * shows a suspended pool with no `pool_suspended` row cannot say when it
     * was suspended or by whom. `closed` is left out for the same reason:
     * creating a terminal pool is creating nothing.
     *
     * @var list<string>
     */
    public const CREATABLE = [self::DRAFT, self::AWAITING_PAYMENT, self::ACTIVE];

    /**
     * The states in which a pool's quantity has been funded.
     *
     * A pool created in draft or awaiting_payment holds a quantity nobody has
     * paid for, so it has no funded capacity and no funding ledger row until
     * it enters `active`. The stamp is `seat_pools.funded_at` rather than this
     * list, because a pool abandoned in draft may be closed without ever being
     * funded and its state alone can no longer say which it was.
     */
    public static function funds(string $from, string $to): bool
    {
        return $to === self::ACTIVE && \in_array($from, [self::DRAFT, self::AWAITING_PAYMENT], true);
    }

    /**
     * The machine itself, for the spec generator.
     *
     * @return array<string, list<string>>
     */
    public static function edges(): array
    {
        return self::EDGES;
    }

    /**
     * The zero-delta pool event a state entry writes.
     *
     * A move into `active` writes `pool_resumed` only when it came from
     * `suspended`: entering `active` from `draft` is the pool being granted,
     * which the funding event already records with its quantity.
     */
    public static function entryEvent(string $from, string $to): ?string
    {
        if ($from === $to) {
            return null;
        }

        if ($to === self::ACTIVE && $from !== self::SUSPENDED) {
            return null;
        }

        return self::ENTRY_EVENTS[$to] ?? null;
    }
}
