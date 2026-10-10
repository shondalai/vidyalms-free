<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an identity mapper is handed, and the boundary nothing protocol-shaped
 * crosses.
 *
 * A context object with a private constructor and named factories, on the
 * standing terms. Two factories because there are exactly two shapes a host
 * can supply, and the design says so rather than pretending they are one: a
 * plain list of host groups, which is all one CMS can ever give, and a bag of
 * attributes a package captured on the other. A seam demanding the second
 * would be unimplementable on the first host in every edition; a seam offering
 * only the first would throw away what the second host has.
 *
 * The bag is `array<string, mixed>` deliberately. It is the mapper's input and
 * the mapper's alone: a use case never sees it, nothing else reads it, and the
 * moment it is mapped it stops existing. Typing it would mean the core had a
 * type for a foreign payload, which is the thing the mapper seam is for.
 *
 * The organisation is here because the local issuer is minted per organisation
 * and never once per site: a single site-wide local issuer would give every
 * organisation the same issuer string, which the global verified-issuer index
 * would let exactly one of them hold, and which would collide two
 * organisations' external keys onto one namespace.
 */
final class IdentityAssertionContext
{
    /** A host group read: identifiers, and the host knows all of them. */
    public const KIND_HOST_GROUPS = 'host_groups';

    /** A bag a package captured, whose completeness the mapper decides. */
    public const KIND_ATTRIBUTES = 'attributes';

    /**
     * @param list<array{key: string, label?: string}> $hostGroups
     * @param array<string, mixed>                     $attributes
     * @param array<string, mixed>                     $config     the connection's own dialect settings
     */
    private function __construct(
        public readonly string $kind,
        public readonly int $organisationId,
        public readonly int $userId,
        public readonly ?string $issuerOverride,
        public readonly ?string $subjectOverride,
        public readonly array $hostGroups,
        public readonly array $attributes,
        public readonly array $config,
    ) {
        if ($organisationId < 1) {
            throw new \InvalidArgumentException('An assertion is read for exactly one organisation.');
        }
    }

    /**
     * The free path on both hosts: this person's host groups or roles.
     *
     * The subject is the host account id, because that is what the host is
     * actually asserting: this is the account, and these are its groups. It is
     * a string because a subject is opaque everywhere else in this design and
     * a local subject that was an integer would be the one place a caller
     * could compare it as a number.
     *
     * @param list<array{key: string, label?: string}> $groups
     * @param array<string, mixed>                     $config
     */
    public static function hostGroups(int $organisationId, int $userId, array $groups, array $config = []): self
    {
        return new self(
            self::KIND_HOST_GROUPS,
            $organisationId,
            $userId,
            null,
            null,
            array_values($groups),
            [],
            $config,
        );
    }

    /**
     * The paid path: whatever a package captured, unread.
     *
     * The issuer and the subject are supplied by the caller here, because a
     * federation's issuer is the federation's and not this site's, and the
     * subject is the value the protocol termination handed over. Both are
     * opaque and neither is parsed.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $config
     */
    public static function attributes(
        int $organisationId,
        int $userId,
        string $issuer,
        string $subject,
        array $attributes,
        array $config = [],
    ): self {
        return new self(
            self::KIND_ATTRIBUTES,
            $organisationId,
            $userId,
            $issuer,
            $subject,
            [],
            $attributes,
            $config,
        );
    }
}
