<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * Reserve at issue: the entry point that holds a seat for somebody who has
 * not accepted yet, and the two ways that hold ends.
 *
 * **Why it exists.** An org admin inviting forty one people into a forty seat
 * pool used to succeed forty one times and fail on the fortieth first
 * acceptance, a week later, in somebody else's browser. The count the console
 * showed was consumed rather than committed, and committed is the number an
 * administrator actually manages against. A reservation makes the promise and
 * the capacity the same fact.
 *
 * **The three verbs and nothing else.** reserve() takes a seat and writes
 * `seat_reserved` at delta -1. convert() turns a reservation into a live
 * assignment and writes `seat_assigned` at delta 0, because the seat was
 * already spent by the reservation and charging for it twice is what makes
 * the two derivations of the balance disagree. release() gives the seat back
 * and writes `seat_reservation_expired` at delta +1. Every one of the three
 * is a ledger row with a registered reason, so the reservation window is
 * visible in the ledger rather than inferred from the absence of one.
 *
 * **A reserved row has no member and may have no account.** That is the whole
 * point of an invitation: a pending invitation to somebody with no account
 * cannot be a membership row. So `seat_assignments.membership_id` and
 * `user_id` are nullable, and the holder key of a reservation names the
 * invitation rather than a user, which keeps the unique index meaningful:
 * two administrators inviting the same address concurrently produce one
 * invitation and therefore one reservation.
 *
 * **This is the reserve entry point the seat accounting owns.** It is a
 * separate class rather than three methods on ManageOrganisationSeats because
 * the invitation ceremony is its only caller today and a use case that
 * already refuses eleven things does not need three more branches. The
 * signature is the agreed one: whichever class ends up holding it, the three
 * verbs, their ledger events and their deltas are what the reconciliation
 * task and the reservation sweep are written against.
 */
final class SeatReservations
{
    /** Why a reservation was taken, from the published reason vocabulary. */
    public const REASON_RESERVED = SeatEventReason::GRANTED_BY_ADMIN;

    /** Why a reservation ended without being claimed. */
    public const REASON_RESERVATION_EXPIRED = SeatEventReason::RESERVATION_EXPIRED;

    /** Why a reservation ended because its invitation was withdrawn. */
    public const REASON_RECLAIMED = SeatEventReason::RECLAIMED_BY_ADMIN;

    public function __construct(
        private readonly OrganisationLearningStore $store,
        private readonly OrganisationStore $organisations,
        private readonly ComposedAccess $composed,
        private readonly GrantEntitlement $grant,
        private readonly OrganisationLearningEvents $events,
        /**
         * The threshold alerts, raised the moment a reservation takes the
         * seat that empties a pool.
         *
         * Optional so a harness with no notification engine still builds a
         * reservation path; absent, the reconciliation pass raises the same
         * alert on its next run, so the alert is late rather than lost.
         */
        private readonly ?SeatThresholdAlerts $alerts = null,
    ) {
    }

