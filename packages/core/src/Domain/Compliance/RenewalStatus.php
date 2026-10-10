<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Compliance;

/**
 * The second, orthogonal axis: whether this person owes the requirement again.
 *
 * It is a separate column from {@see ComplianceStatus} because a person can be
 * compliant and due for renewal at the same time, and one enumeration cannot
 * express two independent facts without multiplying into thirty-six words that
 * no report can bucket.
 *
 * Closed for the same reason the other axis is closed.
 */
final class RenewalStatus
{
    public const NOT_DUE = 'not_due';
    public const DUE = 'due';

    /** The recertification window is open and the work may be redone now. */
    public const WINDOW_OPEN = 'window_open';

    public const RENEWAL_OVERDUE = 'renewal_overdue';

    /**
     * The published machine for this axis, from the design's own table.
     *
     * The one cycle in the pair: a renewal completed inside the window returns
     * to not_due, which is how a recertification that was done on time leaves
     * no trace on this axis.
     *
     * **Two edges were missing and the machine had a trap.** `renewal_overdue`
     * had no outgoing edge at all, so a lapsed certification could never be
     * renewed: a late recertification answered `not_due` and the sweep refused
     * it, for ever. And `due -> renewal_overdue` was not drawn, so a daily
     * sweep that missed the days between a due date and a lapse left the row
     * at `due` and permanently `compliant`, which is a lapsed certification
     * reported as good. A window a sweep does not observe is a window that
     * happens anyway.
     *
     * @var array<string, list<string>>
     */
    public const EDGES = [
        self::NOT_DUE => [self::DUE, self::WINDOW_OPEN],
        self::DUE => [self::WINDOW_OPEN, self::RENEWAL_OVERDUE],
        self::WINDOW_OPEN => [self::NOT_DUE, self::RENEWAL_OVERDUE],
        // A late recertification lands here. It returns to not_due, which is
        // the same edge a punctual one takes out of the window.
        self::RENEWAL_OVERDUE => [self::NOT_DUE, self::WINDOW_OPEN],
    ];

    private function __construct()
    {
    }

    /** Whether one renewal state may move to another; staying put is always permitted. */
    public static function permits(string $from, string $to): bool
    {
        return $from === $to || \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NOT_DUE, self::DUE, self::WINDOW_OPEN, self::RENEWAL_OVERDUE];
    }

    /** An unrecognised word reads as not due, which claims nothing about the person. */
    public static function of(?string $status): string
    {
        return $status !== null && \in_array($status, self::all(), true) ? $status : self::NOT_DUE;
    }
}
