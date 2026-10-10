<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MatchBasis;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort;
use Shondalai\VidyaLMS\Core\Port\Identity\NoVerifiedAddresses;
use Shondalai\VidyaLMS\Core\Port\Identity\VerifiedAddressesPort;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreProvisioningSource;

/**
 * The caller the asserted-identity port did not have.
 *
 * Both hosts shipped an `AssertedIdentityPort`, both seated it, and nothing
 * anywhere read it: `Ports::$assertedIdentity` had no reader outside the port
 * directory itself, and `ApplyProvisioningAssertion` had no production caller
 * at all. So the free build's identity clause was a set of parts rather than a
 * path, and the seam Pro and Business register into had never once been
 * exercised end to end by anything an operator could reach.
 *
 * This is the session-resolution use case that closes it. A host adapter calls
 * it when it has just authenticated somebody, and everything after that is the
 * shipped code: the host's own mapper produced the canonical assertion, the
 * connection's stored rules decide what it grants, the removal guard bounds
 * what it may take away, and the decision reference lands on the membership
 * event so the question "why did this person have access on that date" has an
 * explainer to point at.
 *
 * ## Three things it deliberately does not do
 *
 * **It terminates nothing.** The host has already authenticated the person by
 * its own means; this reads what the host says about them afterwards. No
 * certificate, secret or token is held, read or resolved here, and no directory
 * is contacted.
 *
 * **It does not choose a tenant.** The organisation comes from the connection
 * that holds the asserted issuer verified, which is a global lookup by
 * construction, and the scope is minted by the sweep authority from that
 * connection's own recorded claimant. Nothing reads a tenant out of the
 * assertion.
 *
 * **It never removes anybody by itself.** Removal is the removal guard's
 * decision inside {@see ApplyProvisioningAssertion}, and a refusal here is a
 * refusal and never a closure: a person whose issuer holds no verified
 * connection is a person this path has nothing to say about.
 */
final class ApplyHostAssertion
{
    /** The provenance every membership this path writes is attributed to. */
    public const SOURCE = 'host_group';

    public function __construct(
        private readonly AssertedIdentityPort $assertions,
        private readonly OrgIdentityStore $identity,
        private readonly OrganisationSweepAuthority $authority,
        private readonly ApplyProvisioningAssertion $provisioning,
        /**
         * The addresses the host itself vouches for.
         *
         * Trailing and defaulted, so a caller built before the third match
         * rule was reachable keeps constructing this class; the default answers
         * an empty list, which means the host cannot say and never that an
         * address is unverified.
         */
        private readonly VerifiedAddressesPort $addresses = new NoVerifiedAddresses(),
    ) {
    }

    /**
     * Applies whatever the host asserts about this person, or says why it did
     * not.
     *
     * Never throws for an ordinary outcome. A person signing in is not a caller
     * making a request, and an exception at a login form turns a misconfigured
     * connection into a white screen, which is the reason
     * {@see ProvisioningOutcome} exists.
     */
    public function execute(Principal $actor): ProvisioningOutcome
    {
        if ($actor->userId < 1) {
            return ProvisioningOutcome::refused('no_principal');
        }

        $identity = $this->assertions->assertedIdentityFor($actor);

        if ($identity === null) {
            // Silence and not an emptiness. A host with no assertion to make is
            // not a host asserting that this person is in nothing.
            return ProvisioningOutcome::refused('no_assertion');
        }

        $connection = $this->identity->verifiedHolderOf($identity->issuer);

        if ($connection === null || $connection->issuerStatus !== IssuerStatus::VERIFIED) {
            return ProvisioningOutcome::refused('issuer_not_verified');
        }

        $scope = $this->authority->forConnection($connection->id);

        if ($scope === null) {
            // The row that names the tenant also names who claimed it, and a
            // claim whose claimant is gone is not authority this path invents a
            // replacement for.
            return ProvisioningOutcome::refused('no_authority');
        }

        return $this->provisioning->execute(
            $scope,
            $connection,
            $identity,
            CoreProvisioningSource::hostGroup()->key(),
            $actor->userId,
            $this->basisFor($actor, $identity),
        );
    }

    /**
     * Which rule found the account, recorded rather than assumed.
     *
     * The host handed the account over with the assertion, which is what
     * `matched_host_account` says and what no other word in the closed
     * vocabulary says honestly. The one exception is the rule the design puts
     * third in its match order: an address **the host itself** has verified,
     * which is a different party vouching from the one whose assertion is being
     * read. That is what {@see VerifiedAddressesPort} answers, and it is the
     * consumer that port did not have: it shipped declared, defaulted and read
     * by nothing, so the host-verified half of the EmailVerification vocabulary
     * was unreachable.
     *
     * An empty answer means the host cannot say and never that the address is
     * unverified, so it falls through rather than downgrading anything.
     */
    private function basisFor(Principal $actor, AssertedIdentity $identity): string
    {
        $email = $identity->email === null ? '' : strtolower(trim($identity->email));

        if ($email === '') {
            return MatchBasis::HOST_ACCOUNT;
        }

        foreach ($this->addresses->verifiedAddressesFor($actor->userId) as $verified) {
            if (strtolower(trim($verified)) === $email) {
                return MatchBasis::VERIFIED_EMAIL;
            }
        }

        return MatchBasis::HOST_ACCOUNT;
    }
}
