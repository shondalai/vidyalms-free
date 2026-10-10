<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The instant this condition will open. Default when absent: the condition
 * never schedules an unlock notification.
 *
 * A condition that can name the instant feeds the learner line, the unlock
 * task, the blocked-learners report and the "what unlocks next" widget from
 * one evaluation, which is why there is no drip calculator in this codebase.
 * One that cannot returns null and every surface degrades to "not available
 * yet", which is honest.
 */
interface NamesOpensAt
{
    /**
     * @param array<string, mixed> $payload
     */
    public function earliestOpensAt(array $payload, bool $negated, AvailabilityContext $context): ?\DateTimeImmutable;
}
