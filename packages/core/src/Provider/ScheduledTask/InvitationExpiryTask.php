<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ReassertsInvitations;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Expires the invitations nobody accepted, and prunes what they left behind.
 *
 * `invitations.expires_at` was written on every row and read by exactly one
 * thing: the presenter, which rendered a pending row past its date as
 * "expired" without ever writing that state. So the token was refused
 * correctly, the console said the right word, and the row stayed `pending`
 * for ever with a live `pending_key` and a reservation still holding a seat.
 * This is the writer that was missing.
 *
 * Three things happen per row and they are one transaction each. The
 * invitation moves to `expired`, which frees the pending key so a fresh
 * invitation to the same address is a new promise rather than a re-send of a
 * dead one. The reservation it was holding goes back to the pool. And the
 * expired mailbox proof claims are removed, because a claim row carries the
 * hash of a person's address and keeping it after it can never be spent again
 * is storage of personal data with no purpose left.
 *
 * **GitHub's carve-out, narrowed.** An invitation an active provisioning
 * source is still asserting is renewed by the next sync rather than expiring
 * silently, so a directory-managed roster does not fight its own LMS. It is
 * asked through the ProvisioningSource registry with an instanceof probe, and
 * a free build has no registered source, so nothing is skipped and everything
 * due expires. That is the published normal path and it is stated on
 * {@see ReassertsInvitations}.
 */
final class InvitationExpiryTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'vidyalms.organisation.invitation_expiry';

    public function __construct(
        private readonly SweptRecordStore $records,
        private readonly OrganisationSweepAuthority $authority,
        private readonly OrganisationStore $organisations,
        private readonly ProvisioningSourceRegistry $sources,
        private readonly ?SeatReservations $reservations = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $expired = 0;
        $renewed = 0;
        $skipped = 0;

        foreach ($this->records->expiredInvitations() as $row) {
            $invitationId = (int) $row['id'];

            if ($this->reasserted((int) $row['organisation_id'], (string) $row['email'])) {
                ++$renewed;

                continue;
            }

            $scope = $this->authority->forInvitation($invitationId);

            if ($scope === null) {
                ++$skipped;

                continue;
            }

            $reservationId = $row['seat_assignment_id'] === null ? null : (int) $row['seat_assignment_id'];
            $this->records->database->transactional(function () use ($scope, $invitationId, $reservationId): void {
                $this->expire($scope, $invitationId, $reservationId);
            });
            ++$expired;
        }

        $stranded = $this->releaseStranded();
        $pruned = 0;

        foreach ($this->records->prunableClaims() as $claimId) {
            $this->records->pruneClaim($claimId);
            ++$pruned;
        }

        return sprintf(
            'expired=%d renewed=%d skipped=%d stranded_released=%d claims_pruned=%d',
            $expired,
            $renewed,
            $skipped,
            $stranded,
            $pruned
        );
    }

    /**
     * Seats still held by an invitation that has already finished.
     *
     * A hard bounce is the case this exists for: the invitation goes to
     * `bounced` the moment the mail server says the address does not exist,
     * and without this pass its seat stays committed until the reservation
     * window runs out a week later. Revocation and acceptance both release or
     * convert inside their own transaction, so this finds nothing on a site
     * where nothing bounced.
     */
    private function releaseStranded(): int
    {
        if ($this->reservations === null) {
            return 0;
        }

        $released = 0;
        $rows = $this->records->strandedReservations(
            ['revoked', 'expired', 'bounced'],
            SeatAssignmentState::RESERVED
        );

        foreach ($rows as $row) {
            $scope = $this->authority->forInvitation((int) $row['id']);

            if ($scope === null) {
                continue;
            }

            $assignmentId = (int) $row['seat_assignment_id'];
            $this->records->database->transactional(function () use ($scope, $assignmentId, $row): void {
                $next = $this->organisations->claim($scope, (int) $this->organisations->organisation($scope)['revision']);

                try {
                    $this->reservations?->release(
                        $scope,
                        $next,
                        $assignmentId,
                        SeatReservations::REASON_RESERVATION_EXPIRED,
                        self::KEY . ':stranded:' . (int) $row['id']
                    );
                } catch (EntityNotFoundException) {
                    return;
                }
            });
            ++$released;
        }

        return $released;
    }

    private function expire(OrgScope $scope, int $invitationId, ?int $reservationId): void
    {
        $next = $this->organisations->claim($scope, (int) $this->organisations->organisation($scope)['revision']);
        $changed = $this->records->database->update(
            'invitations',
            ['state' => 'expired', 'pending_key' => null, 'token_hash' => null, 'updated_at' => $this->records->now()],
            ['organisation_id' => $scope->organisationId, 'id' => $invitationId, 'state' => 'pending']
        );

        if ($changed !== 1) {
            // Somebody accepted or revoked it between the read and this
            // write. The row is already terminal and the reservation is
            // already theirs to account for.
            return;
        }

        $this->records->database->update(
            'org_invitation_claims',
            ['state' => 'revoked', 'proof_hash' => null],
            ['organisation_id' => $scope->organisationId, 'invitation_id' => $invitationId, 'state' => 'pending']
        );

        if ($reservationId !== null && $this->reservations !== null) {
            try {
                $this->reservations->release(
                    $scope,
                    $next,
                    $reservationId,
                    SeatReservations::REASON_RESERVATION_EXPIRED,
                    self::KEY . ':' . $invitationId
                );
            } catch (EntityNotFoundException) {
                // Already converted by an acceptance or already returned by
                // the reservation sweep. Both are terminal and neither is a
                // failure of this expiry.
            }
        }

        $this->organisations->event(
            $scope,
            $next,
            'invitation.expired',
            null,
            ['invitation_id' => $invitationId],
            // A sweep and not a person. The default context recorded every one
            // of these as a human principal doing it by hand, which is the one
            // thing an audit reads this pair of columns to rule out.
            MembershipEventContext::system((string) $invitationId),
        );
    }

    /**
     * Whether any registered provisioning source is still asserting this
     * invitation.
     *
     * The scoped reader is not used here and cannot be: this asks a registry
     * rather than a table. The organisation id it passes came from the row
     * the sweep already read, and a source that answers for another tenant's
     * address is answering a question it was not asked, which the interface
     * says it may not do.
     */
    private function reasserted(int $organisationId, string $email): bool
    {
        foreach ($this->sources->all() as $source) {
            if ($source instanceof ReassertsInvitations && $source->reassertsInvitation($organisationId, $email)) {
                return true;
            }
        }

        return false;
    }

    public function binCount(): int
    {
        return 1;
    }

    /** Hourly, on the same reasoning the reservation sweep gives. */
    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
