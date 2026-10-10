<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The local issuer, minted per organisation and never once per site.
 *
 * One function, in one place, because both host adapters mint it and two
 * implementations of one string is how two hosts end up with identities that
 * do not match after a migration.
 *
 * **Per organisation is the whole point.** A single configured site-wide local
 * issuer would give every organisation on the site the same issuer string. The
 * global unique index on verified_issuer_key would then let exactly one of
 * them hold it verified, so the first organisation to verify would lock every
 * other one out of its own host-group connection; and every organisation's
 * unit and membership external keys would collide onto one namespace, so two
 * tenants asserting the same host account would be asserting the same
 * identity.
 */
final class HostGroupIssuer
{
    /**
     * The prefix. It is "local" because it is this site asserting about its own
     * accounts, and it is namespaced so it can never collide with a real
     * federation's issuer, which is always a URL or an entity name.
     */
    public const PREFIX = 'local:host:';

    private function __construct()
    {
    }

    public static function forOrganisation(int $organisationId): string
    {
        if ($organisationId < 1) {
            throw new \InvalidArgumentException('A local issuer belongs to one positive organisation.');
        }

        return self::PREFIX . $organisationId;
    }

    /** Whether an issuer string is one this site minted for itself. */
    public static function isLocal(string $issuer): bool
    {
        return str_starts_with($issuer, self::PREFIX);
    }

    /**
     * Which organisation a local issuer names, or null.
     *
     * Null for anything that is not a local issuer, including a string that
     * merely starts with the prefix and carries something that is not a
     * positive integer after it. A caller must not read null as "the site".
     */
    public static function organisationOf(string $issuer): ?int
    {
        if (!self::isLocal($issuer)) {
            return null;
        }

        $tail = substr($issuer, \strlen(self::PREFIX));

        return preg_match('/^[1-9][0-9]*$/', $tail) === 1 ? (int) $tail : null;
    }
}
