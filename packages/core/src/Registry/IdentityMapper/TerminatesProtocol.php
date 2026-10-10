<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\IdentityMapper;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol;

/**
 * A dialect that says which kinds of connection a package on this site
 * actually terminates.
 *
 * A capability interface rather than a method on {@see
 * IdentityMapperInterface}, because that interface is shipped and a mapper
 * written against it must keep compiling and keep meaning what it meant. A
 * mapper that does not declare this terminates nothing beyond the host's own
 * account, which is the free build's answer and the safe one.
 *
 * It exists because `ConnectionProtocol::FREE` publishes a rule that had no
 * reader: "A connection naming any other protocol is refused at save unless a
 * registered identity mapper declares that dialect." Without the declaration
 * there was nothing to ask, so a stock free build accepted, verified and
 * reported as driving provisioning a connection claiming SAML, OIDC or SCIM,
 * and the operator was shown a configured federation nobody could sign in
 * through.
 *
 * The declaration is evidence and not permission. Declaring `saml` does not
 * make the core terminate SAML, hold a certificate or call a directory; it
 * says that this package does, so a connection row naming it is a row somebody
 * on this site can read.
 */
interface TerminatesProtocol
{
    /**
     * The `ConnectionProtocol` words this dialect reads, beyond the host's own.
     *
     * @return list<string>
     *
     * @see ConnectionProtocol::PROTOCOLS
     */
    public function protocols(): array;
}
