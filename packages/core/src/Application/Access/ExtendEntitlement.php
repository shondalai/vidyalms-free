<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Gives an entitlement a new expiry, null meaning never.
 *
 * This is the promise expiry makes good on: suspension never deleted the
 * progress, so an extension resumes the learner exactly where they stopped.
 * When the entitlement is effective again and the enrolment is suspended, it
 * resumes through the same EnrolLearner path every other grant uses, which is
 * what emits EnrolmentResumed; the progress rows are not touched by any of it.
 *
 * A revoked entitlement is refused: revocation was a decision, and undoing it
 * quietly by moving a date would hide that. Grant a new entitlement instead.
 */
final class ExtendEntitlement
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly CourseAccess $access,
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolLearner $enrolLearner,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(Principal $actor, int $entitlementId, ?string $expiresAt): AccessGrant
    {
        $this->access->requireSignedIn($actor);

        $entitlement = $this->entitlements->find($entitlementId);

        if ($entitlement === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Entitlement', $entitlementId);
        }

        $this->access->require($actor, $entitlement->courseId, Capability::ENROL_OTHERS);
        if ($entitlement->organisationId !== null) {
            throw new ConflictException('Manage organisation access through its organisation grant or seat assignment.');
        }

        if ($entitlement->status === Entitlement::STATUS_REVOKED) {
            throw new ConflictException('A revoked entitlement cannot be extended; grant a new one instead.');
        }

        $parsed = $this->parse($expiresAt);

        if ($parsed !== null && $entitlement->startsAt !== null && $parsed <= $entitlement->startsAt) {
            throw ValidationException::forField('expires_at', 'The expiry must be after the start.');
        }

        $now = $this->clock->now();
        $status = $parsed !== null && $parsed <= $now
            ? Entitlement::STATUS_EXPIRED
            : Entitlement::STATUS_ACTIVE;

        $this->entitlements->updateExpiry($entitlementId, $parsed, $status);

        $extended = $this->entitlements->find($entitlementId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Entitlement', $entitlementId);

        $enrolment = $this->enrolments->findByUserAndCourse($entitlement->userId, $entitlement->courseId);

        if ($enrolment !== null && !$enrolment->isActive() && !$enrolment->isCompleted() && $extended->isEffectiveAt($now)) {
            $enrolment = $this->enrolLearner->execute(
                $entitlement->userId,
                $entitlement->courseId,
                $entitlementId,
            );
        }

        return new AccessGrant($extended, $enrolment);
    }

    private function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, new \DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format(self::DATETIME_FORMAT) !== $value) {
            throw ValidationException::forField('expires_at', 'Expected a UTC datetime in "Y-m-d H:i:s" format.');
        }

        return $parsed;
    }
}
