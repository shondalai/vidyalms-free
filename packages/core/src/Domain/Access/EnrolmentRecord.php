<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

/**
 * One enrolment with the entitlement it rides on and the course it belongs
 * to. The two travel together everywhere staff look at a roster, because
 * "suspended" without "the entitlement expired on the 3rd" is a state
 * without a reason.
 */
final class EnrolmentRecord
{
    public function __construct(
        public readonly Enrolment $enrolment,
        public readonly Entitlement $entitlement,
        public readonly string $courseTitle,
        public readonly string $courseSlug,
    ) {
    }
}
