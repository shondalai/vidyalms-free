<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * Whether a claimed mail domain has actually been proved.
 *
 * Optional and defaulted on Ports, on the same terms as every other port added
 * after the first adapters shipped. The free build records domains and shows
 * them unverified; proving one is a DNS ceremony a paid package performs.
 *
 * Default when absent: refuses everything. A domain that nobody can verify is
 * unverified, never assumed. A join rule that depends on a verified domain
 * therefore asks, at the moment of the decision, whether anything on this site
 * can prove one, and admits nothing but the free invitation floor when nothing
 * can.
 *
 * A paid rule declares `VerifiesDomain`, which ships in `packages/pro-core`,
 * and the probe is at decision time rather than at save. That is structural: a
 * package seats this port through the port-contribution moment
 * ({@see \Shondalai\VidyaLMS\Core\Boot\PortsReady}), which fires while `Ports`
 * is still being assembled and therefore after the moment a save would want to
 * ask, so a save-time refusal could only ever answer about a port nobody had
 * seated yet.
 */
interface DomainVerificationPort
{
    /**
     * Whether this exact domain is verified for this organisation.
     *
     * Both arguments matter. A domain verified by one organisation says
     * nothing about another's claim on it, and at most one organisation ever
     * holds an issuer verified.
     */
    public function isVerified(int $organisationId, string $domain): bool;
}
