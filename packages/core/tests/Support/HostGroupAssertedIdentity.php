<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentityMapper;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;

/**
 * The host-group identity source contract, as a double both adapters can be
 * written against.
 *
 * It exists in the core rather than in each adapter's own tests because the
 * contract is the core's: an adapter reads the host's groups and hands them
 * over, the free dialect maps them, and the issuer is minted per organisation
 * by one function. A double per adapter would be two readings of one contract,
 * and the day they disagreed the disagreement would look like a host
 * difference rather than a defect.
 *
 * What an adapter's own implementation adds is the part this cannot have: the
 * actual read of the host's group table. Everything above it is here, so an
 * adapter test can assert the shape it must produce without a database.
 */
final class HostGroupAssertedIdentity implements AssertedIdentityPort
{
    /**
     * @param array<int, list<array{key: string, label?: string}>> $groupsByUser
     * @param array<string, mixed>                                $config
     */
    public function __construct(
        private readonly int $organisationId,
        private readonly array $groupsByUser,
        private readonly array $config = [],
        private readonly HostGroupIdentityMapper $mapper = new HostGroupIdentityMapper(),
    ) {
    }

    /**
     * Null for a person this host knows nothing about, which is the published
     * default and is never a denial: an assertion that is not there removes no
     * membership.
     */
    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity
    {
        $userId = $principal->userId;

        if ($userId < 1 || !isset($this->groupsByUser[$userId])) {
            return null;
        }

        return $this->mapper->map(IdentityAssertionContext::hostGroups(
            $this->organisationId,
            $userId,
            $this->groupsByUser[$userId],
            $this->config,
        ));
    }
}
