<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\EnrolmentRule;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Decides whether a caller may self-enrol in a course right now. The free
 * build ships "open" (free courses) and "manual" (admin enrols); Pro adds
 * cohort schedules and more. A rule never creates the enrolment; it only
 * answers, and the EnrolLearner use case acts.
 */
interface EnrolmentRuleInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * @param array<string, mixed> $course the course row this rule inspects
     */
    public function allowsSelfEnrolment(Principal $principal, array $course): bool;
}
