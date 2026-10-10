<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentResumed;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The single writer of enrolment rows for both the admin-grant and the
 * self-enrol paths. Callers have already authorized the operation and
 * verified that the entitlement covers the course.
 *
 * Idempotent per (user, course): a missing row is created and LearnerEnrolled
 * emitted; a suspended or cancelled row resumes under the new entitlement and
 * EnrolmentResumed is emitted; an active or completed row returns unchanged
 * with no event.
 */
final class EnrolLearner
{
    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        /**
         * Optional so a harness with no notification engine still builds this
         * use case; absent, the enrolment is written and dispatched exactly
         * as before, with the listener as the only enqueue path.
         */
        private readonly ?TransactionalOutbox $outbox = null,
    ) {
    }

    public function execute(int $userId, int $courseId, int $entitlementId): Enrolment
    {
        $now = $this->clock->now();
        $existing = $this->enrolments->findByUserAndCourse($userId, $courseId);

        if ($existing !== null && ($existing->isActive() || $existing->isCompleted())) {
            return $existing;
        }

        // The enrolment row and its outbox intent commit or abort together.
        // Written through the listener alone, an enqueue that threw left the
        // enrolment committed and the mail gone, with nothing anywhere
        // recording that it had been promised.
        return $this->write(function () use ($existing, $now, $userId, $courseId, $entitlementId): array {
            if ($existing === null) {
                $enrolment = $this->enrolments->add(Enrolment::start($userId, $courseId, $entitlementId, $now));

                return [
                    $enrolment,
                    [new LearnerEnrolled($now, $enrolment->id(), $userId, $courseId, $entitlementId)],
                ];
            }

            $this->enrolments->reactivate($existing->id(), $entitlementId);
            $resumed = $existing->resumedWith($entitlementId);

            return [$resumed, [new EnrolmentResumed($now, $resumed->id(), $userId, $courseId)]];
        });
    }

    /**
     * The same writer within a caller-owned aggregate transaction. The caller
     * stages these events in its transactional outbox and dispatches them only
     * after its OUTERMOST transaction commits. Calling execute() inside a seat
     * transaction would publish events after a nested savepoint, too early.
     *
     * @return array{Enrolment, list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>}
     */
    public function executeDeferred(int $userId, int $courseId, int $entitlementId): array
    {
        $now = $this->clock->now();
        $existing = $this->enrolments->findByUserAndCourse($userId, $courseId);
        if ($existing !== null && ($existing->isActive() || $existing->isCompleted())) {
            return [$existing, []];
        }
        if ($existing === null) {
            $enrolment = $this->enrolments->add(Enrolment::start($userId, $courseId, $entitlementId, $now));

            return [$enrolment, [new LearnerEnrolled($now, $enrolment->id(), $userId, $courseId, $entitlementId)]];
        }
        $this->enrolments->reactivate($existing->id(), $entitlementId);

        return [$existing->resumedWith($entitlementId), [new EnrolmentResumed($now, $existing->id(), $userId, $courseId)]];
    }

    /**
     * @param callable(): array{0: Enrolment, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function write(callable $operation): Enrolment
    {
        if ($this->outbox !== null) {
            return $this->outbox->commit($operation);
        }

        [$enrolment, $events] = $operation();
        $this->events->dispatchAll($events);

        return $enrolment;
    }
}
