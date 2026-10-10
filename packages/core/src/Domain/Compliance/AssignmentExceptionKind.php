<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

/**
 * The six reasons an assignment stops and asks a person rather than proceeding.
 *
 * Following Totara, which raises an exception and emails the administrator
 * rather than assigning silently. The design's point is that every one of these
 * is a fact somebody has to decide about, and an assignment routine that skipped
 * them would produce a compliance report whose denominator is quietly wrong.
 *
 * **`anchor_unresolved` is the one a relative-anchor design hits first.** The
 * shipped `ScheduleAnchor` registry supplies the anchor and publishes that an
 * unresolved anchor blocks rather than opens; a requirement whose anchor
 * resolves to null therefore writes a row here instead of assigning a date
 * nobody can defend or skipping a person nobody notices.
 */
final class AssignmentExceptionKind
{
    /** A person has an agreed extension the schedule has to honour. */
    public const TIME_ALLOWANCE = 'time_allowance';

    /** The schedule has nothing to count from. */
    public const ANCHOR_UNRESOLVED = 'anchor_unresolved';

    /** The same requirement reaches this person twice. */
    public const DUPLICATE_REQUIREMENT = 'duplicate_requirement';

    /** The course is required and this person has no organisation grant for it. */
    public const NO_ENTITLEMENT = 'no_entitlement';

    /** The grant would come from a seat pool and none is available. */
    public const NO_SEAT = 'no_seat';

    /** The requirement names somebody who is not an active member here. */
    public const USER_NOT_IN_ORG = 'user_not_in_org';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::TIME_ALLOWANCE,
            self::ANCHOR_UNRESOLVED,
            self::DUPLICATE_REQUIREMENT,
            self::NO_ENTITLEMENT,
            self::NO_SEAT,
            self::USER_NOT_IN_ORG,
        ];
    }

    public static function isKnown(string $kind): bool
    {
        return \in_array($kind, self::all(), true);
    }
}
