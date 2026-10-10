<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentityMapper;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgType\OrgTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\SeatEventReason\SeatEventReasonInterface;
use Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition\SeatExpiryDispositionInterface;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationInterface;
use Shondalai\VidyaLMS\Core\Registry\SeatReclaim\SeatReclaimInterface;
use Shondalai\VidyaLMS\Core\Registry\SeatScope\SeatScopeInterface;

/**
 * The free build's organisation providers, in one list the kernel registers
 * and a generator can replay.
 *
 * It is a list rather than four calls inside the kernel for the reason the
 * assessment providers are: a catalogue built from a second list describes a
 * site nobody runs. Everything here is registered through the same public
 * method a package outside the core uses, so the free providers are proof the
 * seam works rather than a privileged path beside it.
 */
final class CoreOrganisationProviders
{
    private function __construct()
    {
    }

    /**
     * Registers every free organisation provider into the seated registries.
     *
     * One entry point rather than four loops at the call site, so the kernel,
     * a test harness and a future catalogue generator all fill the registries
     * the same way and cannot drift into describing a site nobody runs. It
     * uses the same public register() a package outside the core uses, and a
     * duplicate key throws here exactly as it would there.
     */
    public static function seed(Registries $registries): void
    {
        foreach (self::roles() as $role) {
            $registries->orgRoles->register($role);
        }

        foreach (self::types() as $type) {
            $registries->orgTypes->register($type);
        }

        foreach (self::joinRules() as $rule) {
            $registries->orgJoinRules->register($rule);
        }

        foreach (self::seatReclaimPolicies() as $policy) {
            $registries->seatReclaim->register($policy);
        }

        foreach (self::invitationMatchPolicies() as $policy) {
            $registries->invitationMatch->register($policy);
        }

        foreach (self::seatScopes() as $scope) {
            $registries->seatScopes->register($scope);
        }

        foreach (self::seatOverAllocationPolicies() as $stance) {
            $registries->seatOverAllocation->register($stance);
        }

        foreach (self::seatExpiryDispositions() as $disposition) {
            $registries->seatExpiryDisposition->register($disposition);
        }

        foreach (self::seatEventReasons() as $reason) {
            $registries->seatEventReasons->register($reason);
        }

        foreach (self::roleMappers() as $mapper) {
            $registries->orgRoleMappers->register($mapper);
        }

        foreach (self::identityMappers() as $dialect) {
            $registries->identityMappers->register($dialect);
        }

        foreach (self::provisioningSources() as $source) {
            $registries->provisioningSources->register($source);
        }

        foreach (self::catalogueSources() as $source) {
            $registries->orgCatalogueSources->register($source);
        }

        // The three seats that had a published miss direction, an edition-table
        // row and nothing else: no provider, no reader, and a silent no-op for
        // any registration into them. Each now has the free provider the design
        // names and a reader that applies what it answers.
        $registries->orgVisibility->register(new OrgSourcedOnlyVisibility());
        $registries->orgMembershipTermination->register(new SuspendAndRetainTermination());
        $registries->orgUnitMove->register(new CarryEverythingUnitMove());
    }

    /**
     * The two free ways a course reaches an organisation.
     *
     * They register for the reason the identity dialect does: an extension
     * point whose only providers are paid is an extension point nobody
     * exercises, and the registry sat empty from R1 to R10 with the entry
     * table it selects from unbuilt beside it. With these two seated, an
     * organisation can be given a released course explicitly or given a
     * department's whole list, on a stock free build, and the Pro programme
     * and query sources register into a seam that already has readers.
     *
     * @return list<OrgCatalogueSourceInterface>
     */
    public static function catalogueSources(): array
    {
        return [new CourseCatalogueSource(), new UnitSubtreeCatalogueSource()];
    }

    /**
     * The one free identity dialect: the host's own groups.
     *
     * It is what makes the identity seam something the free build exercises
     * rather than an empty registry with a paid provider promised. On both
     * hosts, on day one, an organisation can claim a local issuer, write
     * mapping rules against the host's own groups and read a decision that
     * explains what a person was granted and why.
     *
     * @return list<IdentityMapperInterface>
     */
    public static function identityMappers(): array
    {
        return [new HostGroupIdentityMapper()];
    }

