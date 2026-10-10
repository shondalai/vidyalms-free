<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;

/**
 * An entitlement and the enrolment it produced, which is what every staff
 * action on access answers: the reason, and what it did to the learner's
 * place in the course. The enrolment is null when a future start has
 * provisioned access that has not begun.
 */
final class AccessGrant
{
    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly ?Enrolment $enrolment,
    ) {
    }
}
