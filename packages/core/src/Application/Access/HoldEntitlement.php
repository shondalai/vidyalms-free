<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Suspends access without ending the grant.
 *
 * A dispute is a hold rather than a revocation: the money is frozen and may
 * come back, so the entitlement has to stop conferring access **and** the
 * enrolment has to stop being active, while the row itself stays available
 * for a later reinstatement through ExtendEntitlement. A dunning terminal is
 * the same shape.
 *
 * **This use case exists because neither shipped path could express it.**
 * ExtendEntitlement has no suspension branch, so extending an entitlement to
 * "now" flipped its status and left the enrolment, and therefore the content,
 * fully available. ExpireEntitlements only sweeps rows still marked active,
 * so it never revisits one. The result was that a dispute hold and a
 * paused-access state both failed open, silently, on a path where the seller
 * is holding money that is not theirs.
 *
 * The status it writes is `expired` and not a fourth word. The entitlement
 * enumeration stays three values by design: a hold is an entitlement that has
 * stopped conferring access, the reason says which kind of stop it was, and a
 * fourth status would put a second access-state machine beside the enrolment's
 * own.
 *
 * It runs the identical last-effective-entitlement check RevokeEntitlement
 * performs: another active grant covering the same course keeps the learner
 * enrolled, because a hold on one entitlement is not a hold on a person.
 */
final class HoldEntitlement
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolmentRepository $enrolments,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @param string  $reason why access stopped, for the suspension read model
     * @param ?string $ref    the external key the hold is about, so the thing
     *                        that lifts it knows what it is lifting
     */
    public function execute(
        Principal $actor,
        int $entitlementId,
        string $reason,
        ?string $ref = null,
    ): Entitlement {
        $this->access->requireSignedIn($actor);

        $entitlement = $this->entitlements->find($entitlementId);

        if ($entitlement === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Entitlement', $entitlementId);
        }

        $this->access->require($actor, $entitlement->courseId, Capability::ENROL_OTHERS);

        if ($entitlement->organisationId !== null) {
            throw new ConflictException(
                'Manage organisation access through its organisation grant or seat assignment.',
            );
        }

        if ($entitlement->status !== Entitlement::STATUS_ACTIVE) {
            // Already not conferring access. Idempotent for the same reason
            // revoking twice is: a webhook redelivery must not produce a
            // second suspension event.
            return $entitlement;
        }

        $this->entitlements->updateStatus($entitlementId, Entitlement::STATUS_EXPIRED, $reason, $ref);
        $held = $entitlement->withStatus(Entitlement::STATUS_EXPIRED, $reason, $ref);

        $now = $this->clock->now();

        foreach ($this->entitlements->activeForUserAndCourse($entitlement->userId, $entitlement->courseId) as $other) {
            // Only an entitlement that confers access right now keeps the
            // enrolment active; a future-dated or lapsed one does not cover.
            if ($other->isEffectiveAt($now)) {
                return $held;
            }
        }

        $enrolment = $this->enrolments->findByUserAndCourse($entitlement->userId, $entitlement->courseId);

        if ($enrolment !== null && $enrolment->isActive()) {
            $this->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_SUSPENDED);
            $this->events->dispatch(new EnrolmentSuspended(
                $now,
                $enrolment->id(),
                $entitlement->userId,
                $entitlement->courseId,
            ));
        }

        return $held;
    }
}
