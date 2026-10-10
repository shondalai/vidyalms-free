<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an `OrgUnitMove` policy answers about.
 *
 * One member moving between two units of one organisation, with the unit they
 * came from and the unit they are going to, either of which may be absent
 * because a member may hold no unit at all.
 *
 * The constructor is private and the factory is named, and it carries no
 * database handle: a policy says what travels and never moves anything itself.
 */
final class OrgUnitMoveContext
{
    private function __construct(
        public readonly int $organisationId,
        public readonly int $membershipId,
        public readonly int $subjectUserId,
        public readonly ?int $fromUnitId,
        public readonly ?int $toUnitId,
        /**
         * The unit-scoped role the member holds today, or null.
         *
         * Named on the context because it is the one thing a move can lose,
         * and a policy asked to decide without it would be deciding blind.
         */
        public readonly ?string $unitScopedRoleKey,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    public static function of(
        int $organisationId,
        int $membershipId,
        int $subjectUserId,
        ?int $fromUnitId,
        ?int $toUnitId,
        ?string $unitScopedRoleKey,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $organisationId,
            $membershipId,
            $subjectUserId,
            $fromUnitId,
            $toUnitId,
            $unitScopedRoleKey,
            $now,
        );
    }
}
