<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\EnrolmentRule;

use Shondalai\VidyaLMS\Core\Registry\Context\EnrolmentContext;

/**
 * The context-object form of the self-enrolment question. Default when
 * absent: the existing allowsSelfEnrolment(Principal, array), read as an
 * allow or a refusal with no reason.
 *
 * A capability interface rather than a widened method, because
 * EnrolmentRuleInterface is shipped and third parties are invited to
 * implement it. The decision object is what lets a rule say why it refused,
 * which is what the enrol button needs in order to say anything but "no".
 */
interface ContextAwareEnrolmentRule
{
    public function allowsSelfEnrolmentIn(EnrolmentContext $context): EnrolmentDecision;
}