    /**
     * The three free provenances.
     *
     * They register because org_memberships.provisioned_by has held one of
     * these three words since the spine shipped and nothing resolved it: the
     * column was a string a screen printed. A registered vocabulary is what
     * lets the console ask whether a row is governed somewhere else, and lets
     * a paid directory be a registration rather than a fourth word added to a
     * schema comment.
     *
     * @return list<ProvisioningSourceInterface>
     */
    public static function provisioningSources(): array
    {
        return CoreProvisioningSource::all();
    }

    /**
     * The two free seat scopes.
     *
     * @return list<SeatScopeInterface>
     */
    public static function seatScopes(): array
    {
        return [
            new CoreSeatScope(CoreSeatScope::COURSE),
            new CoreSeatScope(CoreSeatScope::PROGRAMME),
        ];
    }

    /**
     * The one free over-allocation stance.
     *
     * @return list<SeatOverAllocationInterface>
     */
    public static function seatOverAllocationPolicies(): array
    {
        return [new RefuseAndQueueOverAllocation()];
    }

    /**
     * The one free expiry disposition.
     *
     * @return list<SeatExpiryDispositionInterface>
     */
    public static function seatExpiryDispositions(): array
    {
        return [new LapseAccessKeepProgress()];
    }

    /**
     * The ten free reason keys.
     *
     * @return list<SeatEventReasonInterface>
     */
    public static function seatEventReasons(): array
    {
        return array_map(
            static fn (string $key): SeatEventReasonInterface => new CoreSeatEventReason($key),
            SeatEventReason::FREE_REASONS,
        );
    }

    /**
     * The one free role mapper: the three standard foreign vocabularies.
     *
     * A free provider that a free code path reaches, which is the rule every
     * seam here is held to: both roster importers translate every role token
     * through this registry, so the two hard rules are exercised by the free
     * build on day one rather than by a paid package later.
     *
     * @return list<OrgRoleMapperInterface>
     */
    public static function roleMappers(): array
    {
        return [new StandardOrgRoleMapper()];
    }
    /**
     * The four roles the free build ships.
     *
     * @return list<OrgRoleInterface>
     */
    public static function roles(): array
    {
        return [
            new CoreOrgOwnerRole(),
            CoreOrgRole::administrator(),
            CoreOrgRole::reporter(),
            CoreOrgRole::member(),
        ];
    }

    /**
     * The five general shapes plus the six OneRoster values.
     *
     * The OneRoster six are free rather than paid because a district's own
     * export has to load into the free build for the roster mapper seam to
     * mean anything, and a type is a label rather than a gate.
     *
     * @return list<OrgTypeInterface>
     */
    public static function types(): array
    {
        $types = [];

        foreach (
            ['organisation', 'department', 'team', 'location', 'division',
                'school', 'district', 'local', 'state', 'national', 'company'] as $order => $key
        ) {
            $types[] = new CoreOrgType($key, $order);
        }

        return $types;
    }

    /**
     * The one free join rule.
     *
     * @return list<OrgJoinRuleInterface>
     */
    public static function joinRules(): array
    {
        return [new InvitationOnlyJoinRule()];
    }

    /**
     * The four free reclaim policies.
     *
     * @return list<SeatReclaimInterface>
     */
    public static function seatReclaimPolicies(): array
    {
        return [
            new CoreSeatReclaimPolicy('never'),
            new CoreSeatReclaimPolicy('always'),
            new CoreSeatReclaimPolicy('before_start'),
            new CoreSeatReclaimPolicy('before_completion'),
        ];
    }

    /**
     * The one free invitation match policy.
     *
     * It is the ceremony the free build already performed, moved behind the
     * seam that was seated for it, so a package registering `relaxed` changes
     * the decision without touching any of the three files that used to spell
     * the comparison out by hand.
     *
     * @return list<InvitationMatchPolicyInterface>
     */
    public static function invitationMatchPolicies(): array
    {
        return [new StrictEmailInvitationMatch()];
    }
}
