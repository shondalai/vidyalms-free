<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an `OrgMembershipTermination` policy answers about.
 *
 * One membership leaving the active state: the organisation, the membership
 * row, the person, the state it is moving to and the state it came from.
 *
 * The constructor is private and the factory is named. A provider receives one
 * of these and can forge neither the organisation nor the membership, and it
 * carries no database handle, so a policy answers a question and never widens
 * its own reach.
 */
final class OrgMembershipTerminationContext
{
    private function __construct(
        public readonly int $organisationId,
        public readonly int $membershipId,
        public readonly int $subjectUserId,
        public readonly string $fromState,
        public readonly string $toState,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    public static function of(
        int $organisationId,
        int $membershipId,
        int $subjectUserId,
        string $fromState,
        string $toState,
        \DateTimeImmutable $now,
    ): self {
        return new self($organisationId, $membershipId, $subjectUserId, $fromState, $toState, $now);
    }
}
