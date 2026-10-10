<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * What an identity link is: settled, waiting for a person, or contested.
 *
 * pending is the state an address-based match lands in unless the connection
 * is explicitly authoritative, which is the direct counter to the two
 * behaviours this design is written against: a plugin that links an assertion
 * to an existing account because the address already exists, and one that
 * provisions new accounts by default. Both are safe on a single-tenant site
 * and unsafe the moment organisations exist.
 *
 * conflict is what two candidates produce. Nobody is linked, nothing is
 * merged, and an administrator resolves it through a route. Merging two
 * existing people automatically is not reversible, and correcting duplicated
 * profiles afterwards is documented across this whole product category as the
 * thing customers cannot recover from.
 */
final class IdentityLinkState
{
    /** Settled: this identity is this account. */
    public const ACTIVE = 'active';

    /** Matched on evidence that needs a person to confirm it. */
    public const PENDING = 'pending';

    /** Two candidates. Linked to nobody until an administrator decides. */
    public const CONFLICT = 'conflict';

    /** Withdrawn by an administrator. The row stays, because history stays answerable. */
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const STATES = [self::ACTIVE, self::PENDING, self::CONFLICT, self::REVOKED];

    /** @var array<string, list<string>> */
    public const EDGES = [
        self::PENDING => [self::ACTIVE, self::CONFLICT, self::REVOKED],
        self::CONFLICT => [self::ACTIVE, self::REVOKED],
        self::ACTIVE => [self::REVOKED],
        self::REVOKED => [],
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $state): bool
    {
        return \in_array($state, self::STATES, true);
    }

    public static function permits(string $from, string $to): bool
    {
        return \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /** Whether a link in this state actually says who somebody is. */
    public static function identifies(string $state): bool
    {
        return $state === self::ACTIVE;
    }
}
