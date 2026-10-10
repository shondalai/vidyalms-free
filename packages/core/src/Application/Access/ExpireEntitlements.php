<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The scheduler sweep over entitlement time windows. Two passes: every
 * active entitlement whose expires_at has passed flips to expired, and each
 * enrolment that loses its last effective entitlement is suspended; every
 * future-provisioned entitlement whose starts_at has arrived enrols its
 * learner. Progress rows are never touched. Running the sweep twice changes
 * nothing on the second pass.
 *
 * The expiry write is a compare-and-swap against the row the read returned,
 * because the read and the write are not one statement: an admin who extends
 * or revokes an entitlement while the sweep is between them keeps their
 * write, and the sweep counts and suspends only what it actually expired.
 */
final class ExpireEntitlements
{
    /**
     * Where the sweep records that it ran. The dashboard reads it to answer
     * "is expiry actually running on this site?", which no count of expired
     * rows can answer: a sweep that finds nothing to do looks exactly like a
     * sweep that never ran.
     */
    public const LAST_RUN_SETTING = 'expiry_sweep.last_run_at';

    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolLearner $enrolLearner,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return array{expired: int, suspended: int, activated: int}
     */
    public function execute(): array
    {
        $now = $this->clock->now();
        $due = $this->entitlements->activeExpiringBy($now);

        /** @var array<string, array{0: int, 1: int}> $affected */
        $affected = [];
        $expired = 0;

        foreach ($due as $entitlement) {
            // Guarded: a row an admin extended or revoked between the read
            // above and this write is left exactly as they left it, and is
            // not carried into the suspension pass either.
            if (!$this->entitlements->expireIfStillDue($entitlement, $now)) {
                continue;
            }

            $expired++;
            $affected[$entitlement->userId . ':' . $entitlement->courseId] = [$entitlement->userId, $entitlement->courseId];
        }

        $suspended = 0;

        foreach ($affected as [$userId, $courseId]) {
            if ($this->hasEffectiveEntitlement($userId, $courseId, $now)) {
                continue;
            }

            $enrolment = $this->enrolments->findByUserAndCourse($userId, $courseId);

            if ($enrolment === null || !$enrolment->isActive()) {
                continue;
            }

            $this->enrolments->updateStatus($enrolment->id(), Enrolment::STATUS_SUSPENDED);
            $this->events->dispatch(new EnrolmentSuspended($now, $enrolment->id(), $userId, $courseId));
            $suspended++;
        }

        // Activation pass: EnrolLearner creates, resumes or no-ops per
        // enrolment state, so re-sweeping an already-enrolled schedule row
        // changes nothing.
        $activated = 0;

        foreach ($this->entitlements->activeScheduledBy($now) as $entitlement) {
            if ($entitlement->isExpiredAt($now)) {
                continue;
            }

            $enrolment = $this->enrolments->findByUserAndCourse($entitlement->userId, $entitlement->courseId);

            if ($enrolment !== null && ($enrolment->isActive() || $enrolment->isCompleted())) {
                continue;
            }

            $this->enrolLearner->execute($entitlement->userId, $entitlement->courseId, $entitlement->id());
            $activated++;
        }

        $this->settings->set(self::LAST_RUN_SETTING, $now->format('Y-m-d H:i:s'));

        return ['expired' => $expired, 'suspended' => $suspended, 'activated' => $activated];
    }

    private function hasEffectiveEntitlement(int $userId, int $courseId, \DateTimeImmutable $moment): bool
    {
        foreach ($this->entitlements->activeForUserAndCourse($userId, $courseId) as $entitlement) {
            if ($entitlement->isEffectiveAt($moment)) {
                return true;
            }
        }

        return false;
    }
}
