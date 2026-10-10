<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ApplyHostAssertion;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RenewalStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\DomainStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingDecision;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleEvaluator;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleSet;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MatchBasis;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedGroup;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedKeyKind;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentityMapper;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreProvisioningSource;
use Shondalai\VidyaLMS\Core\Registry\Context\FieldDisclosureContext;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureDecision;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosurePolicyInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\HostGroupAssertedIdentity;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The identity, governance and compliance half of the R5 review.
 *
 * Every case is driven through the shipped kernel rather than through a
 * hand-built object graph, because the defects these close are all of the same
 * family: a rule the design publishes, a piece of code that could not reach it,
 * and a test that never asked.
 */
final class IdentityReviewClosuresTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private int $acme;
    private int $north;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports, [
            'organisation_invitation_secret' => 'identity-review-secret-value-00001',
        ]);

        $this->env->users->add(1, 'Site Manager', 'manager@example.test');
        $this->env->users->add(2, 'Sam Ling', 'sam@example.test');
        $this->env->users->add(3, 'Ana Rao', 'ana@example.test');
        $this->env->authorization->grant(1, Capability::MANAGE);

        $this->acme = $this->kernel->organisationStore->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'created_by' => 1,
        ]);
        $this->north = $this->kernel->organisationStore->create([
            'name' => 'Northlight',
            'slug' => 'northlight',
            'created_by' => 1,
        ]);
    }

    // ── The key ──────────────────────────────────────────────────────────

    /**
     * The critical one. A NUL in either half made two genuinely different
     * identities one digest, so the second of them collided on the unique
     * index the design's own (issuer, subject) index could not have collided
     * on.
     */
    public function testANulInEitherHalfCannotCollideTwoIdentitiesOntoOneKey(): void
    {
        $issuer = 'https://idp.example';

        self::assertNotSame(
            IdentityKey::of($issuer, "alice\0bob"),
            IdentityKey::of($issuer . "\0alice", 'bob'),
            'Two distinct pairs are two distinct keys.',
        );
    }

    /** And the boundary refuses the byte outright, so no stored row carries one. */
    public function testAControlByteInAnAssertedIssuerOrSubjectIsRefused(): void
    {
        foreach ([["https://idp\0evil", 'alice'], ['https://idp.example', "alice\0bob"]] as [$issuer, $subject]) {
            try {
                AssertedIdentity::create($issuer, $subject);
                self::fail('A control byte crossed the assertion boundary.');
            } catch (\InvalidArgumentException $refusal) {
                self::assertStringContainsString('control byte', $refusal->getMessage());
            }
        }
    }

    // ── The connection ───────────────────────────────────────────────────

    /**
     * The connection's verification, its rules and its authoritative flag are
     * statements about one issuer, and an assertion from another one was
     * applied under all three.
     */
    public function testAnAssertionFromAnotherIssuerIsRefusedUnderThisConnection(): void
    {
        [$scope, $connection] = $this->verifiedConnection($this->acme, authoritative: true);

        $outcome = $this->kernel->applyProvisioningAssertion->execute(
            $scope,
            $connection,
            AssertedIdentity::asserted(
                'https://attacker.example/idp',
                'subject-1',
                [AssertedGroup::hostGroup('8')],
                [],
                AssertedCompleteness::COMPLETE,
            ),
            CoreProvisioningSource::manual()->key(),
            2,
        );

        self::assertFalse($outcome->applied);
        self::assertSame('issuer_mismatch', $outcome->reason);
        self::assertSame(0, $this->env->database->count(Query::from('org_identity_links')));
    }

    /**
     * The reserved local issuer namespace belongs to the organisation it names.
     * One organisation could claim another's, have it verified, and lock the
     * real owner out of its own host-group connection for ever.
     */
    public function testAnotherOrganisationsLocalIssuerIsRefusedAtSave(): void
    {
        try {
            $this->kernel->organisationConnections->create(Principal::user(1), $this->north, [
                'issuer' => HostGroupIssuer::forOrganisation($this->acme),
                'dialect' => HostGroupIdentityMapper::KEY,
            ]);
            self::fail('Northlight claimed Acme\'s reserved local issuer.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('issuer', $refusal->errors);
        }

        // And the organisation it belongs to still claims and verifies it.
        [, $connection] = $this->verifiedConnection($this->acme);
        self::assertSame(IssuerStatus::VERIFIED, $connection->issuerStatus);
    }

    /** A row stored before the rule existed still cannot be verified into the lock. */
    public function testAStoredSquatCannotBeVerified(): void
    {
        $this->env->database->insert('org_connections', [
            'organisation_id' => $this->north,
            'protocol' => ConnectionProtocol::HOST,
            'dialect' => HostGroupIdentityMapper::KEY,
            'issuer' => HostGroupIssuer::forOrganisation($this->acme),
            'issuer_status' => IssuerStatus::PENDING,
            'authoritative' => 0,
            'jit_enabled' => 0,
            'default_completeness' => AssertedCompleteness::PARTIAL,
            'mapping_rules' => '{"_v":1,"rules":[]}',
            'mapping_rules_version' => MappingRuleSet::SHAPE_VERSION,
            'state' => 'active',
            'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $stored = $this->env->database->selectOne(
            Query::from('org_connections')->where('organisation_id', '=', $this->north)
        );
        self::assertNotNull($stored);

        $this->expectException(ValidationException::class);
        $this->kernel->organisationConnections->decide(
            Principal::user(1),
            $this->north,
            (int) $stored['id'],
            ['issuer_status' => IssuerStatus::VERIFIED],
        );
    }

    /**
     * ConnectionProtocol::FREE published the rule in its own docblock and had
     * no reader, so a stock free build accepted, verified and reported as
     * driving provisioning a connection claiming a protocol nothing terminates.
     *
     * @dataProvider paidProtocols
     */
    public function testAProtocolNothingOnThisSiteTerminatesIsRefused(string $protocol): void
    {
        try {
            $this->kernel->organisationConnections->create(Principal::user(1), $this->acme, [
                'issuer' => 'https://idp.example/' . $protocol,
                'dialect' => HostGroupIdentityMapper::KEY,
                'protocol' => $protocol,
            ]);
            self::fail('A free build recorded a ' . $protocol . ' connection.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('protocol', $refusal->errors);
        }

        self::assertSame(0, $this->env->database->count(Query::from('org_connections')));
    }

    /**
     * @return list<array{string}>
     */
    public static function paidProtocols(): array
    {
        return [
            [ConnectionProtocol::ASSERTION],
            [ConnectionProtocol::TOKEN],
            [ConnectionProtocol::DIRECTORY_PUSH],
        ];
    }

    // ── The stored configuration bag ─────────────────────────────────────

    /**
     * attribute_overrides is the dialect configuration bag both hosts read and
     * it had no writer anywhere, so the shipped mapper's own documented setting
     * could never be configured on a real site.
     */
    public function testTheDialectConfigurationBagIsWrittenVersionedAndReadBackUnwrapped(): void
    {
        $created = $this->kernel->organisationConnections->create(Principal::user(1), $this->acme, [
            'issuer' => HostGroupIssuer::forOrganisation($this->acme),
            'dialect' => HostGroupIdentityMapper::KEY,
            'attribute_overrides' => ['exclude_group_ids' => ['9']],
        ]);

        $scope = $this->kernel->organisationAccess->require(Principal::user(1), $this->acme, 'read');
        $connection = $this->identityStore()->connection($scope, (int) $created['id']);

        self::assertNotNull($connection);
        self::assertSame(
            ['exclude_group_ids' => ['9']],
            $connection->attributeOverrides,
            'The body, without the envelope key the reader used to hand a provider.',
        );

        $row = $this->env->database->selectOne(
            Query::from('org_connections')->where('id', '=', (int) $created['id'])
        );
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['attribute_overrides_version']);
        self::assertStringContainsString('"_v":1', (string) $row['attribute_overrides']);
    }

    /**
     * The one organisation write with no revision claim, carrying the object
     * that decides who gets access.
     */
    public function testEditingAConnectionTakesARevisionClaimLikeEveryOtherOrganisationWrite(): void
    {
        $created = $this->kernel->organisationConnections->create(Principal::user(1), $this->acme, [
            'issuer' => HostGroupIssuer::forOrganisation($this->acme),
            'dialect' => HostGroupIdentityMapper::KEY,
        ]);

        try {
            $this->kernel->organisationConnections->update(
                Principal::user(1),
                $this->acme,
                (int) $created['id'],
                ['mapping_rules' => []],
            );
            self::fail('A connection edit landed with no revision claim.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('expected_revision', $refusal->errors);
        }

        $updated = $this->kernel->organisationConnections->update(
            Principal::user(1),
            $this->acme,
            (int) $created['id'],
            ['expected_revision' => $this->revision($this->acme), 'state' => 'archived'],
        );
        self::assertSame('archived', $updated['state']);
    }

    /** An unknown field is a named refusal rather than a silent drop with a 200. */
    public function testAnUnknownFieldOnAnIdentityWriteIsRefusedByName(): void
    {
        $this->expectException(ValidationException::class);
        $this->kernel->organisationJoining->addJoinRule(Principal::user(1), $this->acme, [
            'rule_key' => 'invitation_only',
            'admits_everybody' => true,
        ]);
    }

    // ── The domain ceremony ──────────────────────────────────────────────

    /**
     * Three of the eight published domain transitions were promotions and all
     * three had no writer, because the only sweep read verified rows alone.
     * Installing a verifier completed nothing.
     */
    public function testAPendingDomainClaimIsPromotedOnceAVerifierCanProveIt(): void
    {
        $claim = $this->kernel->organisationJoining->claimDomain(Principal::user(1), $this->acme, [
            'domain' => 'acme.example.test',
        ]);
        self::assertSame(DomainStatus::PENDING, $claim['status']);

        $kernel = $this->kernelWith(new class implements
            \Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort
        {
            public function isVerified(int $organisationId, string $domain): bool
            {
                return true;
            }
        });

        $result = $kernel->registries->scheduledTasks
            ->get(\Shondalai\VidyaLMS\Core\Provider\ScheduledTask\DomainRecheckTask::KEY)
            ->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(1, [], Principal::user(1)));

        self::assertStringContainsString('promoted=1', $result);

        $row = $this->env->database->selectOne(
            Query::from('org_domains')->where('id', '=', (int) $claim['id'])
        );
        self::assertNotNull($row);
        self::assertSame(DomainStatus::VERIFIED, (string) $row['status']);
        self::assertSame('acme.example.test', (string) $row['verified_key']);
    }

    // ── The identity path ────────────────────────────────────────────────

    /**
     * Nothing anywhere read Ports::$assertedIdentity, so the free identity
     * clause was a set of parts and not a path.
     */
    public function testTheHostAssertionPathReadsThePortAndGrantsThroughTheStoredRule(): void
    {
        $this->verifiedConnection($this->acme, authoritative: true, rules: true);

        $kernel = $this->kernelWith(assertions: new HostGroupAssertedIdentity(
            $this->acme,
            [2 => [['key' => '8', 'label' => 'Sales']]],
        ));

        $outcome = $kernel->applyHostAssertion->execute(Principal::user(2));

        self::assertTrue($outcome->applied, (string) $outcome->reason);
        self::assertSame(1, $outcome->grantsApplied);
        self::assertNotNull($outcome->decisionRef);

        $membership = $this->env->database->selectOne(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->acme)
                ->where('user_id', '=', 2)
        );
        self::assertNotNull($membership);
        self::assertSame(MembershipState::ACTIVE, (string) $membership['state']);
        self::assertSame(ApplyHostAssertion::SOURCE, (string) $membership['provisioned_by']);

        $link = $this->env->database->selectOne(Query::from('org_identity_links'));
        self::assertNotNull($link);
        self::assertSame(MatchBasis::HOST_ACCOUNT, (string) $link['match_basis']);
    }

    /** A host with nothing to say removes nobody and writes nothing. */
    public function testAHostWithNoAssertionIsSilenceAndNeverAnEmptiness(): void
    {
        $this->verifiedConnection($this->acme, authoritative: true, rules: true);

        $outcome = $this->kernel->applyHostAssertion->execute(Principal::user(2));

        self::assertFalse($outcome->applied);
        self::assertSame('no_assertion', $outcome->reason);
        self::assertSame(0, $this->env->database->count(Query::from('org_memberships')));
    }

    /**
     * Rule two of the published match order had no implementation, and two
     * candidates had no ceremony: five of the six basis words had no writer at
     * all.
     */
    public function testTheConnectionsOwnExternalIdMatchesAndTwoCandidatesRaiseAConflict(): void
    {
        [$scope, $connection] = $this->verifiedConnection($this->acme, authoritative: true, rules: true);

        $first = $this->kernel->applyProvisioningAssertion->execute(
            $scope,
            $connection,
            $this->assertion('subject-a', ['8'], ['external_id' => 'E-77']),
            CoreProvisioningSource::hostGroup()->key(),
            2,
        );
        self::assertTrue($first->applied, (string) $first->reason);

        // The same person under a subject the directory reissued, with no
        // account supplied: rule two finds them.
        $reissued = $this->kernel->applyProvisioningAssertion->execute(
            $scope,
            $connection,
            $this->assertion('subject-b', ['8'], ['external_id' => 'E-77']),
            CoreProvisioningSource::hostGroup()->key(),
        );
        self::assertTrue($reissued->applied, (string) $reissued->reason);
        self::assertSame(
            MatchBasis::EXTERNAL_ID,
            (string) $this->linkFor('subject-b')['match_basis'],
        );

        // A third subject carrying the same external id and a different
        // account is two candidates, which nothing merges.
        $conflict = $this->kernel->applyProvisioningAssertion->execute(
            $scope,
            $connection,
            $this->assertion('subject-c', ['8'], ['external_id' => 'E-77']),
            CoreProvisioningSource::hostGroup()->key(),
            3,
        );
        self::assertFalse($conflict->applied);
        self::assertSame('identity_conflict', $conflict->reason);
        self::assertSame(IdentityLinkState::CONFLICT, (string) $this->linkFor('subject-c')['state']);
        self::assertSame(MatchBasis::PENDING_CONFLICT, (string) $this->linkFor('subject-c')['match_basis']);
    }

    /**
     * A directory that changed its group key kind grants nothing and is not
     * evidence that anybody left.
     */
    public function testAKeyKindMismatchRefusesRemovalWithItsOwnBasis(): void
    {
        $rules = MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'Sales', 'value_kind' => 'name'],
            'then' => ['org_id' => $this->acme, 'org_role' => 'org_member'],
            'stop' => true,
        ]]);
        $identity = AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($this->acme),
            'subject-1',
            [AssertedGroup::of('8', AssertedKeyKind::ID)],
            [],
            AssertedCompleteness::COMPLETE,
        );

        $decision = (new MappingRuleEvaluator())->evaluate(
            $rules,
            $identity,
            \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingBound::forConnection(
                $this->acme,
                [],
                ['org_member' => []],
            ),
            true,
        );

        self::assertSame([], $decision->grants);
        self::assertFalse($decision->mayRemove);
        self::assertSame(MappingDecision::REMOVAL_EVIDENCE_UNREADABLE, $decision->removalBasis);
    }

    // ── Disclosure ───────────────────────────────────────────────────────

    /**
     * The registry could not select any provider but the hard-coded free one,
     * although the field's own docblock said the factories would resolve it
     * from the registry on the day a provider existed.
     */
    public function testTheDisclosureKeyIsResolvedFromTheRegistryAndNotDefaulted(): void
    {
        $scope = $this->kernel->organisationAccess->require(Principal::user(1), $this->acme, 'read');
        self::assertSame('org_minimal', $scope->disclosureKey, 'The free key, with nothing else registered.');

        $this->kernel->registries->fieldDisclosure->register(new class implements FieldDisclosurePolicyInterface {
            public function key(): string
            {
                return 'org_declared';
            }

            public function label(): string
            {
                return 'org.disclosure.declared';
            }

            public function decide(FieldDisclosureContext $context): string
            {
                return FieldDisclosureDecision::ALLOW;
            }
        });
        $this->env->database->insert('settings', [
            'name' => OrgScopeResolver::DISCLOSURE_SETTING . '.' . $this->acme,
            'value' => json_encode('org_declared', JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $kernel = $this->kernelWith();
        $kernel->registries->fieldDisclosure->register(new class implements FieldDisclosurePolicyInterface {
            public function key(): string
            {
                return 'org_declared';
            }

            public function label(): string
            {
                return 'org.disclosure.declared';
            }

            public function decide(FieldDisclosureContext $context): string
            {
                return FieldDisclosureDecision::ALLOW;
            }
        });

        $resolved = $kernel->organisationAccess->require(Principal::user(1), $this->acme, 'read');
        self::assertSame('org_declared', $resolved->disclosureKey);
        self::assertNotSame($scope->digest(), $resolved->digest(), 'The policy is in the digest.');
    }

    // ── Compliance ───────────────────────────────────────────────────────

    /**
     * Both compliance axes published a machine in the specification and neither
     * carried one in code, so the sweep saved whatever a rule answered.
     */
    public function testBothComplianceAxesPublishTheMachineTheSpecificationStates(): void
    {
        self::assertFalse(ComplianceStatus::permits(ComplianceStatus::EXPIRED, ComplianceStatus::ASSIGNED));
        self::assertTrue(ComplianceStatus::permits(ComplianceStatus::OVERDUE, ComplianceStatus::COMPLIANT));
        self::assertTrue(ComplianceStatus::permits(ComplianceStatus::ASSIGNED, ComplianceStatus::ASSIGNED));

        self::assertFalse(RenewalStatus::permits(RenewalStatus::RENEWAL_OVERDUE, RenewalStatus::DUE));
        self::assertTrue(RenewalStatus::permits(RenewalStatus::WINDOW_OPEN, RenewalStatus::NOT_DUE));

        self::assertSame(array_keys(ComplianceStatus::EDGES), ComplianceStatus::all());
        self::assertSame(array_keys(RenewalStatus::EDGES), RenewalStatus::all());
    }

    // ── The host seam ────────────────────────────────────────────────────

    /**
     * The algorithm a third adapter would have written a third time.
     *
     * Two adapters shipped 215 and 245 lines of the same tenant resolution,
     * refusal rules and assertion shaping, differing only in the six lines that
     * read the host's own groups. This is the core seam that leaves an adapter
     * those six: a closure in, a conformant AssertedIdentityPort out. And the
     * dialect comes from the registry rather than from a hard-coded key, which
     * is what makes a registered third-party dialect reachable at all.
     */
    public function testAHostSuppliesAClosureAndGetsAConformantIdentityPort(): void
    {
        $this->verifiedConnection($this->acme, authoritative: true, rules: true);

        $source = new \Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentitySource(
            $this->env->database,
            $this->env->clock,
            $this->env->users,
            fn (): \Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry
                => $this->kernel->registries->identityMappers,
            static fn (int $userId): array => $userId === 2 ? [['key' => '8', 'label' => 'Sales']] : [],
        );

        $identity = $source->assertedIdentityFor(Principal::user(2));

        self::assertNotNull($identity);
        self::assertSame(HostGroupIssuer::forOrganisation($this->acme), $identity->issuer);
        self::assertSame(['8'], $identity->groups);
        self::assertSame(AssertedKeyKind::ID, $identity->assertedGroups()[0]->keyKind);

        // A guest asserts nothing, and neither does an account this site does
        // not have: null is the port's own default and is never a denial.
        self::assertNull($source->assertedIdentityFor(Principal::guest()));
        self::assertNull($source->assertedIdentityFor(Principal::user(99)));
    }

    /** A dialect nothing on this site registers resolves to nothing rather than to the shipped one. */
    public function testAConnectionNamingADialectNobodyRegistersAssertsNothing(): void
    {
        [, $connection] = $this->verifiedConnection($this->acme, authoritative: true);
        $this->env->database->update(
            'org_connections',
            ['dialect' => 'entra-groups'],
            ['id' => $connection->id],
        );

        $source = new \Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentitySource(
            $this->env->database,
            $this->env->clock,
            $this->env->users,
            fn (): \Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry
                => $this->kernel->registries->identityMappers,
            static fn (int $userId): array => [['key' => '8']],
        );

        self::assertNull($source->assertedIdentityFor(Principal::user(2)));
    }

    // ── Terms, roles and retention ───────────────────────────────────────

    /**
     * org_memberships.ends_at was written by every roster load carrying a
     * OneRoster endDate and read by nothing: no sweep, filter or access check
     * consulted it, so a membership that ended two years ago was still active.
     */
    public function testAMembershipPastItsAgreedEndDateIsClosedBySweep(): void
    {
        $membership = $this->env->database->insert('org_memberships', [
            'organisation_id' => $this->acme,
            'user_id' => 2,
            'state' => MembershipState::ACTIVE,
            'active_key' => $this->acme . ':2',
            'joined_at' => '2024-01-01 00:00:00',
            'ends_at' => '2024-06-30 00:00:00',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);

        $result = $this->kernel->registries->scheduledTasks
            ->get(\Shondalai\VidyaLMS\Core\Provider\ScheduledTask\MembershipTermSweepTask::KEY)
            ->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(1, [], Principal::user(1)));

        self::assertSame('closed=1 skipped=0', $result);

        $row = $this->env->database->selectOne(
            Query::from('org_memberships')->where('id', '=', $membership)
        );
        self::assertNotNull($row);
        self::assertSame(MembershipState::LEFT, (string) $row['state']);

        // A sweep and not a person, which is what the two provenance columns
        // are read to rule out.
        $event = $this->env->database->selectOne(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $this->acme)
                ->orderBy('id', Query::SORT_DESC)
        );
        self::assertNotNull($event);
        self::assertSame('system', (string) $event['source']);
        // The actor half of the same pair. Asserting the source alone leaves
        // the column that says WHAT KIND of thing acted unchecked, and the two
        // are exactly the pair that must never disagree.
        self::assertSame(
            \Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent::ACTOR_SYSTEM,
            (string) $event['actor_type'],
        );
    }

    /** A conformant LTI platform sending the more precise form used to get nothing. */
    public function testAnLtiSubRoleMatchesItsBaseRoleAndSaysTheDistinctionWasDropped(): void
    {
        $roles = $this->kernel->registries->orgRoles;
        $mapping = $this->kernel->registries->orgRoleMappers->translate(
            \Shondalai\VidyaLMS\Core\Provider\Organisation\StandardOrgRoleMapper::LTI_STEM
                . 'Instructor#PrimaryInstructor',
            \Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext::forOrganisation(
                $this->acme,
                $roles->keys(),
                \Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext::VOCABULARY_LTI,
            ),
            $roles,
        );

        self::assertTrue($mapping->isMapped());
        self::assertSame('org_member', $mapping->requireRoleKey());
        self::assertStringContainsString('sub-role', (string) $mapping->warning);
    }

    /**
     * Three of the four stored retention fields were write-only, so a policy an
     * administrator saved changed nothing at all.
     */
    public function testTheOrganisationsOwnRetentionPolicyIsReadRatherThanOnlyStored(): void
    {
        $this->kernel->organisationPrivacy->setRetention(Principal::user(1), $this->acme, [
            'expected_revision' => $this->revision($this->acme),
            'retention_policy' => [
                'artefact_days' => 400,
                'record_days' => 900,
                'statute_ref' => 'UK Companies Act 2006 s388',
            ],
        ]);

        $scope = $this->kernel->organisationAccess->require(Principal::user(1), $this->acme, 'read');

        self::assertSame(400, $this->kernel->organisationRetention->artefactDaysFor($scope, 30));
        self::assertSame(
            900,
            $this->kernel->organisationRetention->artefactDaysFor($scope, 900),
            'The site value is a floor the organisation may lengthen and never shorten.',
        );
        self::assertSame(900, $this->kernel->organisationRetention->recordDaysFor($scope));
        self::assertSame(
            'UK Companies Act 2006 s388',
            $this->kernel->organisationRetention->statuteRefFor($scope),
        );
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    /** @return array{0: \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope, 1: \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgConnection} */
    private function verifiedConnection(int $orgId, bool $authoritative = false, bool $rules = false): array
    {
        $input = [
            'issuer' => HostGroupIssuer::forOrganisation($orgId),
            'dialect' => HostGroupIdentityMapper::KEY,
        ];

        if ($rules) {
            $input['mapping_rules'] = [[
                'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => '8', 'value_kind' => 'id'],
                'then' => ['org_role' => 'org_member'],
                'stop' => true,
            ]];
        }

        $created = $this->kernel->organisationConnections->create(Principal::user(1), $orgId, $input);
        $decision = ['issuer_status' => IssuerStatus::VERIFIED];

        if ($authoritative) {
            $decision['authoritative'] = true;
        }

        $this->kernel->organisationConnections->decide(
            Principal::user(1),
            $orgId,
            (int) $created['id'],
            $decision,
        );

        $scope = $this->kernel->organisationAccess->require(Principal::user(1), $orgId, 'read');
        $connection = $this->identityStore()->connection($scope, (int) $created['id']);
        self::assertNotNull($connection);

        return [$scope, $connection];
    }

    /**
     * @param list<string>         $groups
     * @param array<string, mixed> $attributes
     */
    private function assertion(string $subject, array $groups, array $attributes = []): AssertedIdentity
    {
        return AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($this->acme),
            $subject,
            array_map(static fn (string $key): AssertedGroup => AssertedGroup::hostGroup($key), $groups),
            $attributes,
            AssertedCompleteness::COMPLETE,
        );
    }

    /** @return array<string, mixed> */
    private function linkFor(string $subject): array
    {
        $row = $this->env->database->selectOne(
            Query::from('org_identity_links')->where('subject', '=', $subject)
        );
        self::assertNotNull($row, 'No link for ' . $subject);

        return $row;
    }

    private function identityStore(): \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore
    {
        return new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore(
            $this->env->database,
            $this->env->clock,
        );
    }

    private function revision(int $orgId): int
    {
        $row = $this->env->database->selectOne(Query::from('organisations')->where('id', '=', $orgId));

        return (int) ($row['revision'] ?? 1);
    }

    /** A second kernel over the same database, with one port replaced. */
    private function kernelWith(
        ?\Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort $domains = null,
        ?\Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort $assertions = null,
    ): CoreKernel {
        $ports = new Ports(
            database: $this->env->database,
            users: $this->env->users,
            authorization: $this->env->authorization,
            storage: $this->env->storage,
            mailer: $this->env->mailer,
            scheduler: $this->env->scheduler,
            eventBridge: $this->env->eventBridge,
            commerce: $this->env->commerce,
            translator: $this->env->translator,
            cache: $this->env->cache,
            clock: $this->env->clock,
            random: $this->env->random,
            logger: new \Psr\Log\NullLogger(),
            locale: $this->env->locale,
            courseCategories: $this->env->courseCategories,
            assertedIdentity: $assertions ?? $this->env->ports->assertedIdentity,
            domainVerification: $domains ?? $this->env->ports->domainVerification,
        );

        return new CoreKernel($ports, [
            'organisation_invitation_secret' => 'identity-review-secret-value-00001',
        ]);
    }
}
