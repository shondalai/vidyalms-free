<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ApplyProvisioningAssertion;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\ProvisioningWatermarks;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\FieldConflictPolicy;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingDecision;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MatchBasis;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedGroup;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedKeyKind;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentityMapper;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ProvisioningSyncTask;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\NamesGoverningSource;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\HostGroupAssertedIdentity;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The identity path that actually writes: the free host-group dialect, the
 * removal guard, and the one writer of org_memberships.directory_managed.
 *
 * That column has been read in four places since the spine shipped, and
 * written by nothing: the console refused edits on it, erasure refused
 * erasure on it, and the screen drew a third row shape for it, all against a
 * flag hard coded to zero. A guard nothing can trip is a guard nobody has
 * tested, so both directions are asserted here.
 */
final class OrganisationProvisioningTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrgIdentityStore $identity;
    private ManageOrganisationConnections $connections;
    private ProvisioningSourceRegistry $sources;
    private OrganisationAccess $access;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->identity = new OrgIdentityStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $mappers = new IdentityMapperRegistry();

        foreach (CoreOrganisationProviders::identityMappers() as $mapper) {
            $mappers->register($mapper);
        }

        $this->sources = new ProvisioningSourceRegistry();

        foreach (CoreOrganisationProviders::provisioningSources() as $source) {
            $this->sources->register($source);
        }

        $this->access = new OrganisationAccess(
            new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
        );

        $this->connections = new ManageOrganisationConnections(
            $this->access,
            $this->identity,
            $this->store,
            $roles,
            $mappers,
            $this->env->users,
        );
    }

    // ── The free dialect ─────────────────────────────────────────────────

    public function testTheHostGroupDialectMintsTheIssuerPerOrganisationAndAssertsIdentifiers(): void
    {
        $mapper = new HostGroupIdentityMapper();
        $identity = $mapper->map(IdentityAssertionContext::hostGroups(7, 254559, [
            ['key' => '8', 'label' => 'Manager'],
            ['key' => '2', 'label' => 'Registered'],
        ]));

        self::assertNotNull($identity);
        self::assertSame('local:host:7', $identity->issuer);
        self::assertSame('254559', $identity->subject);
        self::assertTrue($identity->isComplete(), 'the host reads its own tables, so it knows every group');
        self::assertSame(AssertedCompleteness::COMPLETE, $identity->completeness);

        foreach ($identity->assertedGroups() as $group) {
            self::assertTrue(
                $group->isKind(AssertedKeyKind::ID),
                'a host group is a row with a primary key, so its key is an identifier and never a name',
            );
        }

        // A second organisation asserting the same account is a different
        // identity, which is the whole reason the issuer is per organisation.
        $other = $mapper->map(IdentityAssertionContext::hostGroups(9, 254559, [['key' => '8']]));
        self::assertNotNull($other);
        self::assertNotSame($identity->key(), $other->key());
    }

    public function testTheHostGroupDialectRefusesABagItCannotRead(): void
    {
        $mapper = new HostGroupIdentityMapper();

        self::assertNull(
            $mapper->map(IdentityAssertionContext::attributes(7, 1, 'https://idp.example.test', 'sub', ['groups' => ['a']])),
            'null is "I cannot read this", which removes nothing',
        );
    }

    public function testTheHostGroupDialectHonoursItsExclusionList(): void
    {
        $mapper = new HostGroupIdentityMapper();
        $identity = $mapper->map(IdentityAssertionContext::hostGroups(
            7,
            5,
            [['key' => '8'], ['key' => '2']],
            [HostGroupIdentityMapper::EXCLUDE => [2]],
        ));

        self::assertNotNull($identity);
        self::assertSame(['8'], $identity->groups);
    }

    /**
     * The contract both adapters implement, exercised through the core's own
     * double so a host has one reading of it to write against.
     */
    public function testTheHostGroupSourceContractAnswersNullForAnUnknownPerson(): void
    {
        $source = new HostGroupAssertedIdentity(7, [
            254559 => [['key' => '8', 'label' => 'Manager']],
        ]);

        $known = $source->assertedIdentityFor(Principal::user(254559));
        self::assertNotNull($known);
        self::assertSame('local:host:7', $known->issuer);
        self::assertSame(['8'], $known->groups);
        self::assertTrue($known->isComplete());

        self::assertNull(
            $source->assertedIdentityFor(Principal::user(99)),
            'null is "this host asserts nothing about this person", never "they belong to nothing"',
        );
        self::assertNull($source->assertedIdentityFor(Principal::guest()));
    }

    // ── The writer ───────────────────────────────────────────────────────

    public function testAnUnverifiedConnectionDrivesNothing(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection();
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $outcome = $this->writer()->execute(
            $scope,
            $connection,
            $this->assertion($org, '2', ['8']),
            'manual',
            2,
        );

        self::assertFalse($outcome->applied);
        self::assertSame('issuer_not_verified', $outcome->reason);
        self::assertNull($this->store->activeMemberId($scope, 2));
    }

    public function testAnUnregisteredSourceIsRefused(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $outcome = $this->writer()->execute(
            $scope,
            $connection,
            $this->assertion($org, '2', ['8']),
            'a-directory-nobody-registered',
            2,
        );

        self::assertFalse($outcome->applied);
        self::assertSame('unregistered_source', $outcome->reason);
    }

    /**
     * The real write, and the flag it sets.
     *
     * A source declaring NamesGoverningSource governs the rows it wrote, so
     * the console renders them read only and names where they are governed.
     */
    public function testAGoverningSourceWritesTheDirectoryManagedFlag(): void
    {
        $this->sources->register($this->governingSource());
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $outcome = $this->writer()->execute($scope, $connection, $this->assertion($org, '2', ['8']), 'test-directory', 2);

        self::assertTrue($outcome->applied, (string) $outcome->reason);
        self::assertSame(1, $outcome->grantsApplied);
        self::assertTrue($outcome->governed);

        $membership = $this->env->database->selectOne(
            Query::from('org_memberships')->where('organisation_id', '=', $org)->where('user_id', '=', 2)
        );
        self::assertNotNull($membership);
        self::assertSame(1, (int) $membership['directory_managed'], 'the flag has a real writer at last');
        self::assertSame('test-directory', (string) $membership['provisioned_by']);
    }

    /** And a free provenance, which governs nothing, leaves the row ordinary. */
    public function testAFreeProvenanceLeavesTheRowEditable(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $outcome = $this->writer()->execute($scope, $connection, $this->assertion($org, '2', ['8']), 'manual', 2);

        self::assertTrue($outcome->applied, (string) $outcome->reason);
        self::assertFalse($outcome->governed);

        $membership = $this->env->database->selectOne(
            Query::from('org_memberships')->where('organisation_id', '=', $org)->where('user_id', '=', 2)
        );
        self::assertNotNull($membership);
        self::assertSame(0, (int) $membership['directory_managed']);
    }

    /** The event stream points at the explainer, which is why decision_ref exists. */
    public function testTheMembershipEventCarriesTheDecisionReference(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $outcome = $this->writer()->execute($scope, $connection, $this->assertion($org, '2', ['8']), 'manual', 2);
        self::assertNotNull($outcome->decisionRef);
        self::assertStringStartsWith('connection:' . $connectionId . ':', $outcome->decisionRef);

        $event = $this->env->database->selectOne(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $org)
                ->where('event_key', '=', MembershipEvent::JOINED)
        );

        self::assertNotNull($event);
        self::assertSame($outcome->decisionRef, (string) $event['decision_ref']);
        self::assertSame(MembershipEvent::SOURCE_DIRECTORY, (string) $event['source']);
    }

    /**
     * The design's own line: an assertion carrying a verified address from a
     * connection nobody marked authoritative produces a PENDING link and never
     * an active one.
     *
     * emailVerified is asserted by the identity provider the tenant controls,
     * so on its own it is the tenant vouching for an address it may not own.
     * That is the direct counter to a plugin linking an assertion to an
     * existing account because the address already existed.
     */
    public function testAnAssertionFromANonAuthoritativeConnectionProducesAPendingLink(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $claimsVerified = AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($org),
            '2',
            [AssertedGroup::hostGroup('8')],
            [],
            AssertedCompleteness::COMPLETE,
            'user2@example.test',
            \Shondalai\VidyaLMS\Core\Port\Identity\EmailVerification::VERIFIED,
        );

        $this->writer()->execute($scope, $connection, $claimsVerified, 'manual', 2);

        $link = $this->identity->linkFor($claimsVerified->issuer, $claimsVerified->subject);
        self::assertNotNull($link);
        self::assertSame(IdentityLinkState::PENDING, $link->state);
        self::assertFalse($link->identifies(), 'a pending link does not say who somebody is');
        self::assertTrue($link->needsAdministrator());
    }

    /** And the same assertion on an authoritative connection lands active. */
    public function testAnAssertionFromAnAuthoritativeConnectionLandsActive(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(
            verified: true,
            rules: true,
            authoritative: true,
        );
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $this->writer()->execute($scope, $connection, $this->assertion($org, '2', ['8']), 'manual', 2);

        $link = $this->identity->linkFor(HostGroupIssuer::forOrganisation($org), '2');
        self::assertNotNull($link);
        self::assertSame(IdentityLinkState::ACTIVE, $link->state);
        // The host handed the account over and nothing matched, which is what
        // this basis says. matched_subject would claim the pair matched byte
        // for byte about a pair nobody had ever asserted.
        self::assertSame(MatchBasis::HOST_ACCOUNT, $link->matchBasis);
    }

    /** A conflicted link stops the write rather than guessing which person it meant. */
    public function testAConflictedLinkStopsTheWrite(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);
        $issuer = HostGroupIssuer::forOrganisation($org);
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $this->identity->insertLink([
            'organisation_id' => $org,
            'connection_id' => $connectionId,
            'issuer' => $issuer,
            'subject' => '2',
            'identity_key' => IdentityKey::of($issuer, '2'),
            'user_id' => 2,
            'external_id' => null,
            'match_basis' => MatchBasis::PENDING_CONFLICT,
            'match_ref' => null,
            'state' => IdentityLinkState::CONFLICT,
            'first_seen_at' => $now,
            'last_asserted_at' => $now,
        ]);

        $outcome = $this->writer()->execute($scope, $connection, $this->assertion($org, '2', ['8']), 'manual', 2);

        self::assertFalse($outcome->applied);
        self::assertSame('link_conflict', $outcome->reason);
        self::assertNull($this->store->activeMemberId($scope, 2));
    }

    // ── The rule that stops a roster disappearing ────────────────────────

    /** An assertion whose groups claim is absent removes no membership. */
    public function testAPartialAssertionMayNotRemoveAnything(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(
            verified: true,
            rules: true,
            authoritative: true,
        );
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        $truncated = AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($org),
            '2',
            [],
            [],
            AssertedCompleteness::PARTIAL,
        );

        [$mayRemove, $basis] = $this->writer()->mayRemove($connection, $truncated);

        self::assertFalse($mayRemove);
        self::assertSame(MappingDecision::REMOVAL_SOURCE_PARTIAL, $basis);
    }

    /** A complete source on a connection nobody vouched for may not either. */
    public function testACompleteAssertionOnANonAuthoritativeConnectionMayNotRemove(): void
    {
        [$org, $scope, $connectionId] = $this->organisationWithConnection(verified: true, rules: true);
        $connection = $this->identity->connection($scope, $connectionId);
        self::assertNotNull($connection);

        [$mayRemove, $basis] = $this->writer()->mayRemove($connection, $this->assertion($org, '2', ['8']));

        self::assertFalse($mayRemove);
        self::assertSame(MappingDecision::REMOVAL_CONNECTION_NOT_AUTHORITATIVE, $basis);
    }

    // ── The sweep ────────────────────────────────────────────────────────

    /** On a stock build the sweep runs nothing and says so. */
    public function testTheProvisioningSweepIsIdleOnAFreeBuild(): void
    {
        $task = new ProvisioningSyncTask(
            $this->sources,
            new ProvisioningWatermarks($this->env->database, $this->env->clock),
            $this->identity,
        );

        self::assertSame(ProvisioningSyncTask::KEY, $task->key());
        self::assertStringStartsWith(
            'idle:',
            $task->run(new ScheduledTaskRun(1, [], Principal::user(1))),
        );
    }

    /** A watermark round trips and a reset is a stored null rather than a missing row. */
    public function testAWatermarkRoundTripsAndResetsToNull(): void
    {
        $watermarks = new ProvisioningWatermarks($this->env->database, $this->env->clock);

        self::assertNull($watermarks->watermarkFor(7, 'test-directory'));

        $watermarks->advance(7, 'test-directory', '2026-09-07T00:00:00Z');
        self::assertSame('2026-09-07T00:00:00Z', $watermarks->watermarkFor(7, 'test-directory'));

        $watermarks->reset(7, 'test-directory');
        self::assertNull($watermarks->watermarkFor(7, 'test-directory'));

        // One organisation's position never becomes another's.
        self::assertNull($watermarks->watermarkFor(9, 'test-directory'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function writer(): ApplyProvisioningAssertion
    {
        return new ApplyProvisioningAssertion(
            $this->identity,
            $this->store,
            $this->connections,
            $this->sources,
        );
    }

    /** @return array{0: int, 1: OrgScope, 2: int} */
    private function organisationWithConnection(
        bool $verified = false,
        bool $rules = false,
        bool $authoritative = false,
    ): array {
        $org = $this->store->create(['name' => 'Acme', 'slug' => 'acme', 'created_by' => 1]);
        $actor = Principal::user(1);
        $input = [
            'issuer' => HostGroupIssuer::forOrganisation($org),
            'dialect' => 'host-group',
        ];

        if ($rules) {
            $input['mapping_rules'] = [[
                'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => '8', 'value_kind' => 'id'],
                'then' => ['org_role' => 'org_member'],
                'stop' => true,
            ]];
        }

        $connection = $this->connections->create($actor, $org, $input);

        if ($verified) {
            $decision = ['issuer_status' => IssuerStatus::VERIFIED];

            if ($authoritative) {
                $decision['authoritative'] = true;
            }

            $this->connections->decide($actor, $org, (int) $connection['id'], $decision);
        }

        return [$org, $this->access->require($actor, $org, 'read'), (int) $connection['id']];
    }

    /**
     * @param list<string> $groups
     */
    private function assertion(int $organisationId, string $subject, array $groups): AssertedIdentity
    {
        return AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($organisationId),
            $subject,
            array_map(static fn (string $key): AssertedGroup => AssertedGroup::hostGroup($key), $groups),
            [],
            AssertedCompleteness::COMPLETE,
        );
    }

    /** A source that governs its rows, which no free source does. */
    private function governingSource(): ProvisioningSourceInterface
    {
        return new class implements ProvisioningSourceInterface, NamesGoverningSource {
            public function key(): string
            {
                return 'test-directory';
            }

            public function label(): string
            {
                return 'org.provisioning.test_directory';
            }

            public function isContinuous(): bool
            {
                return true;
            }

            public function conflictPolicy(): string
            {
                return FieldConflictPolicy::SOURCE_WINS;
            }

            public function governingSourceLabel(int $organisationId): string
            {
                return 'The test directory';
            }

            /** @return list<string> */
            public function governedFields(): array
            {
                return ['department', 'manager'];
            }
        };
    }
}
