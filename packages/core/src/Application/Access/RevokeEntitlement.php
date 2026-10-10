<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningLock;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Events\EntitlementRevoked;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Revokes one entitlement. When no other active entitlement covers the course
 * for that user and the enrolment is active, the enrolment is suspended, not
 * deleted: progress survives a revocation.
 *
 * Idempotent: revoking an already revoked entitlement changes nothing and
 * emits nothing.
 */
final class RevokeEntitlement
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
     * @param ?string $ref the external key this revocation is about, so a
     *                     dispute that is later won knows what to reinstate.
     *                     Additive and trailing: every shipped caller is
     *                     unchanged, and a null leaves whatever is stored
     *                     alone rather than erasing it.
     */
    public function execute(
        Principal $actor,
        int $entitlementId,
        string $reason = 'revoked',
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
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException(
                'Manage organisation access through its organisation grant or seat assignment.'
            );
        }

        if ($entitlement->status === Entitlement::STATUS_REVOKED) {
            return $entitlement;
        }

        // The reason and the external key are stored beside the status now.
        // The suspension panel, the admin list and the refunds report all
        // read them, and before this they had no writer at all: the reason
        // reached the domain event and nothing else, which is a delivery
        // rather than a record.
        $this->entitlements->updateStatus($entitlementId, Entitlement::STATUS_REVOKED, $reason, $ref);
        $revoked = $entitlement->withStatus(Entitlement::STATUS_REVOKED, $reason, $ref);

        $now = $this->clock->now();
        $this->events->dispatch(new EntitlementRevoked(
            $now,
            $entitlementId,
            $entitlement->userId,
            $entitlement->courseId,
            $reason,
        ));

        foreach ($this->entitlements->activeForUserAndCourse($entitlement->userId, $entitlement->courseId) as $other) {
            // Only an entitlement that confers access RIGHT NOW keeps the
            // enrolment active; a future-dated or lapsed one does not cover.
            if ($other->isEffectiveAt($now)) {
                return $revoked;
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

        return $revoked;
    }

    /**
     * Whether revoking this grant would actually end the learner's access.
     *
     * The revoke itself has asked this question since it was written, at the
     * line that returns early when another grant is still effective. The seat
     * revocation ceremony did not: it force-submitted and graded every open
     * attempt before the revoke, whatever else the learner held, so a learner
     * with a personal purchase beside their seat lost an attempt at minute
     * forty and kept the access it was closed for. This is that same question,
     * asked from one place, so the two cannot drift apart.
     *
     * The grant being revoked is excluded by id rather than by status, because
     * the ceremony runs BEFORE the revoke: at the moment it asks, the row it
     * is about to revoke is still active and would otherwise answer its own
     * question.
     */
    public function isLastEffectiveGrant(int $entitlementId, int $userId, int $courseId): bool
    {
        $now = $this->clock->now();

        foreach ($this->entitlements->activeForUserAndCourse($userId, $courseId) as $other) {
            if ($other->id() !== $entitlementId && $other->isEffectiveAt($now)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Already-authorised org lifecycle path; scope is repeated in the lookup
     * and update. The caller commits the returned events through its outbox.
     * Other organisations' or personal grants remain independent access.
     *
     * @return list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>
     */
    public function executeForOrganisation(OrgScope $scope, DatabasePort $database, int $id, string $reason): array
    {
        $row = $database->selectOne(Query::from('entitlements')->where('id', '=', $id)
            ->where('organisation_id', '=', $scope->organisationId));
        $entitlement = $row === null ? null : $this->entitlements->find($id);
        if ($entitlement === null) {
            throw new EntityNotFoundException('No organisation entitlement here.');
        }
        if ($entitlement->status === Entitlement::STATUS_REVOKED) {
            return [];
        }
        OrganisationLearningLock::claim(
            $database,
            $entitlement->userId,
            $entitlement->courseId,
            $this->clock->now()->format('Y-m-d H:i:s')
        );
        $now = $this->clock->now();
        $database->update('entitlements', [
            'status' => Entitlement::STATUS_REVOKED, 'revoked_reason' => $reason,
            'revoked_ref' => 'org' . $scope->organisationId . ':entitlement-' . $id,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], ['id' => $id, 'organisation_id' => $scope->organisationId]);
        $events = [new EntitlementRevoked($now, $id, $entitlement->userId, $entitlement->courseId, $reason)];
        foreach ($this->entitlements->activeForUserAndCourse($entitlement->userId, $entitlement->courseId) as $other) {
            if ($other->isEffectiveAt($now)) {
                return $events;
            }
        }
        $enrolment = $this->enrolments->findByUserAndCourse($entitlement->userId, $entitlement->courseId);
        if ($enrolment !== null && $enrolment->isActive()) {
            $this->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_SUSPENDED);
            $events[] = new EnrolmentSuspended($now, $enrolment->id(), $entitlement->userId, $entitlement->courseId);
        }
        if ($enrolment !== null) {
            // Last-access loss closes resumable attempts, but never grades or
            // deletes their answers. Other grants returned above preserve them.
            $database->update('attempts', ['status' => 'abandoned', 'submitted_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s')], ['enrolment_id' => $enrolment->id(), 'status' => 'in_progress']);
        }

        return $events;
    }
}
