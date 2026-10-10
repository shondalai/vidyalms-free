<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationJoining;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationIdentityRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\DomainStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The organisation identity surface: connections, links, domains and join
 * rules.
 *
 * Every case here is one of the design's own test-matrix lines. The two that
 * matter most are the pair about the issuer: two organisations claiming one
 * issuer leave at most one verified, and a connection whose issuer is not
 * verified drives nothing.
 */
final class OrganisationIdentityTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrgIdentityStore $identity;
    private ManageOrganisationConnections $connections;
    private ManageOrganisationJoining $joining;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->identity = new OrgIdentityStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        // User 1 is the site manager. Nobody else is, which is what makes the
        // site-scoped transition testable at all.
        $this->env->authorization->grant(1, Capability::MANAGE);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $mappers = new IdentityMapperRegistry();

        foreach (CoreOrganisationProviders::identityMappers() as $mapper) {
            $mappers->register($mapper);
        }

        $joinRules = new OrgJoinRuleRegistry();

        foreach (CoreOrganisationProviders::joinRules() as $rule) {
            $joinRules->register($rule);
        }

        $access = new OrganisationAccess(
            new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
        );

        $this->connections = new ManageOrganisationConnections(
            $access,
            $this->identity,
            $this->store,
            $roles,
            $mappers,
            $this->env->users,
        );

        $this->joining = new ManageOrganisationJoining(
            $access,
            $this->identity,
            $this->store,
            $joinRules,
            $roles,
            $this->env->ports->domainVerification,
            new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock)),
            $this->env->random,
            $this->env->clock,
        );
    }

    public function testTheSurfaceIsThirteenRoutesUnderTheOrganisationsOwnPath(): void
    {
        $paths = array_map(
            static fn (array $definition): string => $definition[0] . ' ' . $definition[1],
            OrganisationIdentityRoutes::definitions(),
        );

        self::assertCount(13, $paths);

        foreach ($paths as $path) {
            self::assertStringContainsString(
                '/manage/organisations/{id}/',
                $path,
                'the tenancy middleware and the access log both key on this prefix',
            );
        }

        // The thirteenth, named rather than counted. Delegation is the second
        // site-scoped transition on this surface and it must stay beside the
        // first: a route placed anywhere else would need the tenancy rule and
        // the access-log rule restated.
        self::assertContains(
            'POST /manage/organisations/{id}/connections/{connection_id}/delegation',
            $paths,
        );
    }

    /** The free build ships one dialect and it is the host's own groups. */
    public function testTheFreeDialectIsRegisteredAndAConnectionCanNameIt(): void
    {
        $org = $this->organisation('acme');
        $connection = $this->connections->create($this->owner(), $org, [
            'issuer' => HostGroupIssuer::forOrganisation($org),
            'dialect' => 'host-group',
        ]);

        self::assertTrue($connection['dialect_resolved']);
        self::assertSame(IssuerStatus::PENDING, $connection['issuer_status']);
        self::assertFalse($connection['authoritative']);
        self::assertFalse($connection['jit_enabled']);
        self::assertFalse($connection['drives_provisioning']);
        self::assertFalse($connection['may_remove']);
    }

    /** A dialect nothing reads is refused at save rather than stored. */
    public function testAConnectionNamingAnUnreadableDialectIsRefused(): void
    {
        $org = $this->organisation('acme');

        $this->expectException(ValidationException::class);
        $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'a-dialect-nobody-registered',
        ]);
    }

    /**
     * The design's own line: two organisations claiming one issuer leave at
     * most one verified.
     */
    public function testTwoOrganisationsClaimingOneIssuerLeaveAtMostOneVerified(): void
    {
        $issuer = 'https://shared.example.test/idp';
        $acme = $this->organisation('acme');
        $northlight = $this->organisation('northlight');

        $first = $this->connections->create($this->owner(), $acme, ['issuer' => $issuer, 'dialect' => 'host-group']);
        $second = $this->connections->create(
            $this->owner(),
            $northlight,
            ['issuer' => $issuer, 'dialect' => 'host-group'],
        );

        // Both claims exist. Claiming is free; being believed is not.
        self::assertSame(IssuerStatus::PENDING, $first['issuer_status']);
        self::assertSame(IssuerStatus::PENDING, $second['issuer_status']);

        $verified = $this->connections->decide(
            $this->owner(),
            $acme,
            (int) $first['id'],
            ['issuer_status' => IssuerStatus::VERIFIED],
        );

        self::assertSame(IssuerStatus::VERIFIED, $verified['issuer_status']);

        try {
            $this->connections->decide(
                $this->owner(),
                $northlight,
                (int) $second['id'],
                ['issuer_status' => IssuerStatus::VERIFIED],
            );
            self::fail('A second organisation verified an issuer another one already holds.');
        } catch (ConflictException $conflict) {
            self::assertStringContainsString('already holds that issuer verified', $conflict->getMessage());
        }

        // The database, not the check, is what makes this true under a race.
        $holders = $this->env->database->selectAll(
            Query::from('org_connections')->whereNotNull('verified_issuer_key')
        );
        self::assertCount(1, $holders);
        self::assertSame($acme, (int) $holders[0]['organisation_id']);
    }

    /** Only a site-scoped principal decides whether a connection is believed. */
    public function testAnOrganisationOwnerCannotVerifyItsOwnIssuerClaim(): void
    {
        $org = $this->organisation('acme');
        $connection = $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'host-group',
        ]);

        $this->memberOf($org, 2, 'org_owner');

        $this->expectException(NotAuthorizedException::class);
        $this->connections->decide(
            Principal::user(2),
            $org,
            (int) $connection['id'],
            ['issuer_status' => IssuerStatus::VERIFIED],
        );
    }

    /** And cannot reach the three site-written flags through its own edit. */
    public function testAnOrganisationEditCannotReachTheSiteWrittenFlags(): void
    {
        $org = $this->organisation('acme');
        $connection = $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'host-group',
        ]);

        foreach (['issuer_status' => 'verified', 'authoritative' => true, 'jit_enabled' => true] as $field => $value) {
            try {
                $this->connections->update($this->owner(), $org, (int) $connection['id'], [$field => $value]);
                self::fail('An organisation wrote ' . $field . ' through its own edit route.');
            } catch (ValidationException $refusal) {
                self::assertArrayHasKey($field, $refusal->errors);
            }
        }
    }

    /** A mapping rule naming another organisation is refused at save. */
    public function testAMappingRuleNamingAForeignOrganisationIsRefusedAtSave(): void
    {
        $acme = $this->organisation('acme');
        $northlight = $this->organisation('northlight');

        try {
            $this->connections->create($this->owner(), $acme, [
                'issuer' => 'https://idp.example.test',
                'dialect' => 'host-group',
                'mapping_rules' => [[
                    'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'Everyone', 'value_kind' => 'name'],
                    'then' => ['org_ref' => 'northlight', 'org_id' => $northlight, 'org_role' => 'org_admin'],
                ]],
            ]);
            self::fail('A rule targeting another organisation was saved.');
        } catch (ValidationException $refusal) {
            self::assertSame(['mapping_rules.0.then.org_id' => 'forbidden_scope'], $refusal->errors);
        }
    }

    /** A rule naming a role no mapping may grant is refused with the same code. */
    public function testAMappingRuleNamingAnUngrantableRoleIsRefused(): void
    {
        $org = $this->organisation('acme');

        try {
            $this->connections->create($this->owner(), $org, [
                'issuer' => 'https://idp.example.test',
                'dialect' => 'host-group',
                'mapping_rules' => [[
                    'when' => ['attribute' => 'groups', 'op' => 'pr', 'value_kind' => 'name'],
                    // org_owner declares ownership and is deliberately not
                    // grantable by a rule: a directory push may make somebody
                    // an administrator and may never make them the owner.
                    'then' => ['org_role' => 'org_owner'],
                ]],
            ]);
            self::fail('A rule granting ownership was saved.');
        } catch (ValidationException $refusal) {
            self::assertSame(['mapping_rules.0.then.org_role' => 'forbidden_scope'], $refusal->errors);
        }
    }

    /** The rules survive a round trip with their resolved ids beside their refs. */
    public function testASavedRuleStoresTheResolvedIdBesideTheRef(): void
    {
        $org = $this->organisation('acme');
        $connection = $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'host-group',
            'mapping_rules' => [[
                'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales', 'value_kind' => 'name'],
                'then' => ['org_ref' => 'acme', 'org_role' => 'org_member'],
            ]],
        ]);

        self::assertSame(1, $connection['mapping_rule_count']);
        self::assertTrue($connection['mapping_rules_readable']);

        $scope = $this->scope($org);
        $stored = $this->identity->connection($scope, (int) $connection['id']);
        self::assertNotNull($stored);
        self::assertSame($org, $stored->rules->rules[0]->orgId, 'the id is resolved at save and stored');
        self::assertSame('acme', $stored->rules->rules[0]->orgRef);
    }

    /** A foreign organisation's connection is a 404 and not a 403. */
    public function testAConnectionOfAnotherOrganisationIsNotFound(): void
    {
        $acme = $this->organisation('acme');
        $northlight = $this->organisation('northlight');
        $connection = $this->connections->create($this->owner(), $northlight, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'host-group',
        ]);

        try {
            $this->connections->update($this->owner(), $acme, (int) $connection['id'], [
                'expected_revision' => 1,
                'state' => 'archived',
            ]);
            self::fail('One organisation edited another organisation connection.');
        } catch (EntityNotFoundException $missing) {
            self::assertSame('Connection not found.', $missing->getMessage());
        }
    }

    /** The free build records domains and shows them unverified. */
    public function testADomainIsRecordedPendingAndShownUnverifiable(): void
    {
        $org = $this->organisation('acme');
        $claim = $this->joining->claimDomain($this->owner(), $org, ['domain' => 'Acme.Example.TEST.']);

        self::assertSame('acme.example.test', $claim['domain'], 'normalised in one place');
        self::assertSame(DomainStatus::PENDING, $claim['status']);
        self::assertFalse($claim['verifiable'], 'no installed provider can prove a claim on a free build');
        self::assertStringStartsWith('vidyalms-site-verification=', $claim['proof_record']);
        self::assertNull($claim['verified_at']);
    }

    /** A public mailbox domain cannot be claimed by anybody. */
    public function testAPublicMailboxDomainIsRefused(): void
    {
        $org = $this->organisation('acme');

        try {
            $this->joining->claimDomain($this->owner(), $org, ['domain' => 'gmail.com']);
            self::fail('A public mailbox domain was claimed.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('domain', $refusal->errors);
        }
    }

    /** Withdrawing a claim releases the key so another organisation is not locked out. */
    public function testRevokingAClaimReleasesTheVerifiedKey(): void
    {
        $org = $this->organisation('acme');
        $claim = $this->joining->claimDomain($this->owner(), $org, ['domain' => 'acme.example.test']);
        $revoked = $this->joining->revokeDomain($this->owner(), $org, (int) $claim['id']);

        self::assertSame(DomainStatus::REVOKED, $revoked['status']);

        $row = $this->env->database->selectOne(
            Query::from('org_domains')->where('id', '=', (int) $claim['id'])
        );
        self::assertNotNull($row);
        self::assertNull($row['verified_key']);
    }

    /** The join rule is a selection over an owned row, not a compile-time constant. */
    public function testTheFreeJoinRuleIsSelectedOverAnOwnedRow(): void
    {
        $org = $this->organisation('acme');

        // With no row at all, the rule in force is the registry's own default,
        // which is the strict one.
        self::assertSame('invitation_only', $this->joining->ruleKeyInForce($this->scope($org)));

        $rule = $this->joining->addJoinRule($this->owner(), $org, ['rule_key' => 'invitation_only']);
        self::assertTrue($rule['resolved']);
        self::assertSame('org_member', $rule['role_key']);
        self::assertSame('invitation_only', $this->joining->ruleKeyInForce($this->scope($org)));

        $retired = $this->joining->retireJoinRule($this->owner(), $org, (int) $rule['id']);
        self::assertSame('retired', $retired['state']);

        // A retired row is not in force, so the answer falls back to the
        // registry's own default rather than to nothing.
        self::assertSame('invitation_only', $this->joining->ruleKeyInForce($this->scope($org)));
    }

    /** A row whose window has not opened is not in force. */
    public function testAJoinRuleOutsideItsWindowIsNotInForce(): void
    {
        $org = $this->organisation('acme');
        $future = $this->env->clock->now()->modify('+30 days')->format('Y-m-d H:i:s');
        $rule = $this->joining->addJoinRule($this->owner(), $org, [
            'rule_key' => 'invitation_only',
            'effective_from' => $future,
        ]);

        self::assertSame($future, $rule['effective_from']);
        self::assertSame('active', $rule['state']);

        // In force is a question about now and not about the state column,
        // which is why the two dates are real columns rather than a note.
        $rows = $this->identity->joinRules($this->scope($org));
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]->isInForce($this->env->clock->now()));
        self::assertTrue($rows[0]->isInForce($this->env->clock->now()->modify('+31 days')));
    }

    /** A rule key nothing answers is refused at save rather than admitting nobody quietly. */
    public function testAJoinRuleKeyNothingAnswersIsRefused(): void
    {
        $org = $this->organisation('acme');

        $this->expectException(ValidationException::class);
        $this->joining->addJoinRule($this->owner(), $org, ['rule_key' => 'verified_domain']);
    }

    /** An identity link is keyed on the pair and carries no address. */
    public function testAnIdentityLinkIsKeyedOnThePairAndCarriesNoAddress(): void
    {
        $org = $this->organisation('acme');
        $issuer = HostGroupIssuer::forOrganisation($org);
        $this->linkFor($org, $issuer, 'subject-one', 2, IdentityLinkState::ACTIVE);

        $found = $this->identity->linkFor($issuer, 'subject-one');
        self::assertNotNull($found);
        self::assertSame(2, $found->userId);
        self::assertTrue($found->identifies());

        // Byte exact: a subject differing by one character is a different
        // identity and matches nothing.
        self::assertNull($this->identity->linkFor($issuer, 'subject-One'));

        $columns = $this->env->database->selectOne(Query::from('org_identity_links')->where('id', '=', $found->id));
        self::assertNotNull($columns);
        self::assertArrayNotHasKey('email', $columns, 'this table has no address column, by design');
    }

    /** A conflict is resolved by a person and never by a merge. */
    public function testAConflictedLinkIsResolvedByAnAdministrator(): void
    {
        $org = $this->organisation('acme');
        $issuer = HostGroupIssuer::forOrganisation($org);
        $id = $this->linkFor($org, $issuer, 'subject-conflict', 2, IdentityLinkState::CONFLICT);

        $pending = $this->connections->links($this->owner(), $org, IdentityLinkState::CONFLICT);
        self::assertCount(1, $pending);

        $resolved = $this->connections->resolveLink($this->owner(), $org, $id, [
            'state' => IdentityLinkState::ACTIVE,
            'user_id' => 3,
        ]);

        self::assertSame(IdentityLinkState::ACTIVE, $resolved['state']);
        self::assertSame(3, $resolved['user_id']);
    }

    /** A revoked link cannot be quietly brought back. */
    public function testARevokedLinkCannotBeReactivated(): void
    {
        $org = $this->organisation('acme');
        $issuer = HostGroupIssuer::forOrganisation($org);
        $id = $this->linkFor($org, $issuer, 'subject-revoked', 2, IdentityLinkState::REVOKED);

        $this->expectException(ConflictException::class);
        $this->connections->resolveLink($this->owner(), $org, $id, ['state' => IdentityLinkState::ACTIVE]);
    }

    /** A secret is never stored: the column takes a reference or nothing. */
    public function testASecretRefMustBeAReferenceAndNeverAValue(): void
    {
        $org = $this->organisation('acme');

        try {
            $this->connections->create($this->owner(), $org, [
                'issuer' => 'https://idp.example.test',
                'dialect' => 'host-group',
                'secret_ref' => 'sk-live-0123456789',
            ]);
            self::fail('A literal secret was stored in secret_ref.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('secret_ref', $refusal->errors);
        }

        $ok = $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test',
            'dialect' => 'host-group',
            'secret_ref' => 'env:VIDYALMS_SECRET_ACME_IDP_SECRET',
        ]);
        self::assertSame('env:VIDYALMS_SECRET_ACME_IDP_SECRET', $ok['secret_ref']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    public function testTenantCannotChangeApprovedIdentityInterpretation(): void
    {
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $org = $this->organisation('acme');
        $connection = $this->connections->create($this->owner(), $org, [
            'issuer' => 'https://idp.example.test', 'dialect' => 'host-group',
        ]);
        $this->connections->decide($this->owner(), $org, (int) $connection['id'], ['issuer_status' => IssuerStatus::VERIFIED]);
        $this->memberOf($org, 2, 'org_owner');
        $revision = (int) $this->store->organisation($this->scope($org))['revision'];

        foreach (['dialect' => 'host-group', 'mapping_rules' => [], 'attribute_overrides' => [], 'secret_ref' => null] as $key => $value) {
            try {
                $this->connections->update(Principal::user(2), $org, (int) $connection['id'], [
                    'expected_revision' => $revision, $key => $value,
                ]);
                self::fail('A tenant changed approved identity configuration.');
            } catch (NotAuthorizedException) {
                self::assertSame($revision, (int) $this->store->organisation($this->scope($org))['revision']);
            }
        }
    }

    public function testLinkActivationRequiresSiteAuthorityAndAnExistingAccount(): void
    {
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $org = $this->organisation('acme');
        $this->memberOf($org, 2, 'org_owner');
        $issuer = HostGroupIssuer::forOrganisation($org);
        $id = $this->linkFor($org, $issuer, 'subject-conflict', 2, IdentityLinkState::CONFLICT);
        try {
            $this->connections->resolveLink(Principal::user(2), $org, $id, ['state' => IdentityLinkState::ACTIVE, 'user_id' => 3]);
            self::fail('A tenant reassigned an external identity.');
        } catch (NotAuthorizedException) {
            self::assertSame(IdentityLinkState::CONFLICT, $this->identity->linkFor($issuer, 'subject-conflict')?->state);
        }
        try {
            $this->connections->resolveLink($this->owner(), $org, $id, ['state' => IdentityLinkState::ACTIVE, 'user_id' => 99999]);
            self::fail('A nonexistent account was linked.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('user_id', $refusal->errors);
        }
        self::assertSame(2, $this->identity->linkFor($issuer, 'subject-conflict')?->userId);
    }

    private function owner(): Principal
    {
        return Principal::user(1);
    }

    private function organisation(string $slug): int
    {
        return $this->store->create(['name' => ucfirst($slug), 'slug' => $slug, 'created_by' => 1]);
    }

    private function scope(int $organisationId): \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $access = new OrganisationAccess(
            new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
        );

        return $access->require($this->owner(), $organisationId, 'read');
    }

    private function memberOf(int $organisationId, int $userId, string $roleKey): void
    {
        $scope = $this->scope($organisationId);
        $memberId = $this->store->addMember($scope, $userId, $roleKey, 'manual', null);
        self::assertGreaterThan(0, $memberId);
    }

    private function linkFor(int $organisationId, string $issuer, string $subject, int $userId, string $state): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        return $this->identity->insertLink([
            'organisation_id' => $organisationId,
            'connection_id' => null,
            'issuer' => $issuer,
            'subject' => $subject,
            'identity_key' => \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey::of($issuer, $subject),
            'user_id' => $userId,
            'external_id' => null,
            'match_basis' => 'matched_subject',
            'match_ref' => null,
            'state' => $state,
            'first_seen_at' => $now,
            'last_asserted_at' => $now,
        ]);
    }
}
