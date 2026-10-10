<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * The default: nothing is verified, because nothing here can verify anything.
 *
 * A refusing default rather than a permissive one. This is a gate, so the
 * answer to "has this domain been proved?" when nobody can answer is no, and
 * the free build shows recorded domains as unverified instead of failing or
 * pretending.
 */
final class UnverifiedDomains implements DomainVerificationPort
{
    public function isVerified(int $organisationId, string $domain): bool
    {
        return false;
    }
}
