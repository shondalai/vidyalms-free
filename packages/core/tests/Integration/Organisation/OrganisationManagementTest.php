<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Psr\Log\NullLogger;

final class OrganisationManagementTest extends TestCase
{
    private TestPorts $env;
    private ApiKernel $api;
    private int $requestSequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        foreach ([1, 2, 3, 4] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }
        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $this->env->authorization->grant(3, Capability::MANAGE_GROUPS);
        // The seams are filled through the same public seed the kernel calls,
        // so this fixture cannot describe a site nobody runs.
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);
        $roles = $registries->orgRoles;
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $roles);
        $policy = new OrganisationAccess($access);
        $this->api = new ApiKernel(new NullLogger());
        $idempotent = new IdempotentExecution(new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
            $this->env->clock,
        ));
        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations($access, $store, $this->env->users, $roles, $registries->orgTypes),
            new ManageOrganisationMembers($policy, $store, $roles, $this->env->users, $this->env->translator),
            $access,
            $idempotent,
            $this->env->database
        );
    }

    /**
     * The organisation read is the free route that reaches the type registry.
     *
     * The stored type is a label and never a gate, so an unresolved one is
     * returned as its own key with the read saying so, rather than being
     * hidden or refusing. That is the published normal path for this seam, and
     * without a route reaching it the eleven free providers would be a
     * registration nothing calls.
     */
    public function testTheOrganisationReadResolvesItsTypeThroughTheRegistryAndNamesAnUnresolvedOne(): void
    {
        $this->create();

        $read = $this->request('GET', '/manage/organisations/1');
        self::assertSame(200, $read->status);
        self::assertIsArray($read->data);
        self::assertSame('organisation', $read->data['type']);
        self::assertSame('org.type.organisation', $read->data['type_label']);
        self::assertFalse($read->data['type_unresolved']);

        // A type supplied by a package this site has since removed still
        // reads, still shows its own key, and says the provider is gone.
        $this->env->database->update('organisations', ['type' => 'acme.holding_company'], ['id' => 1]);
        $unresolved = $this->request('GET', '/manage/organisations/1');
        self::assertSame(200, $unresolved->status);
        self::assertIsArray($unresolved->data);
        self::assertSame('acme.holding_company', $unresolved->data['type']);
        self::assertNull($unresolved->data['type_label']);
        self::assertTrue($unresolved->data['type_unresolved']);
    }

    public function testCreateReadsUpdatesAndBoundedPagesAreActualDatabaseRows(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $response = $this->request('POST', '/manage/organisations', ['name' => 'Org ' . $i, 'slug' => 'org-' . $i]);
            self::assertSame(201, $response->status);
            self::assertSame(1, $response->data['revision'] ?? null);
        }
        $page = $this->request('GET', '/manage/organisations', query: ['page' => '2', 'per_page' => '3']);
        self::assertSame(7, $page->data['total'] ?? null);
        self::assertSame([4, 5, 6], array_column($page->data['items'], 'id'));
        $update = $this->request('PATCH', '/manage/organisations/1', ['expected_revision' => 1, 'name' => 'Renamed']);
        self::assertSame(200, $update->status);
        self::assertSame(2, $update->data['revision'] ?? null);
        self::assertSame('Renamed', $this->request('GET', '/manage/organisations/1')->data['name'] ?? null);
        self::assertSame(422, $this->request('GET', '/manage/organisations', query: ['per_page' => '101'])->status);
    }

    public function testRoleAloneAndHostCapabilityAloneNeverGrantAnOrganisationOrSiteList(): void
    {
        $this->create();
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
        $add = $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 1, 'user_id' => 4, 'role_key' => 'org_admin',
        ]);
        self::assertSame(201, $add->status);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 4)->status);
        self::assertSame(403, $this->request('GET', '/manage/organisations', actor: 2)->status);
        self::assertSame(401, $this->request('GET', '/manage/organisations/1', actor: 0)->status);
    }

    public function testScopedManagerCannotReadOtherOrganisationOrMutateForeignMemberEvenAsSiteManager(): void
    {
        $this->create();
        $this->create('another');
        $this->add(1, 1, 2, 'org_admin');
        $otherMember = $this->add(2, 1, 3, 'org_member');
        self::assertSame(200, $this->request('GET', '/manage/organisations/1/members', actor: 2)->status);
        $missing = $this->request('GET', '/manage/organisations/999', actor: 2);
        $foreign = $this->request('GET', '/manage/organisations/2', actor: 2);
        self::assertSame($missing->data, $foreign->data);
        $before = $this->request('GET', '/manage/organisations/1')->data;
        self::assertSame(404, $this->request(
            'PATCH',
            '/manage/organisations/1/members/' . $otherMember,
            ['expected_revision' => 2, 'state' => 'left']
        )->status);
        self::assertSame($before, $this->request('GET', '/manage/organisations/1')->data);
    }

    public function testReporterIsReadOnlyAndAdminCannotEscalateRolesOrModifyOwners(): void
    {
        $this->create();
        $this->add(1, 1, 2, 'org_reporter');
        self::assertSame(200, $this->request('GET', '/manage/organisations/1/events', actor: 2)->status);
        self::assertSame(404, $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 2, 'user_id' => 3, 'role_key' => 'org_member',
        ], actor: 2)->status);
        $this->add(1, 2, 3, 'org_admin');
        self::assertSame(403, $this->request('PATCH', '/manage/organisations/1/members/1', [
            'expected_revision' => 3, 'state' => 'left',
        ], actor: 3)->status);
        self::assertSame(403, $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 3, 'user_id' => 4, 'role_key' => 'org_owner',
        ], actor: 3)->status);
    }

    public function testLastOwnerAndClosedHistorySurviveAndNoEnrolmentIsInvented(): void
    {
        $this->create();
        self::assertSame(409, $this->request('PATCH', '/manage/organisations/1/members/1', [
            'expected_revision' => 1, 'state' => 'left',
        ])->status);
        $id = $this->add(1, 1, 2, 'org_member');
        $leave = $this->request(
            'PATCH',
            '/manage/organisations/1/members/' . $id,
            ['expected_revision' => 2, 'state' => 'left']
        );
        self::assertSame(200, $leave->status);
        self::assertSame(409, $this->request(
            'PATCH',
            '/manage/organisations/1/members/' . $id,
            ['expected_revision' => 3, 'state' => 'active']
        )->status);
        self::assertNotSame($id, $this->add(1, 3, 2, 'org_member'));
        self::assertSame(3, $this->request('GET', '/manage/organisations/1/members')->data['total'] ?? null);
        self::assertSame(0, $this->env->database->count(Query::from('enrolments')));
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));
    }

    public function testStaleRevisionAndAtomicIdempotencyCannotDuplicateOrRewriteMemberChanges(): void
    {
        $this->create();
        $body = ['expected_revision' => 1, 'user_id' => 2, 'role_key' => 'org_admin'];
        $first = $this->request('POST', '/manage/organisations/1/members', $body, key: 'owned-retry');
        $replay = $this->request('POST', '/manage/organisations/1/members', $body, key: 'owned-retry');
        self::assertSame($first->data, $replay->data);
        self::assertSame('true', $replay->headers['Idempotent-Replayed']);
        // A spent key carrying different input is unprocessable (422); only
        // the stale revision below is a conflict.
        self::assertSame(422, $this->request(
            'POST',
            '/manage/organisations/1/members',
            array_replace($body, ['user_id' => 3]),
            key: 'owned-retry'
        )->status);
        self::assertSame(409, $this->request(
            'POST',
            '/manage/organisations/1/members',
            array_replace($body, ['user_id' => 3])
        )->status);
        self::assertSame(2, $this->request('GET', '/manage/organisations/1')->data['revision'] ?? null);
        self::assertSame(2, $this->request('GET', '/manage/organisations/1/events')->data['total'] ?? null);
    }

    public function testSuspendedOrganisationFailsClosedForDelegatesAndCanBeRestoredBySiteManager(): void
    {
        $this->create();
        $this->add(1, 1, 2, 'org_owner');
        self::assertSame(200, $this->request('PATCH', '/manage/organisations/1', [
            'expected_revision' => 2, 'state' => 'suspended',
        ])->status);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
        self::assertSame(200, $this->request('PATCH', '/manage/organisations/1', [
            'expected_revision' => 3, 'state' => 'active',
        ])->status);
        self::assertSame(200, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
    }

    public function testDriverStringifiedNumbersRemainJsonNumbersAndKeysAreNotExposed(): void
    {
        $this->create();
        $this->env->database->pdo()->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $row = $this->request('GET', '/manage/organisations/1')->data;
        self::assertIsArray($row);
        self::assertSame(1, $row['id']);
        self::assertSame(1, $row['revision']);
        $member = $this->request('GET', '/manage/organisations/1/members')->data['items'][0] ?? null;
        self::assertIsArray($member);
        self::assertSame(1, $member['user_id']);
        self::assertArrayNotHasKey('active_key', $member);
        self::assertSame('Synthetic User 1', $member['user_name']);
    }

    public function testOwnOrganisationDiscoverySupportsSharedLearnersWithoutGrantingAdminOrForeignRows(): void
    {
        $this->create();
        $this->create('second');
        $this->create('third');
        $this->add(1, 1, 4, 'org_member');
        $this->add(2, 1, 4, 'org_member');
        $first = $this->request('GET', '/me/organisations', actor: 4, query: ['per_page' => '1']);
        self::assertSame(200, $first->status);
        self::assertSame(2, $first->data['total'] ?? null);
        self::assertSame([1], array_column($first->data['items'] ?? [], 'id'));
        $second = $this->request('GET', '/me/organisations', actor: 4, query: ['per_page' => '1', 'page' => '2']);
        self::assertSame([2], array_column($second->data['items'] ?? [], 'id'));
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 4)->status);
        self::assertSame(401, $this->request('GET', '/me/organisations', actor: 0)->status);
    }

    public function testRemovedRoleAndMismatchedRoleScopeFailClosedWithoutFallback(): void
    {
        $this->create();
        $id = $this->add(1, 1, 2, 'org_admin');
        $this->env->database->update(
            'org_role_assignments',
            ['role_key' => 'removed-provider'],
            ['organisation_id' => 1, 'membership_id' => $id]
        );
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
        $this->env->database->update(
            'org_role_assignments',
            ['role_key' => 'org_admin', 'scope_id' => 999],
            ['organisation_id' => 1, 'membership_id' => $id]
        );
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
    }

    public function testReplayRechecksCurrentMembershipAfterDelegatedManagerIsRemoved(): void
    {
        $this->create();
        $id = $this->add(1, 1, 2, 'org_admin');
        $memberId = $this->add(1, 2, 4, 'org_member');
        $path = '/manage/organisations/1/members/' . $memberId;
        $body = ['expected_revision' => 3, 'state' => 'suspended'];
        self::assertSame(200, $this->request('PATCH', $path, $body, 2, 'delegated-retry')->status);
        self::assertSame(200, $this->request(
            'PATCH',
            '/manage/organisations/1/members/' . $id,
            ['expected_revision' => 4, 'state' => 'left']
        )->status);
        self::assertSame(404, $this->request('PATCH', $path, $body, 2, 'delegated-retry')->status);
    }

    public function testDelegatedManagerMustInviteRatherThanAddAnArbitraryAccount(): void
    {
        $this->create();
        $this->add(1, 1, 2, 'org_admin');
        self::assertSame(403, $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 2, 'user_id' => 4, 'role_key' => 'org_member',
        ], 2, 'direct-add')->status);
        self::assertSame(0, $this->env->database->count(Query::from('org_memberships')->where('user_id', '=', 4)));
    }

    public function testDuplicateSlugReturnsConflictAndInvalidFieldsNeverBecomeTenantSelectors(): void
    {
        $this->create();
        self::assertSame(409, $this->request('POST', '/manage/organisations', [
            'name' => 'Duplicate', 'slug' => 'synthetic',
        ])->status);
        self::assertSame(422, $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 1, 'user_id' => 2, 'role_key' => 'org_member', 'organisation_id' => 999,
        ])->status);
        self::assertSame(1, $this->env->database->count(Query::from('organisations')));
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
    }

    private function create(string $slug = 'synthetic'): void
    {
        self::assertSame(201, $this->request('POST', '/manage/organisations', [
            'name' => 'Synthetic Organisation', 'slug' => $slug,
        ])->status);
    }

    /**
     * The OrgRole miss direction is three clauses and the third was published
     * without being built: the membership row survives, the person resolves to
     * no capabilities, and the console names the missing provider. The
     * catalogue iterates the registered providers, so a stored key no package
     * registers appeared nowhere at all and an administrator saw a member with
     * no role and no reason.
     */
    public function testAStoredRoleKeyNoProviderResolvesIsNamedRatherThanInvisible(): void
    {
        $this->create();
        $member = $this->add(1, 1, 2, 'org_member');
        $this->env->database->update(
            'org_role_assignments',
            ['role_key' => 'acme.regional_lead'],
            ['organisation_id' => 1, 'membership_id' => $member],
        );

        $catalogue = $this->request('GET', '/manage/organisations/1/roles');

        self::assertSame(200, $catalogue->status);
        self::assertIsArray($catalogue->data);
        self::assertSame(['acme.regional_lead'], $catalogue->data['missing_providers']);

        // Fail closed is still the direction: the row survives and grants
        // nothing, so the holder cannot even read the organisation.
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);

        foreach ($catalogue->data['items'] as $row) {
            self::assertNotSame('acme.regional_lead', $row['key']);
        }
    }

    public function testACatalogueWithEveryProviderInstalledNamesNoMissingOne(): void
    {
        $this->create();
        $this->add(1, 1, 2, 'org_admin');

        $catalogue = $this->request('GET', '/manage/organisations/1/roles');

        self::assertSame(200, $catalogue->status);
        self::assertIsArray($catalogue->data);
        self::assertSame([], $catalogue->data['missing_providers']);
    }

    private function add(int $org, int $revision, int $user, string $role): int
    {
        $response = $this->request('POST', '/manage/organisations/' . $org . '/members', [
            'expected_revision' => $revision, 'user_id' => $user, 'role_key' => $role,
        ]);
        self::assertSame(201, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));

        $id = $response->data['item']['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    /** @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        int $actor = 1,
        ?string $key = null,
        array $query = []
    ): ApiResponse {
        return $this->api->handle(new ApiRequest(
            $method,
            $path,
            $actor === 0 ? Principal::guest() : Principal::user($actor),
            $query,
            $body,
            $key ?? 'org-test-' . ++$this->requestSequence
        ));
    }
}
