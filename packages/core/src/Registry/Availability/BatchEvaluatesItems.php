<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * One learner, many items. Default when absent: per-call evaluation.
 *
 * This is the direction a sixty-item outline needs, and it is why phase two
 * of the composer issues exactly one call per batching provider rather than
 * one per leaf. It exists from the first release because retrofitting it
 * changes every provider signature, and the two batch directions are
 * genuinely different: a sixty-item outline for one learner, and a
 * five-hundred-learner roster for one item.
 */
interface BatchEvaluatesItems
{
    /**
     * The keys of the returned map are the caller's own leaf keys, taken
     * unchanged from the request, so a provider never has to invent an
     * identity for a leaf it did not name.
     *
     * @param array<string, ConditionEvaluationRequest> $requests
     *
     * @return array<string, AvailabilityOutcome>
     */
    public function evaluateMany(array $requests, AvailabilityContext $context): array;
}
