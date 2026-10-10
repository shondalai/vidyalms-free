<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatExpiryOutcome;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\SeatReclaim\SeatReclaimRegistry;

/** One release ceremony shared by explicit reclaim and forced relationship termination. */
final class SeatLifecycle
{
    public function __construct(
        private readonly OrganisationLearningStore $store,
        private readonly SeatReclaimRegistry $policies,
        private readonly RevokeEntitlement $revoke,
        private readonly OrganisationLearningEvents $events,
        private readonly ClockPort $clock,
        /**
         * The attempt half of the revocation ceremony.
         *
         * Optional so a harness with no assessment engine still builds a seat
         * lifecycle; absent, a revocation closes no attempt, which is what
         * every build did before this wave. It is not optional in the kernel.
         */
        private readonly ?CloseAttemptsOnAccessLoss $attempts = null,
    ) {
    }

    /**
     * Closes an assignment and returns the seat to the pool where the policy
     * allows it.
     *
     * **The reclaim decision no longer queries progress.** It used to select
     * the learner's enrolment row and read status, progress_percent and
     * last_activity_at, which is the one query the design forbids the reclaim
     * policy to make: the seat aggregate was not closed, and a change to how
     * progress is stored would have changed who could take a seat back. The
     * assignment's own state carries both facts now, written by the listener
     * on the existing progress and completion events.
     *
     * **In-flight attempts are closed here rather than refused later.** A
     * revocation at minute forty of a sixty minute attempt used to leave the
     * learner unable to save or submit, with answers in attempt_answer_events
     * and no grade: the guard on the assessment path is a post-hoc check for
     * a stale client, and it was standing in for a ceremony that did not
     * exist. The disposition defaults to submit_and_grade, the closure runs
     * inside this transaction, and the seat_events row that caused it is
     * recorded on every attempt it touches. It runs only when the grant being
     * revoked is the last one that confers access, which is the same question
     * {@see RevokeEntitlement::isLastEffectiveGrant()} answers for the revoke.
     *
     * **A policy that refuses a credit forfeits the seat rather than refusing
     * the return.** It used to throw, which left the designed
     * assigned-to-forfeited and consumed-to-forfeited edges with no writer at
     * all: a pool whose reclaim policy is `never` could not take a seat back
     * even as an outright forfeit, so the row stayed assigned and the
     * learner's access stayed open. The seat is closed at a zero delta, the
     * entitlement is revoked, and the ledger reason says the seat was returned
     * and not credited. The one refusal left is the completed guard, which is
     * an invariant rather than a policy.
     */
    public function release(
        OrgScope $scope,
        int $revision,
        int $assignmentId,
        string $reason,
        bool $terminate = false,
        string $disposition = AttemptDisposition::SUBMIT_AND_GRADE,
        string $actorType = OrganisationLearningStore::ACTOR_PRINCIPAL,
    ): void {
        $assignment = $this->store->openAssignment($scope, $assignmentId);
        $state = (string) $assignment['state'];
        if (!\in_array($state, SeatAssignmentState::OPEN, true)) {
            throw new ConflictException('This assignment is already closed.');
        }
        $poolId = (int) $assignment['pool_id'];
        $pool = $this->store->pool($scope, $poolId);
        $this->store->requireReconciled($scope, $poolId);
        $reasonKey = SeatEventReason::normalise($reason);
        $pending = $state === SeatAssignmentState::PENDING;
        // The completed guard is asked here and no longer inside the registry.
        // It is a seat invariant and not a policy: taking a seat back from
        // somebody who finished would revoke access to a certificate they
        // earned, and every other seat invariant is enforced in this use case.
        // Asking it in the registry meant a registered provider was never
        // consulted about a finished seat while the registry pretended to be a
        // pure lookup.
        if (!$terminate && SeatAssignmentState::completed($state)) {
            throw new ConflictException('A completed seat cannot be reclaimed.');
        }
        $credit = $pending || $this->policies->allows((string) $pool['reclaim_policy'], SeatReclaimContext::forAssignment(
            $pool,
            $assignment,
            $scope->actorId,
            $this->clock->now(),
            $reasonKey,
        ));
        // A voluntary return and an administrator's withdrawal are two facts
        // and the design gives them two states. The holder is the person the
        // seat is assigned to, so the acting principal being that person is
        // what tells them apart; nothing else in the request can, and a
        // release route that always wrote "reclaimed" is why the distinction
        // had been lost.
        $byHolder = !$terminate && $assignment['user_id'] !== null && (int) $assignment['user_id'] === $scope->actorId;
        $next = $this->closingState($state, $credit, $terminate, $byHolder);
        // A return the policy refused a credit for is a forfeit and carries
        // the reason the published vocabulary gives it, so the ledger says
        // which of the two kinds of forfeit it was: a seat given back that the
        // pool would not take back, or a term that ended.
        if (!$terminate && !$credit && !$pending) {
            $reasonKey = SeatEventReason::RETURNED_BUT_NOT_CREDITED;
        }
        $this->store->update('seat_assignments', $scope, $assignmentId, [
            'state' => $next, 'holder_key' => null, 'closed_at' => $this->store->now(), 'problem_code' => null,
        ]);

        // Before the revoke and not after it. RevokeEntitlement's last-access
        // branch abandons every resumable attempt on the enrolment, so a
        // ceremony that ran afterwards would find nothing open and the
        // learner would lose the grade anyway.
        //
        // And only when this grant is the last one that confers access. The
        // ceremony used to close every open attempt unconditionally, so a
        // learner holding a personal purchase beside their seat lost an
        // attempt at minute forty and kept the access it was closed for. The
        // question is the revoke's own and is asked from its own class.
        $closed = $this->closesAttempts($assignment) ? ($this->attempts?->close(
            (int) $assignment['user_id'],
            (int) $pool['course_id'],
            $disposition,
            $reasonKey,
        ) ?? []) : [];

        if ($assignment['entitlement_id'] !== null) {
            $this->events->stage($this->revoke->executeForOrganisation(
                $scope,
                $this->store->database,
                (int) $assignment['entitlement_id'],
                $reason
            ));
        }
        $eventId = $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            $assignmentId,
            SeatEventType::forClosedState($next),
            $credit && !$pending ? 1 : 0,
            $reasonKey,
            $assignment['user_id'] === null ? null : (int) $assignment['user_id'],
            $assignment['entitlement_id'] === null ? null : (int) $assignment['entitlement_id'],
            [],
            null,
            $actorType,
        );

