<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * Enrols the learner a grant deliberately did not enrol.
 *
 * The companion to GrantEntitlement's trailing $activate flag: it enrols a
 * learner whose grant is **active** and whose enrolment was deliberately not
 * created. It is not what lifts a hold, and this sentence used to say it was,
 * two paragraphs above the code that refuses exactly that case. HoldEntitlement
 * writes the expired status, and the door back from there is ExtendEntitlement,
 * which the comment on the refusal below already says.
 *
 * It exists because the consumer withdrawal
 * regime requires exactly this shape: Directive 2011/83/EU Article 16(m) lets
 * a consumer lose the right to withdraw once performance of a digital
 * service has begun with their prior express consent and acknowledgement, so
 * a seller who has not captured that acknowledgement must be able to take the
 * money and hold the access rather than either refusing the sale or starting
 * performance without consent.
 *
 * A future-dated start is not the same thing and is not a substitute: the
 * expiry sweep's activation pass enrols the learner the moment starts_at
 * arrives, waiver or no waiver, so a hold expressed as a date lifts itself.
 *
 * It is idempotent. EnrolLearner emits nothing when the enrolment is already
 * active, so a waiver captured twice enrols once.
 */
final class ActivateEntitlement
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolLearner $enrolLearner,
        private readonly \Shondalai\VidyaLMS\Core\Port\Clock\ClockPort $clock,
    ) {
    }

    public function execute(Principal $actor, int $entitlementId): Entitlement
    {
        $this->access->requireSignedIn($actor);

        $entitlement = $this->entitlements->find($entitlementId);

        if ($entitlement === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Entitlement', $entitlementId);
        }

        $this->access->require($actor, $entitlement->courseId, Capability::ENROL_OTHERS);

        if (!$entitlement->isActive()) {
            // A revoked or held grant is not activated back into life here.
            // Bringing one back is ExtendEntitlement, which writes a new
            // expiry and the status that goes with it, and the two are
            // deliberately different doors.
            throw new ConflictException('Only an active entitlement can be activated; extend or grant a new one.');
        }

        if (!$entitlement->hasStartedBy($this->clock->now())) {
            throw new ConflictException('This entitlement has not started yet; the sweep enrols it when it does.');
        }

        $this->enrolLearner->execute($entitlement->userId, $entitlement->courseId, $entitlement->id());

        return $entitlement;
    }
}
