<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * One item, many learners. Default when absent: per-learner evaluation, and
 * the provider may never return NEVER.
 *
 * This interface is load-bearing twice. It is the roster-direction batch
 * answer, and it is the mechanical test for which providers may exclude an
 * item from the completion denominator: a NEVER returned by a provider that
 * does not implement it is downgraded to BLOCKED and logged with the provider
 * key, because a silently corrupted denominator is the one failure in this
 * design that is invisible until credentials are wrong.
 */
interface FiltersLearners
{
    /**
     * @param array<string, mixed> $payload
     * @param list<int>            $userIds
     *
     * @return list<int> the subset that satisfies the leaf
     */
    public function filterLearners(array $payload, bool $negated, array $userIds, AvailabilityContext $context): array;
}
