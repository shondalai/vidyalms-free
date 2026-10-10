<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRecord;

/**
 * One roster row: the enrolment with its entitlement and course, and the
 * learner's display name as the users port knows it. The name is null when
 * the CMS account is gone; the enrolment and its progress are not.
 *
 * There is no email here. Nothing on the management surface emits one, and a
 * field that is carried and never emitted is not free: this one kept a per
 * row lookup into the host user table alive for years of pages that threw
 * the answer away.
 */
final class LearnerEnrolment
{
    public function __construct(
        public readonly EnrolmentRecord $record,
        public readonly ?string $learnerName,
    ) {
    }
}
