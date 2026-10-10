<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * Bridges the Progress context's terminal event into issuance.
 *
 * Subscribed to the EventBus at boot; issuance absorbs replays, so this stays
 * a plain delegation.
 *
 * It requires an active entitlement at issue. Completion is terminal and the
 * event cannot re-fire, so a completion recorded against an enrolment whose
 * entitlement has since lapsed would otherwise mint a credential the site no
 * longer had a basis to award: expiry suspends an enrolment and never deletes
 * progress, so the completion survives the entitlement by design and the
 * check has to be here rather than upstream. The reconciliation sweep finds
 * an enrolment whose entitlement is restored later and issues then.
 */
final class IssueCertificateOnCourseCompletion
{
    public function __construct(
        private readonly IssueCertificate $issueCertificate,
        /**
         * Optional so a harness with no access context still builds the
         * listener; absent, every completion issues, which is what happened
         * before the check existed.
         */
        private readonly ?EntitlementRepository $entitlements = null,
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        if (!$event instanceof CourseCompleted) {
            return;
        }

        if (!$this->isEntitled($event->userId, $event->courseId)) {
            return;
        }

        $this->issueCertificate->executeOutcome(
            $event->enrolmentId,
            $event->userId,
            $event->courseId,
            // The completion date is a real column populated from this event.
            // The public card labels its date "Completed" while the only
            // stored date used to be the issue date, and a manual reissue
            // makes the two diverge: presenting one fact under another's
            // label on an evidentiary page is not defensible.
            $event->occurredAt,
        );
    }

    private function isEntitled(int $userId, int $courseId): bool
    {
        return $this->entitlements === null
            || $this->entitlements->activeForUserAndCourse($userId, $courseId) !== [];
    }
}