        // The drill-through is stamped once the ledger row exists, because
        // its id is what the attempt points back at, and both halves are in
        // this transaction: a revocation that committed without closing the
        // attempt is the stranded attempt this ceremony exists to prevent.
        $this->attempts?->record($closed, $reasonKey, $eventId);
    }

    /**
     * One seat at a pool term end, under the pool's expiry disposition.
     *
     * It is a separate entry point from {@see release()} and not a flag on it
     * because the accounting is different in a way that matters: a term end
     * credits nothing back. Canvas Catalog states the commercial rule
     * plainly, that bulk seat sales are final with no refunds for unused
     * seats, so an unclaimed reservation is forfeited and a held seat expires,
     * both at a zero delta. Crediting them back would return quantity that
     * was sold and unbalance the ledger against what the customer bought.
     *
     * No reclaim policy is consulted. The term ending is not a request to
     * take a seat back, it is the seat running out.
     */
    public function expireAtTerm(
        OrgScope $scope,
        int $revision,
        int $assignmentId,
        SeatExpiryOutcome $outcome,
        string $disposition = AttemptDisposition::SUBMIT_AND_GRADE,
    ): void {
        $assignment = $this->store->openAssignment($scope, $assignmentId);
        $state = (string) $assignment['state'];

        if (!\in_array($state, SeatAssignmentState::OPEN, true)) {
            return;
        }

        $to = match ($state) {
            SeatAssignmentState::PENDING => SeatAssignmentState::CANCELLED,
            // Unclaimed at the term end, so the seat is spent rather than
            // returned: the design's own reading of Canvas Catalog's rule.
            SeatAssignmentState::RESERVED => SeatAssignmentState::FORFEITED,
            default => SeatAssignmentState::EXPIRED,
        };
        $this->store->update('seat_assignments', $scope, $assignmentId, [
            'state' => $to, 'holder_key' => null, 'closed_at' => $this->store->now(), 'problem_code' => null,
        ]);

        // Graded before the revoke, for the reason release() gives: the
        // revoke's last-access branch abandons what it finds open. And only
        // when the access really ends: a disposition that keeps access closes
        // nothing, and neither does a term end for a learner whose personal
        // grant on the same course is still effective.
        $closed = $outcome->lapsesAccess && $this->closesAttempts($assignment) ? ($this->attempts?->close(
            (int) $assignment['user_id'],
            (int) $this->store->pool($scope, (int) $assignment['pool_id'])['course_id'],
            \in_array($disposition, AttemptDisposition::DISPOSITIONS, true)
                ? $disposition : AttemptDisposition::SUBMIT_AND_GRADE,
            SeatEventReason::EXPIRED_WITH_TERM,
        ) ?? []) : [];

        if ($outcome->lapsesAccess && $assignment['entitlement_id'] !== null) {
            $this->events->stage($this->revoke->executeForOrganisation(
                $scope,
                $this->store->database,
                (int) $assignment['entitlement_id'],
                SeatEventReason::EXPIRED_WITH_TERM
            ));
        }

        $eventId = $this->store->ledger(
            $scope,
            $revision,
            (int) $assignment['pool_id'],
            $assignmentId,
            SeatEventType::forClosedState($to),
            0,
            SeatEventReason::EXPIRED_WITH_TERM,
            $assignment['user_id'] === null ? null : (int) $assignment['user_id'],
            $assignment['entitlement_id'] === null ? null : (int) $assignment['entitlement_id'],
            [],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM
        );

        $this->attempts?->record($closed, SeatEventReason::EXPIRED_WITH_TERM, $eventId);
    }

    /**
     * Whether closing this assignment actually ends the holder's access to
     * the course, and therefore whether the attempt ceremony runs.
     *
     * Three ways it does not, and each was a real defect. The row has no
     * account behind it, which is every unclaimed reservation. The seat
     * granted no entitlement, so revoking it takes nothing away. Or the
     * learner holds another grant on the same course that is still effective,
     * which is the case RevokeEntitlement has always checked and this ceremony
     * did not: it burned an attempt and locked in a partial grade for somebody
     * who never lost access.
     *
     * @param array<string, mixed> $assignment
     */
    private function closesAttempts(array $assignment): bool
    {
        if ($assignment['user_id'] === null || $assignment['entitlement_id'] === null) {
            return false;
        }

        $entitlementId = (int) $assignment['entitlement_id'];
        $row = $this->store->database->selectOne(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('entitlements')
            ->where('id', '=', $entitlementId));

        if ($row === null) {
            return false;
        }

        return $this->revoke->isLastEffectiveGrant($entitlementId, (int) $row['user_id'], (int) $row['course_id']);
    }

    /**
     * Which closed state an assignment lands in.
     *
     * A pending row never held a seat, so withdrawing it is a cancellation
     * and not a return. A completed seat taken by a forced termination is
     * expired rather than reclaimed, because forced termination is not a
     * refund of earned completion. Everything else is the policy's answer:
     * credited back is a return, refused a credit is a forfeit, and a return
     * the holder made themselves is released rather than reclaimed.
     */
    private function closingState(string $state, bool $credit, bool $terminate, bool $byHolder): string
    {
        if ($state === SeatAssignmentState::PENDING) {
            return SeatAssignmentState::CANCELLED;
        }

        if ($terminate && SeatAssignmentState::completed($state)) {
            return SeatAssignmentState::EXPIRED;
        }

        if (!$credit) {
            return SeatAssignmentState::FORFEITED;
        }

        return $byHolder ? SeatAssignmentState::RELEASED : SeatAssignmentState::RECLAIMED;
    }
}
