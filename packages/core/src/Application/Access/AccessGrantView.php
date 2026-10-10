<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;

/**
 * Reads back the enrolment an entitlement decision left behind. Called after
 * a use case that already authorized the write, so it carries no rule of its
 * own.
 */
final class AccessGrantView
{
    public function __construct(
        private readonly EnrolmentRepository $enrolments,
    ) {
    }

    public function of(Entitlement $entitlement): AccessGrant
    {
        return new AccessGrant(
            $entitlement,
            $this->enrolments->findByUserAndCourse($entitlement->userId, $entitlement->courseId),
        );
    }
}
