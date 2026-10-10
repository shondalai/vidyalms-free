<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/** Checks a pending claim without treating it as an approved joining domain. */
interface VerifiesDomainClaim extends DomainVerificationPort
{
    public function provesClaim(string $domain, string $token): bool;
}
