<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\InvitationAttemptLimit;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationInvitationRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class OrganisationInvitationsTest extends TestCase
{
    private TestPorts $env;
    private ApiKernel $api;
    private RecordingInvitationDelivery $delivery;
    private OrgJoinRuleRegistry $joinRules;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }
        $this->env->authorization->grant(1, Capability::MANAGE);
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);
        $roles = $registries->orgRoles;
        $this->joinRules = $registries->orgJoinRules;
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $roles);
        $members = new ManageOrganisationMembers(
            new OrganisationAccess($access),
            $store,
            $roles,
            $this->env->users,
            $this->env->translator,
        );
        $this->api = new ApiKernel(new NullLogger());
        $idem = new IdempotentExecution(new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
            $this->env->clock,
        ));
        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations($access, $store, $this->env->users, $roles, $registries->orgTypes),
            $members,
            $access,
            $idem,
            $this->env->database
        );
        $this->delivery = new RecordingInvitationDelivery();
        $invitations = new ManageOrganisationInvitations(
            $access,
            $store,
            $members,
            $roles,
            $this->env->users,
            $this->env->clock,
            $this->env->random,
            $this->delivery,
            new InvitationAttemptLimit($this->env->database, $this->env->clock),
            $registries->orgJoinRules,
            $registries->invitationMatch,
            new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock))
        );
        OrganisationInvitationRoutes::register($this->api, $invitations, $access, $idem, $this->env->database);
        self::assertSame(201, $this->request('POST', '/manage/organisations', [
            'name' => 'Synthetic Organisation', 'slug' => 'synthetic',
        ])->status);
    }

    public function testFreshMailboxProofJoinsAnAuthenticatedAccountWithoutGrantingSiteCapabilitiesOrEnrolment(): void
    {
        $this->issue();
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
        $claim = $this->claim();
        self::assertSame(201, $claim->status);
        self::assertSame('queued', $claim->data['delivery'] ?? null);
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
        $accepted = $this->accept($this->claimId($claim));
        self::assertSame(200, $accepted->status);
        self::assertSame('active', $accepted->data['state'] ?? null);
        $member = $this->env->database->selectOne(Query::from('org_memberships')->where('user_id', '=', 2));
        self::assertIsArray($member);
        self::assertSame('invitation', $member['provisioned_by']);
        self::assertFalse($this->env->authorization->can(2, Capability::MANAGE));
        self::assertFalse($this->env->authorization->can(2, Capability::MANAGE_GROUPS));
        self::assertSame(0, $this->env->database->count(Query::from('enrolments')));
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));
        self::assertSame(404, $this->accept($this->claimId($claim))->status);
        $row = $this->env->database->selectOne(Query::from('invitations'));
        self::assertIsArray($row);
        self::assertNull($row['token_hash']);
        self::assertNull($row['pending_key']);
    }

    /**
     * The join rule is a registration and the acceptance route reaches it.
     *
     * Acceptance used to be the rule; now it asks the rule. This replaces the
     * free provider with one that refuses and drives the same route to the
     * same non-enumerating failure, which is the only way to show that the
     * seam is load bearing rather than decorative: a registry nothing consults
     * is a hardcoded rule with an interface in front of it.
     */
    public function testTheAcceptanceRouteAsksTheRegisteredJoinRuleAndRefusesWhenItSaysNo(): void
    {
        $this->issue();
        $claim = $this->claim();
        self::assertSame(201, $claim->status);

        $this->joinRules->replace(new class implements OrgJoinRuleInterface {
            public function key(): string
            {
                return 'invitation_only';
            }

            public function label(): string
            {
                return 'org.join_rule.invitation_only';
            }

            public function admits(OrgJoinContext $context): bool
            {
                return false;
            }
        });

        // The same 404 an unknown token answers, so a refused join tells the
        // caller nothing about whether the invitation existed.
        $refused = $this->accept($this->claimId($claim));
        self::assertSame(404, $refused->status);
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
        self::assertSame('pending', $this->env->database->selectOne(Query::from('invitations'))['state'] ?? null);
    }

    public function testForwardedLinkAndEditableAccountEmailAreNotProofOfMailboxOwnership(): void
    {
        $this->issue();
        $token = $this->delivery->notices[0]['secret'];
        self::assertSame(404, $this->request('POST', '/me/organisation-invitations/claim', ['token' => $token], 3)->status);
        $this->env->users->add(3, 'Synthetic Forwarded Link Holder', 'user2@example.test');
        $claim = $this->request('POST', '/me/organisation-invitations/claim', ['token' => $token], 3);
        self::assertIsArray($claim->data);
        self::assertSame(201, $claim->status);
        self::assertSame('user2@example.test', $this->delivery->notices[1]['email']);
        self::assertSame(404, $this->request('POST', '/me/organisation-invitations/accept', [
            'claim_id' => $this->claimId($claim), 'code' => 'NOTTHEPROOF',
        ], 3)->status);
        // Even the correct proof is useless under a different authenticated account.
        self::assertSame(404, $this->request('POST', '/me/organisation-invitations/accept', [
            'claim_id' => $this->claimId($claim), 'code' => $this->delivery->notices[1]['secret'],
        ], 2)->status);
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
    }

    public function testAccountAddressChangeInvalidatesProof(): void
    {
        $this->issue();
        $claim = $this->claim();
        $this->env->users->add(2, 'Synthetic User 2', 'changed@example.test');
        self::assertSame(404, $this->accept($this->claimId($claim))->status);
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
    }

    public function testReissueRotatesSamePendingRowAndInvalidatesPriorTokensAndClaims(): void
    {
        $this->issue();
        $oldToken = $this->delivery->notices[0]['secret'];
        $claim = $this->claim();
        $oldCode = $this->delivery->notices[1]['secret'];
        $reissued = $this->issue(2);
        self::assertSame(['organisation_revision' => 3, 'outcome' => 'queued'], $reissued->data);
        $stored = $this->env->database->selectOne(Query::from('invitations'));
        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['id']);
        self::assertSame(2, (int) $stored['revision']);
        self::assertSame(1, $this->env->database->count(Query::from('invitations')));
        self::assertNotSame($oldToken, $this->delivery->notices[2]['secret']);
        self::assertSame(404, $this->request('POST', '/me/organisation-invitations/claim', ['token' => $oldToken], 2)->status);
        self::assertSame(404, $this->request('POST', '/me/organisation-invitations/accept', [
            'claim_id' => $this->claimId($claim), 'code' => $oldCode,
        ], 2)->status);
    }

    /**
     * The three routes whose use case takes scalars rather than a body used to
     * ignore an unknown key, while the contract's own rule and its
     * OrganisationRevisionRequest schema both said the surface refuses one.
     * Claim and accept are the only organisation routes a caller with no
     * membership can reach, which makes them the worst place to be lenient.
     */
    public function testAnUnknownKeyIsRefusedOnTheClaimAcceptAndRevokeRoutes(): void
    {
        $this->issue();
        $claim = $this->claim();

        $revoke = $this->request('POST', '/manage/organisations/1/invitations/1/revoke', [
            'expected_revision' => 2, 'state' => 'active',
        ]);
        $claimed = $this->request('POST', '/me/organisation-invitations/claim', [
            'token' => str_repeat('a', 64), 'organisation_id' => 1,
        ], 2);
        $accepted = $this->request('POST', '/me/organisation-invitations/accept', [
            'claim_id' => $this->claimId($claim), 'code' => 'ABCDEFGHIJKL', 'role_key' => 'org_owner',
        ], 2);

        self::assertSame(422, $revoke->status);
        self::assertSame(422, $claimed->status);
        self::assertSame(422, $accepted->status);
        self::assertSame('This field is not supported by this operation.', $revoke->data['errors']['state'] ?? null);
        self::assertSame(
            'This field is not supported by this operation.',
            $claimed->data['errors']['organisation_id'] ?? null,
        );
        self::assertSame('This field is not supported by this operation.', $accepted->data['errors']['role_key'] ?? null);

        // The named keys still pass, so the fold is a filter and not a wall.
        self::assertSame(200, $this->request('POST', '/manage/organisations/1/invitations/1/revoke', [
            'expected_revision' => 2,
        ])->status);
    }

    public function testRevocationAndExpiryUseSameNonEnumeratingFailureAsUnknownToken(): void
    {
        $this->issue();
        $claim = $this->claim();
        self::assertSame(200, $this->request('POST', '/manage/organisations/1/invitations/1/revoke', [
            'expected_revision' => 2,
        ])->status);
        $revoked = $this->claim();
        $unknown = $this->request('POST', '/me/organisation-invitations/claim', ['token' => 'UNKNOWN'], 2);
        self::assertSame($unknown->data, $revoked->data);
        self::assertSame(404, $this->accept($this->claimId($claim))->status);
        $this->issue(3);
        $this->env->database->update('invitations', ['expires_at' => '2000-01-01 00:00:00'], ['id' => 2]);
        $expired = $this->request('POST', '/me/organisation-invitations/claim', [
            'token' => $this->delivery->notices[2]['secret'],
        ], 2);
        self::assertSame($unknown->data, $expired->data);
        $list = $this->request('GET', '/manage/organisations/1/invitations', query: ['state' => 'expired']);
        self::assertSame(1, $list->data['total'] ?? null);
        self::assertSame('expired', $list->data['items'][0]['state']);
    }

    public function testExpiredProofAndSuspendedOrganisationCannotJoin(): void
    {
        $this->issue();
        $claim = $this->claim();
        $this->env->database->update(
            'org_invitation_claims',
            ['expires_at' => '2000-01-01 00:00:00'],
            ['id' => $this->claimId($claim)]
        );
        self::assertSame(404, $this->accept($this->claimId($claim))->status);
        self::assertSame(200, $this->request('PATCH', '/manage/organisations/1', [
            'expected_revision' => 2, 'state' => 'suspended',
        ])->status);
        self::assertSame(404, $this->claim()->status);
    }

    public function testQueueFailureRollsBackInvitationRevisionAuditAndIdempotencyReceipt(): void
    {
        $this->delivery->fail = true;
        $body = ['expected_revision' => 1, 'email' => 'user2@example.test', 'role_key' => 'org_member'];
        self::assertSame(500, $this->request('POST', '/manage/organisations/1/invitations', $body, key: 'retry-queue')->status);
        self::assertSame(0, $this->env->database->count(Query::from('invitations')));
        self::assertSame(1, $this->env->database->selectOne(Query::from('organisations'))['revision'] ?? null);
        self::assertSame(1, $this->env->database->count(Query::from('org_membership_events')));
        $this->delivery->fail = false;
        self::assertSame(201, $this->request('POST', '/manage/organisations/1/invitations', $body, key: 'retry-queue')->status);
        self::assertCount(1, $this->delivery->notices);
    }

    public function testClaimQueueFailureLeavesNoProofClaimOrSuccessReceipt(): void
    {
        $this->issue();
        $this->delivery->fail = true;
        self::assertSame(500, $this->claim()->status);
        self::assertSame(0, $this->env->database->count(Query::from('org_invitation_claims')));
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
    }

    public function testFailedProofAttemptsConsumeDurableRateLimitsDespiteTransactionRollback(): void
    {
        $this->issue();
        $claim = $this->claim();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(404, $this->request('POST', '/me/organisation-invitations/accept', [
                'claim_id' => $this->claimId($claim), 'code' => 'WRONG',
            ], 2)->status);
        }
        self::assertSame(429, $this->accept($this->claimId($claim))->status);
        self::assertSame(1, $this->env->database->count(Query::from('org_memberships')));
    }

    public function testIdempotentInvitationAndProofRetryDoesNotQueueTwiceAndSecretsAreNotInPublicOrAuditData(): void
    {
        $body = ['expected_revision' => 1, 'email' => 'user2@example.test', 'role_key' => 'org_member'];
        $first = $this->request('POST', '/manage/organisations/1/invitations', $body, key: 'same-invite');
        $second = $this->request('POST', '/manage/organisations/1/invitations', $body, key: 'same-invite');
        self::assertSame($first->data, $second->data);
        self::assertCount(1, $this->delivery->notices);
        $token = $this->delivery->notices[0]['secret'];
        $firstClaim = $this->request('POST', '/me/organisation-invitations/claim', ['token' => $token], 2, 'same-claim');
        $secondClaim = $this->request('POST', '/me/organisation-invitations/claim', ['token' => $token], 2, 'same-claim');
        self::assertSame($firstClaim->data, $secondClaim->data);
        self::assertCount(2, $this->delivery->notices);
        $proof = $this->delivery->notices[1]['secret'];
        foreach (['invitations', 'org_invitation_claims', 'org_membership_events', 'idempotency_keys'] as $table) {
            $stored = json_encode($this->env->database->selectAll(Query::from($table)), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($token, $stored);
            self::assertStringNotContainsString($proof, $stored);
        }
        $public = json_encode($this->request('GET', '/manage/organisations/1/invitations')->data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('token_hash', $public);
        self::assertStringNotContainsString('pending_key', $public);
    }

    public function testInvitingActiveMemberHasTheSameReceiptWithoutEmailOrRoleEscalation(): void
    {
        self::assertSame(201, $this->request('POST', '/manage/organisations/1/members', [
            'expected_revision' => 1, 'user_id' => 2, 'role_key' => 'org_member',
        ])->status);
        $response = $this->request('POST', '/manage/organisations/1/invitations', [
            'expected_revision' => 2, 'email' => 'user2@example.test', 'role_key' => 'org_owner',
        ]);
        self::assertSame('queued', $response->data['outcome'] ?? null);
        self::assertArrayNotHasKey('membership_id', $response->data);
        self::assertSame(['organisation_revision' => 3, 'outcome' => 'queued'], $response->data);
        self::assertCount(0, $this->delivery->notices);
        self::assertSame(0, $this->env->database->count(Query::from('invitations')));
        self::assertSame(1, $this->env->database->count(Query::from('org_role_assignments')->where('role_key', '=', 'org_owner')));
        $newAddress = $this->request('POST', '/manage/organisations/1/invitations', [
            'expected_revision' => 3, 'email' => 'new@example.test', 'role_key' => 'org_member',
        ]);
        self::assertSame($response->status, $newAddress->status);
        self::assertSame(['organisation_revision' => 4, 'outcome' => 'queued'], $newAddress->data);
    }

    public function testWrongAccountCannotExhaustAnotherAccountsNumericProofClaim(): void
    {
        $this->issue();
        $claim = $this->claim();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(404, $this->request('POST', '/me/organisation-invitations/accept', [
                'claim_id' => $this->claimId($claim), 'code' => 'WRONG',
            ], 3)->status);
        }
        self::assertSame(200, $this->accept($this->claimId($claim))->status);
    }

    private function issue(int $revision = 1): ApiResponse
    {
        $response = $this->request('POST', '/manage/organisations/1/invitations', [
            'expected_revision' => $revision, 'email' => 'user2@example.test', 'role_key' => 'org_member',
        ]);
        self::assertSame(201, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));

        return $response;
    }

    private function claim(): ApiResponse
    {
        $response = $this->request('POST', '/me/organisation-invitations/claim', ['token' => $this->delivery->notices[0]['secret']], 2);
        self::assertIsArray($response->data);

        return $response;
    }

    private function claimId(ApiResponse $response): int
    {
        $id = $response->data['claim_id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function accept(int $claim): ApiResponse
    {
        return $this->request('POST', '/me/organisation-invitations/accept', [
            'claim_id' => $claim, 'code' => $this->delivery->notices[1]['secret'],
        ], 2);
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
            Principal::user($actor),
            $query,
            $body,
            $key ?? 'invite-test-' . ++$this->sequence,
            clientAddress: '192.0.2.10'
        ));
    }
}
