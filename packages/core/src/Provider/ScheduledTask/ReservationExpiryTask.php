<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Gives back the seats that nobody claimed.
 *
 * Reserve-at-issue only works if the reservation ends. Without this task the
 * forty first invitation is refused correctly on Monday and the pool is still
 * one seat short in March, because `reserved_until` was written and nothing
 * ever read it. That is the same defect `seat_pools.ends_at` and
 * `invitations.expires_at` both had, and it is why all three sweeps land in
 * one wave rather than one per feature.
 *
 * **It is a scheduled task and never a synchronous release**, for the reason
 * the design gives for waitlist promotion: doing this inside the transaction
 * that took the next reservation deadlocks against the ledger write.
 *
 * The run is bounded and resumable. It takes a page of due rows, releases
 * each in its own transaction, and leaves the rest for the next run, so a
 * site that has not run its scheduler for a month drains over several passes
 * instead of timing out on one.
 */
final class ReservationExpiryTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'vidyalms.organisation.reservation_expiry';

    public function __construct(
        private readonly SweptRecordStore $records,
        private readonly OrganisationSweepAuthority $authority,
        private readonly OrganisationStore $organisations,
        private readonly SeatReservations $reservations,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $released = 0;
        $skipped = 0;

        foreach ($this->records->dueReservations(SeatAssignmentState::RESERVED) as $row) {
            $assignmentId = (int) $row['id'];
            $scope = $this->authority->forReservation($assignmentId);

            if ($scope === null) {
                // The administrator who spent the seat is gone from the row,
                // so there is nobody to act as. Counted rather than guessed
                // at: an expiry attributed to somebody who did not authorise
                // it is worse than one that waits for a person to look.
                ++$skipped;

                continue;
            }

            $this->records->database->transactional(function () use ($scope, $assignmentId): void {
                $next = $this->organisations->claim($scope, (int) $this->organisations->organisation($scope)['revision']);
                $this->reservations->release(
                    $scope,
                    $next,
                    $assignmentId,
                    SeatReservations::REASON_RESERVATION_EXPIRED,
                    self::KEY . ':' . $assignmentId
                );
                // The link goes with the seat. The invitation that took this
                // reservation stays pending and unexpired for days after the
                // reservation window closes, and it kept pointing at the
                // cancelled row: the console showed a promise the pool was not
                // holding, and the acceptance and the administrator's repair
                // both failed on a reservation that no longer existed. A
                // cleared link is what "this invitation promises membership
                // and no seat" looks like.
                $this->records->database->update(
                    'invitations',
                    ['seat_assignment_id' => null],
                    ['organisation_id' => $scope->organisationId, 'seat_assignment_id' => $assignmentId]
                );
                $this->organisations->event(
                    $scope,
                    $next,
                    'seats.reservation_expired',
                    null,
                    ['assignment_id' => $assignmentId],
                    MembershipEventContext::system((string) $assignmentId),
                );
            });
            ++$released;
        }

        return sprintf('released=%d skipped=%d', $released, $skipped);
    }

    public function binCount(): int
    {
        return 1;
    }

    /**
     * Hourly rather than daily.
     *
     * A reservation window is measured in days, so the granularity that
     * matters is how long a returned seat stays invisible after it is due
     * back. An hour is short enough that an administrator watching a pool
     * fill up sees the seat return the same afternoon, and long enough that
     * the queue carries one row per hour rather than one per cron tick on a
     * host whose scheduler fires on page loads.
     */
    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
