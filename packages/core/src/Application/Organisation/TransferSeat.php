<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Moving a learner's seat from one pool to another, as ONE use case.
 *
 * **It acquires before it releases, and the order is the whole point.**
 * Microsoft documents that moving a user between licensed groups is
 * order-dependent and that removing the old licence first leaves the person
 * unlicensed while the directory catches up. Two administrator actions, a
 * release and then an assign, have exactly that gap, and in this product the
 * gap is worse than a pause: a release revokes the entitlement, and a learner
 * mid attempt when it happens is refused by the organisation access gate.
 *
 * So the acquiring event is written first, the releasing event second, and
 * both are inside one transaction. If the receiving pool has no seat the
 * whole thing refuses and the learner keeps the one they had, which is the
 * only safe direction to be wrong in.
 *
 * **Two rows in the ledger, not one.** `seat_transferred` at -1 on the
 * acquiring pool, then `seat_transferred` at +1 on the releasing pool. Each
 * pool's own balance therefore reconciles on its own, which a single row
 * naming two pools could never do.
 *
 * The two pools must fund the same course. A seat is what one learner needs
 * for one course, so moving it somewhere that buys a different course is not
 * a transfer, it is a release and a purchase, and calling it a transfer would
 * put a course change in a row that says the holder did not change.
 */
