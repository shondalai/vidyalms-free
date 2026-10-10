<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an `OrgVisibility` policy answers about.
 *
 * One organisation, one reader, and the rights that reader holds, which is
 * everything a policy needs to say what an organisation-scoped screen may show
 * about the people in it and nothing it could widen its own reach with.
 *
 * The constructor is private and the factory is named, like every other
 * context in this design: a provider receives one of these and can forge
 * neither the organisation it names nor the reader it describes.
 */
final class OrgVisibilityContext
{
    private function __construct(
        public readonly int $organisationId,
        public readonly int $readerUserId,
        /**
         * The roles the reader holds inside this organisation.
         *
         * A policy may quite properly disclose more to an owner than to a
         * group leader, and the roles are what the scope carries; resolving
         * them into action words is the role catalogue's job and not every
         * policy's.
         *
         * @var list<string>
         */
        public readonly array $roles,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    /** @param list<string> $roles */
    public static function of(
        int $organisationId,
        int $readerUserId,
        array $roles,
        \DateTimeImmutable $now,
    ): self {
        return new self($organisationId, $readerUserId, array_values($roles), $now);
    }
}
