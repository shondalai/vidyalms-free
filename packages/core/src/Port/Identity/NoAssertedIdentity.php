<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The default: this host asserts nothing about how anybody signed in.
 *
 * Null is the honest answer rather than a guess, exactly as FixedRecipientProfile
 * answers null for a timezone it does not know. Nothing downstream may read
 * this absence as a denial: an assertion that is not there removes no
 * membership and revokes no link.
 */
final class NoAssertedIdentity implements AssertedIdentityPort
{
    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity
    {
        return null;
    }
}
