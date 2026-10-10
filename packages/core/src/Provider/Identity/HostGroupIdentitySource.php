<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Identity;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgConnection;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;

/**
 * The host-group identity source, written once instead of once per host.
 *
 * Two adapters shipped 215 and 245 lines that were the same algorithm twice
 * over: resolve the tenant by finding the organisation whose verified
 * connection holds this site's own local issuer, refuse on none and on more
 * than one, shape the host's groups as a key and a label, and honour the four
 * adapter security requirements. Only the group lookup differed, which is six
 * lines on each host. A third adapter would have written the other two hundred
 * a third time, and a rule fixed in one copy would have been a rule fixed on
 * one host.
 *
 * So the algorithm is here and each adapter supplies a closure that reads its
 * own tables. That is the seam: a host provides `list<array{key, label?}>` and
 * gets a conformant `AssertedIdentityPort` for it.
 *
 * ## The dialect comes from the registry
 *
 * Both adapters hard-coded `HostGroupIdentityMapper` and compared the
 * connection's dialect to that one key, so a registered third-party dialect
 * could never be asked to map even when a connection named it. Here the
 * connection's own dialect is looked up in {@see IdentityMapperRegistry}, which
 * is what makes the seam a registry rather than a constant. A dialect nothing
 * registers resolves to nothing and the source answers null, which is the
 * registry's published miss direction on the identity path: the assertion is
 * ignored and **no membership is removed**.
 *
 * ## The four security requirements, unchanged
 *
 * No attribute is read from a request: the only input is a `Principal`, which
 * is an integer the authorisation layer produced. No token, secret or
 * certificate is held, read or resolved, and `secret_ref` is never touched: a
 * host asserting about its own accounts has nothing to prove to itself. Nothing
 * is written. No directory is contacted, and there is no `HttpPort` here.
 *
 * ## Which organisation
 *
 * The issuer is minted per organisation, and {@see HostGroupIssuer} says why a
 * single site-wide one would be wrong, so this has to name one. The port's
 * signature carries only a principal, so the organisation is resolved from the
 * site's own rows: an organisation that has claimed the local host-group issuer
 * and had it verified is an organisation that asked to be told about host
 * groups. Exactly one is the answer; none and more than one are both null,
 * because a port that cannot name the organisation must not guess which tenant
 * an assertion belongs to.
 */
final class HostGroupIdentitySource implements AssertedIdentityPort
{
    /**
     * How many organisations one lookup will consider.
     *
     * A bound rather than an unbounded scan, because this runs on a request
     * path. It is generous: a site with more than this many organisations
     * holding a verified local issuer is already in the refusing case below.
     */
    public const MAX_ORGANISATIONS = 50;

    private ?OrgIdentityStore $store = null;

    /**
     * The resolved connection, cached for the life of the request.
     *
     * False means "resolved, and there is none", which is different from null
     * meaning "not yet asked". Without the distinction a site with no
     * organisation repeats two queries on every call.
     */
    private OrgConnection|false|null $connection = null;

    /**
     * The registry is resolved lazily and the group read is a closure.
     *
     * Lazily because Ports is readonly and is finished before the kernel that
     * holds the registries exists, so a source constructed with a registry
     * instance could only ever hold one built a second time. The closure is
     * asked on the request that needs it, which is also when a package that
     * registered a dialect has had its kernel-ready listener run.
     *
     * @param callable(): ?IdentityMapperRegistry                     $mappers the site's dialects, resolved late
     * @param callable(int): list<array{key: string, label?: string}> $groups  the host's own group read
     */
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly UsersPort $users,
        private $mappers,
        private $groups,
    ) {
    }

    /**
     * The site's dialects, or null when the kernel is not up yet.
     *
     * Null answers null all the way out, which is the port's own default and
     * the registry's published miss direction on this path: the assertion is
     * ignored and no membership is removed.
     */
    private function mappers(): ?IdentityMapperRegistry
    {
        try {
            $mappers = ($this->mappers)();
        } catch (\Throwable) {
            return null;
        }

        return $mappers instanceof IdentityMapperRegistry ? $mappers : null;
    }

    /**
     * Null for a person this host asserts nothing about.
     *
     * Null is the published default of the port and it is never a denial: an
     * assertion that is not there removes no membership and revokes no link.
     * That is why an account this site does not have answers null rather than
     * an identity with an empty group list, which would be a complete source
     * saying "in no groups" about somebody who does not exist.
     */
    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity
    {
        if ($principal->guest || $principal->userId < 1) {
            return null;
        }

        $mappers = $this->mappers();
        $connection = $this->connection();

        if ($mappers === null || $connection === null || !$mappers->has($connection->dialect)) {
            return null;
        }

        if (!$this->users->exists($principal->userId)) {
            return null;
        }

        return $mappers->get($connection->dialect)->map(IdentityAssertionContext::hostGroups(
            $connection->organisationId,
            $principal->userId,
            ($this->groups)($principal->userId),
            // The connection's own JSON bag, which is where a dialect's
            // settings live: the mapper reads its own key out of it and
            // ignores everything else, so a later reader of the same bag does
            // not collide with this one.
            $connection->attributeOverrides,
        ));
    }

    /**
     * The one organisation whose verified connection reads host groups, or
     * null.
     *
     * A connection has to be verified and active before it drives anything,
     * which {@see OrgConnection::drivesProvisioning()} answers, and its dialect
     * has to be one this site can read: an organisation that verified a local
     * issuer for a dialect nobody registers is not asking for anything anybody
     * here can answer.
     */
    private function connection(): ?OrgConnection
    {
        if ($this->connection !== null) {
            return $this->connection === false ? null : $this->connection;
        }

        $this->connection = false;
        $mappers = $this->mappers();

        if ($mappers === null) {
            return null;
        }

        try {
            $store = $this->store ??= new OrgIdentityStore($this->database, $this->clock);
            $found = null;

            foreach ($store->organisationsWithVerifiedConnections(self::MAX_ORGANISATIONS) as $organisationId) {
                $connection = $store->verifiedHolderOf(HostGroupIssuer::forOrganisation($organisationId));

                if (
                    $connection === null
                    || !$mappers->has($connection->dialect)
                    || !$connection->drivesProvisioning()
                ) {
                    continue;
                }

                if ($found !== null) {
                    // Two tenants both asking. Refusing is the whole point:
                    // asserting into the wrong one is worse than asserting
                    // into neither, and neither removes anything.
                    return null;
                }

                $found = $connection;
            }

            $this->connection = $found ?? false;

            return $found;
        } catch (\Throwable) {
            // A site mid-upgrade whose identity tables do not exist yet must
            // boot. Silence is the port's own default and removes nothing.
            return null;
        }
    }
}