    /**
     * Holds one seat in a pool for a named holder reference.
     *
     * The holder reference is opaque to this class and is what makes the
     * reservation's holder key unique: the invitation ceremony passes its own
     * invitation id, so re-issuing against the same pending row reuses the
     * same reservation rather than taking a second seat.
     *
     * It refuses rather than queueing, which is the difference between this
     * and a direct assignment. A pending assignment row is something an
     * administrator can reprocess when a seat frees up; a pending invitation
     * would be a promise already in somebody's inbox, and the design's rule is
     * that the administrator is told at row forty one.
     *
     * @return array{id: int, pool: array<string, mixed>}
     */
    public function reserve(
        OrgScope $scope,
        int $revision,
        int $poolId,
        string $holderRef,
        ?int $userId,
        \DateTimeImmutable $until,
    ): array {
        $pool = $this->store->pool($scope, $poolId);
        $this->composed->requireRelease($scope, (int) $pool['course_id']);

        if (
            !\in_array((string) $pool['state'], SeatPoolState::SPENDABLE, true)
            || ($pool['starts_at'] !== null && $pool['starts_at'] > $this->store->now())
            || ($pool['ends_at'] !== null && $pool['ends_at'] <= $this->store->now())
        ) {
            throw new ConflictException('This pool is not currently available for assignment.');
        }

        $this->store->requireReconciled($scope, $poolId);
        $holderKey = $scope->organisationId . ':' . $poolId . ':' . $holderRef;
        $existing = $this->store->database->selectOne(OrgScopedReader::from($scope, 'seat_assignments')
            ->where('holder_key', '=', $holderKey));

        if ($existing !== null) {
            // A re-issued invitation keeps the seat it already holds. Taking a
            // second one would double-charge the pool for one promise, which
            // is exactly the arithmetic reserve-at-issue exists to fix.
            return ['id' => (int) $existing['id'], 'pool' => $pool];
        }

        if ($this->store->balance($scope, $poolId)['available'] < 1) {
            throw new ConflictException('This pool has no seat available to reserve for this invitation.');
        }

        $id = $this->store->insert('seat_assignments', $scope, [
            'pool_id' => $poolId, 'membership_id' => null, 'user_id' => $userId,
            'state' => SeatAssignmentState::RESERVED, 'holder_key' => $holderKey,
            'reserved_until' => $until->format('Y-m-d H:i:s'),
            'assigned_by' => $scope->actorId, 'assigned_at' => $this->store->now(),
        ]);
        $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            $id,
            SeatEventType::SEAT_RESERVED,
            -1,
            self::REASON_RESERVED,
            $userId,
            null,
            ['holder_ref' => $holderRef]
        );
        // Raised here rather than left to the nightly pass, because the person
        // who just spent the last seat is the person who can do something
        // about it and they are still at their desk.
        $this->alerts?->afterBalanceChange($scope, $pool, $this->store->balance($scope, $poolId));