final class TransferSeat
{
    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        private readonly ComposedAccess $composed,
        private readonly UsersPort $users,
        private readonly GrantEntitlement $grant,
        private readonly RevokeEntitlement $revoke,
        private readonly OrganisationLearningEvents $events,
        /**
         * The threshold alerts, raised on the receiving pool.
         *
         * A transfer is a balance change like any other and raised nothing:
         * the pool a seat moves into can be the pool that runs out, and the
         * administrator heard about it on the next day's reconciliation. The
         * releasing pool gains a seat, so it can only move away from a
         * threshold and is not asked.
         *
         * Optional so a harness with no notification engine still builds a
         * transfer; absent, the reconciliation pass raises the same alert
         * under the same occurrence key.
         */
        private readonly ?SeatThresholdAlerts $alerts = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function execute(Principal $actor, int $orgId, int $assignmentId, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'target_pool_id']);
        $expected = OrganisationInput::integer($input, 'expected_revision');
        $targetPoolId = OrganisationInput::integer($input, 'target_pool_id');

        return $this->store->database->transactional(
            function () use ($actor, $orgId, $assignmentId, $targetPoolId, $expected): array {
                $scope = $this->access->require($actor, $orgId, 'seats');
                $next = $this->organisations->claim($scope, $expected);
                $source = $this->store->assignment($scope, $assignmentId);
                $sourcePoolId = (int) $source['pool_id'];

                if ($sourcePoolId === $targetPoolId) {
                    throw ValidationException::forField('target_pool_id', 'A seat cannot be transferred to its own pool.');
                }

                if (!\in_array((string) $source['state'], [SeatAssignmentState::ASSIGNED, SeatAssignmentState::CONSUMED], true)) {
                    throw new ConflictException('Only a live seat can be transferred.');
                }

                $sourcePool = $this->store->pool($scope, $sourcePoolId);
                $targetPool = $this->store->pool($scope, $targetPoolId);

                if ((int) $sourcePool['course_id'] !== (int) $targetPool['course_id']) {
                    throw ValidationException::forField('target_pool_id', 'Both pools must fund the same course.');
                }

                $this->requireSpendable($targetPool);
                $this->composed->requireRelease($scope, (int) $targetPool['course_id']);
                $this->store->requireReconciled($scope, $sourcePoolId);
                $this->store->requireReconciled($scope, $targetPoolId);

                if ($this->store->balance($scope, $targetPoolId)['available'] < 1) {
                    throw new ConflictException('The receiving pool has no seat available for this transfer.');
                }

                $userId = (int) $source['user_id'];
                $membershipId = (int) $source['membership_id'];
                if ($this->store->currentAssignment($scope, $targetPoolId, $userId) !== null) {
                    throw new ConflictException('The learner already holds a seat in the receiving pool.');
                }
                $this->store->requireNoOutstandingReservation(
                    $scope,
                    $targetPoolId,
                    $this->users->findById($userId)?->email,
                );
                $acquired = $this->acquire($scope, $next, $targetPool, $targetPoolId, $userId, $membershipId, $source, $actor);
                $this->release($scope, $next, $sourcePoolId, $assignmentId, $userId, $source);
                $this->organisations->event($scope, $next, 'seats.transferred', $userId, [
                    'from_pool_id' => $sourcePoolId, 'to_pool_id' => $targetPoolId,
                    'from_assignment_id' => $assignmentId, 'to_assignment_id' => $acquired,
                ]);

                $balance = $this->store->balance($scope, $targetPoolId);
                $this->alerts?->afterBalanceChange($scope, $targetPool, $balance);

                return ['organisation_revision' => $next, 'outcome' => 'transferred',
                    'item' => $this->store->assignment($scope, $acquired),
                    'balance' => $balance,
                    'source_balance' => $this->store->balance($scope, $sourcePoolId)];
            }
        );
    }

    /**
     * The acquiring half, written first.
     *
     * A fresh assignment row with a fresh identity, never a pool id rewritten
     * on the old one: a revoked or reclaimed assignment is never resurrected
     * and a re-seating is a new identity, which is what keeps the source ref
     * of the new grant distinct from the source ref of the one being revoked.
     *
     * @param array<string, mixed> $targetPool
     * @param array<string, mixed> $source
     */
    private function acquire(
        OrgScope $scope,
        int $revision,
        array $targetPool,
        int $targetPoolId,
        int $userId,
        int $membershipId,
        array $source,
        Principal $actor,
    ): int {
        // Written as `assigned` and moved to the source's state afterwards,
        // because the grant context binds an assigned row with no entitlement
        // and that binding is what mints a SEAT source reference rather than
        // an organisation-grant one. A transferred seat that lost its seat
        // provenance would be an entitlement the reconciliation report cannot
        // explain, which is the one thing the assignment-causes-entitlement
        // rule exists to prevent.
        $id = $this->store->insert('seat_assignments', $scope, [
            'pool_id' => $targetPoolId, 'membership_id' => $membershipId, 'user_id' => $userId,
            'state' => SeatAssignmentState::ASSIGNED,
            'holder_key' => $scope->organisationId . ':' . $targetPoolId . ':' . $userId,
            'assigned_by' => $actor->userId, 'assigned_at' => $this->store->now(),
        ]);
        $context = OrgEntitlementGrantContext::authorise(
            $this->composed,
            $this->organisations,
            $actor,
            $scope->organisationId,
            $userId,
            (int) $targetPool['course_id'],
            $revision,
            $id,
            $targetPool['ends_at'] === null ? null : (string) $targetPool['ends_at'],
        );
        [$grant, $events] = $this->grant->executeForOrganisation($context);
        $carried = (string) $source['state'];
        $this->store->update('seat_assignments', $scope, $id, ['entitlement_id' => $grant->id()]
            + ($carried === SeatAssignmentState::ASSIGNED ? [] : ['state' => $carried]));
        $this->events->stage($events);
        $this->store->ledger(
            $scope,
            $revision,
            $targetPoolId,
            $id,
            SeatEventType::SEAT_TRANSFERRED,
            -1,
            SeatEventReason::GRANTED_BY_ADMIN,
            $userId,
            $grant->id(),
            ['from_assignment_id' => (int) $source['id'], 'from_pool_id' => (int) $source['pool_id']]
        );

        return $id;
    }

    /**
     * The releasing half, written second.
     *
     * No reclaim policy is asked, and that is deliberate rather than an
     * omission: the reclaim policy decides whether a seat may be taken away
     * from a learner, and a transfer takes nothing away. The learner holds a
     * seat before it and a seat after it, and refusing here under
     * `before_start` would strand an organisation that simply moved a cost
     * centre.
     *
     * @param array<string, mixed> $source
     */
    private function release(OrgScope $scope, int $revision, int $poolId, int $assignmentId, int $userId, array $source): void
    {
        $this->store->update('seat_assignments', $scope, $assignmentId, [
            'state' => SeatAssignmentState::RELEASED, 'holder_key' => null,
            'closed_at' => $this->store->now(), 'problem_code' => null,
        ]);

        if ($source['entitlement_id'] !== null) {
            $this->events->stage($this->revoke->executeForOrganisation(
                $scope,
                $this->store->database,
                (int) $source['entitlement_id'],
                SeatEventReason::REVOKED_SEAT_WITHDRAWN
            ));
        }

        $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            $assignmentId,
            SeatEventType::SEAT_TRANSFERRED,
            1,
            SeatEventReason::RECLAIMED_BY_ADMIN,
            $userId,
            $source['entitlement_id'] === null ? null : (int) $source['entitlement_id']
        );
    }

    /** @param array<string, mixed> $pool */
    private function requireSpendable(array $pool): void
    {
        if (
            !\in_array((string) $pool['state'], SeatPoolState::SPENDABLE, true)
            || ($pool['starts_at'] !== null && $pool['starts_at'] > $this->store->now())
            || ($pool['ends_at'] !== null && $pool['ends_at'] <= $this->store->now())
        ) {
            throw new ConflictException('The receiving pool is not currently available for assignment.');
        }
    }
}
