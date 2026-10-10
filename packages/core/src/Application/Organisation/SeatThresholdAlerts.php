<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Events\SeatPoolThresholdReached;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Raises the three seat threshold alerts, in one place, from the balance the
 * caller already has.
 *
 * Two kinds of caller reach it and both matter. A seat moving raises the
 * alert at the moment it happens, so an administrator inviting the last
 * person into a pool hears about it that afternoon rather than on the next
 * cron. The reconciliation pass raises it for the pools nobody touched, which
 * is where a pool that was already full when the feature shipped, or one
 * whose two derivations have diverged, is finally noticed.
 *
 * **Both write the same occurrence key**, so a pool that exhausts and is then
 * inspected by the sweep inside the same period produces one queue row and
 * one mail. That is the whole reason this is one class: two callers spelling
 * an occurrence key differently is two mails about one fact.
 *
 * The enqueue is a direct call inside the caller's transaction, never an
 * event listener, on the rule NotificationEnqueue states: a listener that
 * throws leaves the business state committed and the message lost.
 */
final class SeatThresholdAlerts
{
    /** How few seats count as running low, when nothing says otherwise. */
    public const LOW_WATER_SETTING = 'organisation.seat_low_water';
    public const DEFAULT_LOW_WATER = 5;

    public function __construct(
        private readonly NotificationEnqueue $outbox,
        private readonly ClockPort $clock,
        private readonly Settings $settings,
    ) {
    }

    /**
     * The alert a balance change earns, if it earns one.
     *
     * Nothing is raised while a pool has room, which is most changes on most
     * pools. The two that are raised are the two an administrator can act on:
     * it is nearly out, or it is out.
     *
     * @param array<string, mixed>                                                             $pool
     * @param array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} $balance
     */
    public function afterBalanceChange(OrgScope $scope, array $pool, array $balance): void
    {
        $remaining = $balance['available'];
        $threshold = match (true) {
            $remaining <= 0 => SeatPoolThresholdReached::EXHAUSTED,
            $remaining <= $this->lowWater() => SeatPoolThresholdReached::LIMITED,
            default => null,
        };

        if ($threshold === null) {
            return;
        }

        $this->raise($scope, $pool, $balance, $threshold, '');
    }

    /**
     * The scheduled summary for one pool in one period.
     *
     * A pool with no seats left produces the exhausted alert rather than the
     * summary, and one that is nearly out produces the limited alert, because
     * a summary and an alert about the same pool in the same period are two
     * mails saying one thing. The summary is what is left over: a pool that
     * is fine, or a pool whose numbers do not add up.
     *
     * @param array<string, mixed>                                                             $pool
     * @param array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} $balance
     */
    public function summary(OrgScope $scope, array $pool, array $balance, string $periodBucket): void
    {
        $remaining = $balance['available'];
        $threshold = match (true) {
            $remaining <= 0 => SeatPoolThresholdReached::EXHAUSTED,
            $remaining <= $this->lowWater() => SeatPoolThresholdReached::LIMITED,
            default => SeatPoolThresholdReached::PERIODIC,
        };

        $this->raise(
            $scope,
            $pool,
            $balance,
            $threshold,
            $threshold === SeatPoolThresholdReached::PERIODIC ? $periodBucket : ''
        );
    }

    /**
     * @param array<string, mixed>                                                             $pool
     * @param array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} $balance
     */
    private function raise(OrgScope $scope, array $pool, array $balance, string $threshold, string $bucket): void
    {
        $courseId = (int) $pool['course_id'];
        $this->outbox->forEvent(new SeatPoolThresholdReached(
            $this->clock->now(),
            $threshold,
            $scope->organisationId,
            (int) $pool['id'],
            $courseId,
            (string) $pool['name'],
            $balance['capacity'],
            $balance['available'],
            $balance['reconciled'],
            $bucket,
        ), $courseId);
    }

    private function lowWater(): int
    {
        $stored = $this->settings->getIntish(self::LOW_WATER_SETTING, self::DEFAULT_LOW_WATER);

        return $stored < 1 ? self::DEFAULT_LOW_WATER : $stored;
    }
}
