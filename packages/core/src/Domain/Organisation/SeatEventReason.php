<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The eleven free reason keys, their groups, and the translation from the
 * words the built code invented onto them.
 *
 * A reason is evidence. The ledger row already said why a seat moved, but it
 * said it in whatever words the writing statement happened to use
 * (`site_allocation`, `member_assignment`, `CountViolation`,
 * `manager_reclaim`), so no report could group on it and no label could be
 * offered for it. The keys below are the published vocabulary; the map below
 * them is the one place the older words are translated, so a reader does not
 * have to know which wave wrote a row.
 *
 * **An unmapped reason is kept, not dropped.** Every path that stores one
 * asks the registry for a label and stores the key either way, because losing
 * the reason is a bigger loss than losing its label.
 */
final class SeatEventReason
{
    public const REVOKED_REFUND = 'revoked_refund';
    public const REVOKED_DISPUTE = 'revoked_dispute';
    public const REVOKED_SEAT_WITHDRAWN = 'revoked_seat_withdrawn';
    public const MEMBER_LEFT_ORG = 'member_left_org';
    public const MEMBERSHIP_SUSPENDED = 'membership_suspended';
    public const UNIT_MOVED = 'unit_moved';
    public const RECLAIMED_BY_ADMIN = 'reclaimed_by_admin';
    public const EXPIRED_WITH_TERM = 'expired_with_term';
    public const RESERVATION_EXPIRED = 'reservation_expired';
    public const GRANTED_BY_ADMIN = 'granted_by_admin';

    /**
     * A seat handed back that the pool's reclaim policy would not credit.
     *
     * It is the reason on the designed assigned-to-forfeited and
     * consumed-to-forfeited edges, and those edges had no writer at all: the
     * release ceremony threw when the policy refused a credit, so a pool whose
     * policy is `never` could not take a seat back even as a forfeit and the
     * learner's access stayed open. The seat is spent either way, so the
     * ledger row carries a zero delta; this key is what tells it apart from a
     * term end, which is also a zero delta and is not somebody handing a seat
     * back.
     */
    public const RETURNED_BUT_NOT_CREDITED = 'returned_but_not_credited';

    /** @var list<string> */
    public const FREE_REASONS = [
        self::REVOKED_REFUND,
        self::REVOKED_DISPUTE,
        self::REVOKED_SEAT_WITHDRAWN,
        self::MEMBER_LEFT_ORG,
        self::MEMBERSHIP_SUSPENDED,
        self::UNIT_MOVED,
        self::RECLAIMED_BY_ADMIN,
        self::EXPIRED_WITH_TERM,
        self::RESERVATION_EXPIRED,
        self::GRANTED_BY_ADMIN,
        self::RETURNED_BUT_NOT_CREDITED,
    ];

    /**
     * Which question a reason answers, so a ledger read can group on something
     * narrower than the whole vocabulary.
     *
     * Three groups and no more: money, the relationship between a person and
     * an organisation, and the seat's own lifecycle. A report that wants
     * "every seat this organisation lost to a refund" groups on the first, and
     * one that wants "every seat lost because somebody left" groups on the
     * second, without either of them enumerating keys a package may have
     * added.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        self::REVOKED_REFUND => self::GROUP_COMMERCE,
        self::REVOKED_DISPUTE => self::GROUP_COMMERCE,
        self::REVOKED_SEAT_WITHDRAWN => self::GROUP_COMMERCE,
        self::MEMBER_LEFT_ORG => self::GROUP_MEMBERSHIP,
        self::MEMBERSHIP_SUSPENDED => self::GROUP_MEMBERSHIP,
        self::UNIT_MOVED => self::GROUP_MEMBERSHIP,
        self::RECLAIMED_BY_ADMIN => self::GROUP_LIFECYCLE,
        self::EXPIRED_WITH_TERM => self::GROUP_LIFECYCLE,
        self::RESERVATION_EXPIRED => self::GROUP_LIFECYCLE,
        self::GRANTED_BY_ADMIN => self::GROUP_LIFECYCLE,
        self::RETURNED_BUT_NOT_CREDITED => self::GROUP_LIFECYCLE,
    ];

    public const GROUP_COMMERCE = 'commerce';
    public const GROUP_MEMBERSHIP = 'membership';
    public const GROUP_LIFECYCLE = 'lifecycle';

    /** @var list<string> */
    public const GROUPS_LIST = [self::GROUP_COMMERCE, self::GROUP_MEMBERSHIP, self::GROUP_LIFECYCLE];

    /** The group a free reason belongs to; a key nothing publishes is lifecycle, which is the widest of the three. */
    public static function group(string $reason): string
    {
        return self::GROUPS[$reason] ?? self::GROUP_LIFECYCLE;
    }

    /**
     * The words earlier waves wrote, mapped onto the published ones.
     *
     * The revoking callers outside this area (a course withdrawal, a
     * membership change, an organisation suspension) pass their own reason
     * through to RevokeEntitlement as well, and that string is not touched
     * here: only the seat ledger's own reason_key is normalised, because it
     * is the column the reconciliation and delivery reports group on.
     *
     * @var array<string, string>
     */
    private const TRANSLATIONS = [
        'site_allocation' => self::GRANTED_BY_ADMIN,
        'member_assignment' => self::GRANTED_BY_ADMIN,
        'CountViolation' => self::GRANTED_BY_ADMIN,
        'manager_reclaim' => self::RECLAIMED_BY_ADMIN,
        'org_membership_left' => self::MEMBER_LEFT_ORG,
        'org_membership_suspended' => self::MEMBERSHIP_SUSPENDED,
        'org_course_withdrawn' => self::REVOKED_SEAT_WITHDRAWN,
    ];

    private function __construct()
    {
    }

    /** The published key for a reason a caller supplied, or the caller's own word where none is published. */
    public static function normalise(string $reason): string
    {
        return self::TRANSLATIONS[$reason] ?? $reason;
    }
}
