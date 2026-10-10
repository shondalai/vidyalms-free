<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The published seat ledger vocabulary, free half.
 *
 * The built code wrote eight keys and three of them were invented at the call
 * site: `pool_updated` for every pool edit whatever it changed,
 * `overallocation` for the row Entra calls a CountViolation, and
 * `overallocation_cancelled` for its withdrawal. A ledger whose event names
 * are typed where they are written is a ledger nobody can group, and the
 * reconciliation report groups on exactly this column.
 *
 * The three Pro waitlist types and the two Business true-up types are not
 * here: a free build has no cohort queue and no purchase order, so an event
 * key it can never write does not belong in its vocabulary.
 */
final class SeatEventType
{
    public const POOL_CREATED = 'pool_created';
    public const POOL_FUNDED = 'pool_funded';
    public const POOL_QUANTITY_INCREASED = 'pool_quantity_increased';
    public const POOL_QUANTITY_DECREASED = 'pool_quantity_decreased';
    public const POOL_SUSPENDED = 'pool_suspended';
    public const POOL_RESUMED = 'pool_resumed';
    public const POOL_EXPIRED = 'pool_expired';
    public const POOL_CLOSED = 'pool_closed';

    public const SEAT_RESERVED = 'seat_reserved';
    public const SEAT_RESERVATION_EXPIRED = 'seat_reservation_expired';
    public const SEAT_ASSIGNED = 'seat_assigned';
    public const SEAT_STARTED = 'seat_started';
    public const SEAT_COMPLETED = 'seat_completed';
    public const SEAT_RELEASED = 'seat_released';
    public const SEAT_RECLAIMED = 'seat_reclaimed';
    public const SEAT_FORFEITED = 'seat_forfeited';
    public const SEAT_EXPIRED = 'seat_expired';
    public const SEAT_TRANSFERRED = 'seat_transferred';

    /**
     * The Business over-allocation record, written free.
     *
     * The event is Business in the published editions table because the two
     * stances that produce it in quantity are, but the free
     * `refuse_and_queue` provider records exactly the same fact with a zero
     * delta, and it is that record which makes twenty people visible instead
     * of discarded. Writing it under the published name rather than under an
     * invented one is the whole point of this class.
     */
    public const OVERALLOCATION_RECORDED = 'overallocation_recorded';

    /** @var list<string> */
    public const TYPES = [
        self::POOL_CREATED,
        self::POOL_FUNDED,
        self::POOL_QUANTITY_INCREASED,
        self::POOL_QUANTITY_DECREASED,
        self::POOL_SUSPENDED,
        self::POOL_RESUMED,
        self::POOL_EXPIRED,
        self::POOL_CLOSED,
        self::OVERALLOCATION_RECORDED,
        self::SEAT_RESERVED,
        self::SEAT_RESERVATION_EXPIRED,
        self::SEAT_ASSIGNED,
        self::SEAT_STARTED,
        self::SEAT_COMPLETED,
        self::SEAT_RELEASED,
        self::SEAT_RECLAIMED,
        self::SEAT_FORFEITED,
        self::SEAT_EXPIRED,
        self::SEAT_TRANSFERRED,
    ];

    /**
     * The event a terminal assignment state writes.
     *
     * @var array<string, string>
     */
    private const CLOSING_EVENTS = [
        SeatAssignmentState::RELEASED => self::SEAT_RELEASED,
        SeatAssignmentState::RECLAIMED => self::SEAT_RECLAIMED,
        SeatAssignmentState::FORFEITED => self::SEAT_FORFEITED,
        SeatAssignmentState::EXPIRED => self::SEAT_EXPIRED,
        SeatAssignmentState::CANCELLED => self::OVERALLOCATION_RECORDED,
    ];

    private function __construct()
    {
    }

    public static function known(string $event): bool
    {
        return \in_array($event, self::TYPES, true);
    }

    /**
     * The ledger key for an assignment reaching a closed state.
     *
     * A cancelled over-allocation row lands on `overallocation_recorded` with
     * a zero delta rather than on an invented `overallocation_cancelled`,
     * because it never held a seat: the withdrawal changes no balance and the
     * reason key is what says which of the two it was.
     */
    public static function forClosedState(string $state): string
    {
        return self::CLOSING_EVENTS[$state]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \InvalidArgumentException(sprintf('No seat event closes the state "%s".', $state));
    }

    /**
     * Whether the seat returns to the pool when the assignment reaches this
     * state.
     *
     * Released and reclaimed credit the seat back. Forfeited and expired do
     * not, because the seat was spent: Canvas Catalog's rule that bulk seat
     * sales are final is what makes an unclaimed seat legitimately expire
     * worthless rather than silently unbalancing the ledger.
     */
    public static function creditsBack(string $event): bool
    {
        return \in_array($event, [self::SEAT_RELEASED, self::SEAT_RECLAIMED, self::SEAT_RESERVATION_EXPIRED], true);
    }
}
