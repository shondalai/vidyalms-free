<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Progress reaches the seat model through a listener, never a query.
 *
 * This is the half of the seat aggregate that was missing. `consumed` and
 * `completed` had no writer, so the reclaim policy could not read the
 * assignment's state and read the learner's enrolment row instead: seat
 * accounting became a second reader of the progress schema, and whether an
 * administrator could take a seat back depended on how progress happened to
 * be stored.
 *
 * With the two transitions written here, {@see SeatReclaimContext} is built
 * from the assignment and the seat aggregate is closed. The direction matters
 * as much as the fact: the seat model listens to learning, and learning knows
 * nothing about seats.
 *
 * **Every write is idempotent and every write is guarded by the state it
 * moves from.** A re-fired event finds the assignment already `consumed` and
 * does nothing, and the ledger row carries an idempotency key naming the
 * assignment and the transition, so even a replay across two processes is one
 * row. That matters because these events fire on the learner's own request
 * path and the event bus logs a failing listener rather than failing the
 * request: the worst case is a missing seat event, never a broken heartbeat.
 */
final class SeatProgressListener
{
    public function __construct(
        private readonly OrganisationLearningStore $store,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationSweepAuthority $authority,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        if ($event instanceof CourseCompleted) {
            $this->move(
                $event->userId,
                $event->courseId,
                [SeatAssignmentState::ASSIGNED, SeatAssignmentState::CONSUMED],
                SeatAssignmentState::COMPLETED,
                SeatEventType::SEAT_COMPLETED,
                'completed_at',
            );

            return;
        }

        if ($event instanceof LessonCompleted || $event instanceof AttemptSubmitted || $event instanceof SubmissionReceived) {
            $this->move(
                $event->userId,
                $event->courseId,
                [SeatAssignmentState::ASSIGNED],
                SeatAssignmentState::CONSUMED,
                SeatEventType::SEAT_STARTED,
                'started_at',
            );
        }
    }

    /**
     * Moves the learner's seat on this course, if they hold one.
     *
     * The tenant comes from the assignment row through the sweep authority,
     * which is the same witness a sweep uses and for the same reason: a
     * listener has no caller, and a synthetic principal would be a hole in
     * the access spine. The scope it mints belongs to the administrator who
     * spent the seat, so the ledger row names a real person.
     *
     * @param list<string> $from
     */
    private function move(int $userId, int $courseId, array $from, string $to, string $event, string $stamp): void
    {
        $row = $this->store->assignmentOnCourse($userId, $courseId, $from);

        if ($row === null) {
            return;
        }

        $assignmentId = (int) $row['id'];
        $scope = $this->authority->forAssignment($assignmentId);

        if ($scope === null) {
            // The administrator who spent the seat is gone. The sweep rule
            // applies unchanged: a seat event attributed to somebody who did
            // not authorise it is worse than a missing one, and the seat
            // still holds its capacity either way.
            return;
        }

        // Re-read under the tenant predicate, so the row this writes to is
        // one the scoped reader agreed the scope reaches rather than one a
        // join happened to find.
        $scoped = $this->store->database->selectOne(OrgScopedReader::from($scope, 'seat_assignments')
            ->where('id', '=', $assignmentId)->whereIn('state', $from));

        if ($scoped === null) {
            return;
        }

        $next = $this->organisations->claim($scope, (int) $this->organisations->organisation($scope)['revision']);
        $this->store->update('seat_assignments', $scope, $assignmentId, [
            'state' => $to, $stamp => $this->store->now(),
        ]);
        $this->store->ledger(
            $scope,
            $next,
            (int) $scoped['pool_id'],
            $assignmentId,
            $event,
            // Both are zero-delta: the seat was spent when it was assigned
            // and starting or finishing the course does not spend it again.
            0,
            SeatEventReason::GRANTED_BY_ADMIN,
            $userId,
            $scoped['entitlement_id'] === null ? null : (int) $scoped['entitlement_id'],
            [],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            $event . ':' . $assignmentId,
        );
    }
}
