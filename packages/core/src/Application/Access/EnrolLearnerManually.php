<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;

/**
 * Enrolling a learner by hand: a manual entitlement and the enrolment it
 * produced, in one answer. The rules, the authorization and the idempotence
 * all live in GrantEntitlement; this exists so the route answers with the
 * enrolment beside the entitlement rather than making the caller ask again.
 */
final class EnrolLearnerManually
{
    public function __construct(
        private readonly GrantEntitlement $grantEntitlement,
        private readonly AccessGrantView $view,
    ) {
    }

    public function execute(
        Principal $actor,
        int $courseId,
        int $userId,
        ?string $startsAt = null,
        ?string $expiresAt = null,
    ): AccessGrant {
        return $this->view->of($this->grantEntitlement->execute(
            $actor,
            $userId,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            $startsAt,
            $expiresAt,
        ));
    }
}