        return ['id' => $id, 'pool' => $pool];
    }

    /**
     * Turns a reservation into a live assignment for the account that claimed
     * it, and grants the entitlement the assignment causes.
     *
     * **The grant runs under the issuing administrator's authority, re-asked
     * here rather than remembered.** The person accepting an invitation holds
     * no seat right and no course right, so their own principal cannot
     * authorise a grant; the administrator who spent the seat can, and asking
     * again at conversion means an administrator who has since lost the right
     * cannot have a grant land in their name a week later. There is no
     * synthetic actor anywhere on this path, for the reason
     * BackgroundActorResolver states.
     *
     * @return array{assignment_id: int, entitlement_id: int|null}
     */
    public function convert(OrgScope $scope, int $revision, int $assignmentId, int $membershipId, int $userId): array
    {
        $row = $this->reservation($scope, $assignmentId);
        $poolId = (int) $row['pool_id'];
        $pool = $this->store->pool($scope, $poolId);

        try {
            $this->store->update('seat_assignments', $scope, $assignmentId, [
                'state' => SeatAssignmentState::ASSIGNED, 'membership_id' => $membershipId, 'user_id' => $userId,
                'reserved_until' => null,
                'holder_key' => $scope->organisationId . ':' . $poolId . ':' . $userId,
            ]);
        } catch (UniqueConstraintViolation) {
            // The holder key this conversion is about to claim is already
            // held: the invitee was assigned a seat in this pool directly
            // while their invitation was outstanding. The assigning route now
            // refuses that, so this is the last line of defence and it answers
            // a conflict naming the pool rather than a 500 that leaves the
            // invitation pending and the second seat spent for ever.
            throw new ConflictException('This member already holds a seat in this pool.');
        }
        $entitlementId = $this->grantFor($scope, $revision, $assignmentId, $userId, $pool);
        $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            $assignmentId,
            SeatEventType::SEAT_ASSIGNED,
            0,
            self::REASON_RESERVED,
            $userId,
            $entitlementId
        );

        return ['assignment_id' => $assignmentId, 'entitlement_id' => $entitlementId];
    }

    /**
     * Gives an unclaimed seat back to its pool.
     *
     * Both callers, revocation by an administrator and the reservation sweep,
     * write the same event with a different reason, because the seat moves the
     * same way and only the cause differs. A reservation that has already been
     * converted is not released here: it is a live assignment and belongs to
     * the release ceremony, which has a reclaim policy to ask.
     */
    public function release(
        OrgScope $scope,
        int $revision,
        int $assignmentId,
        string $reason,
        ?string $idempotencyKey = null,
    ): void {
        $row = $this->reservation($scope, $assignmentId);
        $poolId = (int) $row['pool_id'];
        // cancelled rather than released, and this is the one place the state
        // machine and the design's prose had to be reconciled. The design's
        // edge is reserved to expired, but expired sits inside the seat
        // holding set unconditionally, so a +1 ledger row against a row that
        // still counts as held would make the two derivations of availability
        // disagree by every expired reservation. cancelled is the published
        // edge out of reserved, it is terminal, and it is outside the holding
        // set, so the seat really does return and the invariant still closes.
        $this->store->update('seat_assignments', $scope, $assignmentId, [
            'state' => SeatAssignmentState::CANCELLED, 'holder_key' => null, 'reserved_until' => null,
            'closed_at' => $this->store->now(),
        ]);
        $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            $assignmentId,
            SeatEventType::SEAT_RESERVATION_EXPIRED,
            1,
            $reason,
            $row['user_id'] === null ? null : (int) $row['user_id'],
            null,
            [],
            null,
            // A sweep passes a key and an administrator's revocation does not:
            // a cron may run the same period twice on both hosts by design, so
            // the replay has to be the same row rather than a second credit.
            $idempotencyKey === null
                ? OrganisationLearningStore::ACTOR_PRINCIPAL : OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            $idempotencyKey
        );
    }

    /**
     * One reservation, or a refusal.
     *
     * It reads through the scoped reader rather than through the learning
     * store's assignment(), because that method joins org_memberships to
     * narrow on a unit and a reservation has no membership to join to yet.
     *
     * @return array<string, mixed>
     */
    public function reservation(OrgScope $scope, int $assignmentId): array
    {
        return $this->heldReservation($scope, $assignmentId)
            ?? throw new EntityNotFoundException('No seat reservation here.');
    }

    /**
     * The same lookup, answering null rather than throwing.
     *
     * A reservation ends in three ways and only one of them is the invitation
     * being accepted: the reservation sweep cancels it when its window runs
     * out, and the pool term sweep forfeits it when the term ends. Both leave
     * the invitation pending and still pointing at the row, so every caller
     * that reads the link has to be able to ask "is this still a reservation"
     * and get an answer rather than an exception. Asking through
     * {@see reservation()} is what made a swept invitation permanently
     * unacceptable and permanently un-reissuable: the acceptance threw, the
     * repair threw the same way, and the invitation could never be finished
     * or replaced.
     *
     * @return array<string, mixed>|null
     */
    public function heldReservation(OrgScope $scope, int $assignmentId): ?array
    {
        $row = $this->store->database->selectOne(OrgScopedReader::from($scope, 'seat_assignments')
            ->where('id', '=', $assignmentId)->where('state', '=', SeatAssignmentState::RESERVED));

        return $row === null ? null : OrganisationLearningStore::numbers($row);
    }

    /** @param array<string, mixed> $pool */
    private function grantFor(OrgScope $scope, int $revision, int $assignmentId, int $userId, array $pool): ?int
    {
        $assignment = $this->store->database->selectOne(OrgScopedReader::from($scope, 'seat_assignments')
            ->where('id', '=', $assignmentId));
        $issuer = $assignment === null ? 0 : (int) $assignment['assigned_by'];

        if ($issuer < 1) {
            // The reservation records no author, which a row written by this
            // class never is. Answered rather than guessed at: a grant
            // attributed to somebody who did not authorise it is worse than a
            // seat an administrator has to grant by hand.
            return null;
        }

        $context = OrgEntitlementGrantContext::authorise(
            $this->composed,
            $this->organisations,
            Principal::user($issuer),
            $scope->organisationId,
            $userId,
            (int) $pool['course_id'],
            $revision,
            $assignmentId,
            $pool['ends_at'] === null ? null : (string) $pool['ends_at'],
        );
        [$grant, $events] = $this->grant->executeForOrganisation($context);
        $entitlementId = $grant->id();
        $this->store->update('seat_assignments', $scope, $assignmentId, ['entitlement_id' => $entitlementId]);
        $this->events->stage($events);

        return $entitlementId;
    }
}
