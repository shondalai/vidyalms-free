<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class OrganisationPrivacyTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $orgA;
    private int $orgB;
    private int $memberA;
    private int $memberB;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->orgA = $this->organisation('north');
        $this->orgB = $this->organisation('south');
        $this->memberA = $this->member($this->orgA);
        $this->memberB = $this->member($this->orgB);
    }

    public function testAuditCsvIsTenantAnchoredPagedStableAndNeverReturnsPayloads(): void
    {
        $db = $this->fixture->env->database;
        for ($i = 0; $i < 203; $i++) {
            $db->insert('org_membership_events', ['organisation_id' => $this->orgA, 'actor_id' => 1, 'subject_id' => 50,
                'event_key' => $i === 100 ? '=WEBSERVICE("bad")' : 'fixture.row', 'organisation_revision' => 2,
                'details' => '{"private":"never-export-this"}', 'created_at' => '2026-01-15 00:00:00',
                'updated_at' => '2026-01-15 00:00:00']);
        }
        $response = $this->get($this->orgA, '/events/export', ['page' => '2', 'per_page' => '100']);
        self::assertSame(200, $response->status);
        self::assertTrue($response->isBinary());
        self::assertSame('205', $response->headers['X-Total-Count']);
        self::assertSame('private, no-store', $response->headers['Cache-Control']);
        $csv = $response->binaryBytes();
        self::assertStringContainsString("'=WEBSERVICE", $csv);
        self::assertStringNotContainsString('never-export-this', $csv);
        self::assertCount(102, explode("\r\n", $csv));
        $next = $this->get($this->orgA, '/events/export', ['page' => '3', 'per_page' => '100',
            'snapshot_id' => $response->headers['X-Snapshot-Id']]);
        self::assertSame('205', $next->headers['X-Total-Count']);
        self::assertCount(7, explode("\r\n", $next->binaryBytes()));
    }

    public function testPrivacyExportUsesTheRelationshipAndDoesNotLeakProofsOrOtherOrganizations(): void
    {
        $result = $this->get($this->orgA, '/privacy/export', ['record_class' => 'memberships', 'per_page' => '1', 'page' => '2']);
        self::assertSame(200, $result->status, json_encode($result->data, JSON_THROW_ON_ERROR));
        $data = self::data($result);
        self::assertSame(2, $data['total']);
        self::assertCount(1, $data['items']);
        self::assertSame($this->orgA, (int) $data['items'][0]['organisation_id']);
        self::assertArrayNotHasKey('source_ref', $data['items'][0]);
        self::assertTrue($data['complete']);
        $roles = $this->get($this->orgA, '/privacy/export', ['record_class' => 'roles']);
        self::assertSame(200, $roles->status, json_encode($roles->data, JSON_THROW_ON_ERROR));
        self::assertCount(2, self::data($roles)['items']);
    }

    public function testErasureRevokesOnlyItsGrantAndPreservesSharedLearningAndAuditEvidence(): void
    {
        $own = $this->grant($this->orgA, $this->memberA);
        $other = $this->grant($this->orgB, $this->memberB);
        $db = $this->fixture->env->database;
        $before = $db->selectAll(Query::from('enrolments')->where('user_id', '=', 50));
        $events = $db->selectAll(Query::from('org_membership_events')->where('organisation_id', '=', $this->orgA)->orderBy('id'));
        $response = $this->erase($this->orgA, $this->memberA, 'erase-relationship');
        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        $data = self::data($response);
        self::assertSame('relationship_erased', $data['state']);
        self::assertFalse($data['erasure_complete']);
        self::assertSame(1, $data['receipts']['core-organisations']['retained']['entitlements']);
        self::assertSame('revoked', $this->row('entitlements', $own)['status']);
        self::assertSame('active', $this->row('entitlements', $other)['status']);
        self::assertSame('active', $this->row('entitlements', $this->fixture->entitlementId)['status']);
        self::assertSame(0, (int) $this->row('org_memberships', $this->memberA)['user_id']);
        self::assertSame(50, (int) $this->row('org_memberships', $this->memberB)['user_id']);
        self::assertSame($before, $db->selectAll(Query::from('enrolments')->where('user_id', '=', 50)));
        // The event stream survives whole, with the subject pseudonymised and
        // nothing else touched. That is the design's resolution of the ledger
        // versus erasure tension: the row is RETAINED under legal_obligation
        // with the member identity removed, rather than deleted or left naming
        // them. Every other column of every row is asserted byte for byte, so a
        // pseudonymisation that quietly rewrote anything else fails here.
        $after = $db->selectAll(Query::from('org_membership_events')->where('organisation_id', '=', $this->orgA)
            ->where('event_key', '!=', 'privacy.relationship_erased')->orderBy('id'));
        $pseudonymise = static function (array $row): array {
            // Only rows about the erased person move, and only in that one
            // column. A row about the administrator who acted is untouched.
            $row['subject_id'] = (int) $row['subject_id'] === 50 ? 0 : $row['subject_id'];

            return $row;
        };
        self::assertSame(array_map($pseudonymise, $events), $after);
        self::assertNotSame($events, $after, 'the erased person was named in at least one event');
        // And the receipt for it exists, keyed on the organisation, naming the
        // basis and holding no user id at all.
        $receipts = $db->selectAll(Query::from('org_ledger_redactions')
            ->where('organisation_id', '=', $this->orgA)->orderBy('id'));
        self::assertNotSame([], $receipts);
        self::assertSame('legal_obligation', (string) $receipts[0]['basis']);
        self::assertSame(64, \strlen((string) $receipts[0]['subject_token']));
        self::assertArrayNotHasKey('user_id', $receipts[0]);
        self::assertSame(
            $data['receipts']['core-organisations']['retention_bases']['seat_events']['basis'],
            'legal_obligation',
        );
        self::assertArrayHasKey('untouched_other_organisation', $data['receipts']['core-organisations']);
        $replay = $this->fixture->call(
            'POST',
            '/manage/organisations/' . $this->orgA . '/members/' . $this->memberA . '/erase',
            $this->fixture->manager(),
            ['expected_revision' => 2, 'acknowledgement' => 'remove_organization_relationship'],
            idempotencyKey: 'erase-relationship'
        );
        self::assertSame(200, $replay->status);
        self::assertSame($data, $replay->data);
    }

    public function testMissingContributorMarksExportIncompleteAndStopsErasureBeforeAnyWrite(): void
    {
        $this->fixture->kernel->featureProviders->record('privacy_contributor', 'missing-package', 'removed-package');
        self::assertFalse(self::data($this->get($this->orgA, '/privacy/export'))['complete']);
        $response = $this->erase($this->orgA, $this->memberA, 'missing-package');
        self::assertSame(409, $response->status);
        self::assertSame(50, (int) $this->row('org_memberships', $this->memberA)['user_id']);
        self::assertSame(2, $this->revision($this->orgA));
    }

    public function testCurrentScopeAndTargetAreCheckedBeforeErasureReplayAndEveryExport(): void
    {
        $this->fixture->env->authorization->grant(10, Capability::MANAGE_GROUPS);
        foreach (['/privacy', '/privacy/export', '/events/export'] as $suffix) {
            self::assertSame(404, $this->fixture->call(
                'GET',
                '/manage/organisations/' . $this->orgA . $suffix,
                $this->fixture->teacher()
            )->status);
            self::assertSame(401, $this->fixture->call(
                'GET',
                '/manage/organisations/' . $this->orgA . $suffix,
                Principal::guest()
            )->status);
        }
        self::assertSame(404, $this->erase($this->orgA, $this->memberB, 'foreign-member')->status);
        self::assertSame(422, $this->get($this->orgA, '/privacy/export', ['per_page' => '101'])->status);
        self::assertSame(422, $this->get($this->orgA, '/events/export', ['event_key' => '%'])->status);
    }

    public function testSubjectContributorExportIncludesBothOwnMembershipsAndRequiresErasureReview(): void
    {
        $service = new SubjectOrganisationPrivacy($this->fixture->kernel->registries->privacyContributors, $this->fixture->env->database);
        $groups = $service->groups(50, 'lena@example.test');
        $counts = [];
        foreach ($groups as $group) {
            $rows = $this->fixture->env->database->selectAll(($group->query)()->orderBy('id')->limit(200));
            $counts[$group->key] = \count($rows);
            self::assertStringNotContainsString('token_hash', json_encode($rows, JSON_THROW_ON_ERROR));
        }
        self::assertSame(2, $counts['vidyalms-org-memberships']);
        self::assertSame(2, $counts['vidyalms-org-roles']);
        self::assertTrue($service->requiresReview(50, 'lena@example.test'));
        self::assertFalse($service->requiresReview(999, 'none@example.test'));
    }

    public function testLargeCleanupIsDurableAndCannotBeReportedAsErasedOrQueuedTwice(): void
    {
        for ($i = 0; $i < 501; $i++) {
            $this->grant($this->orgA, $this->memberA);
        }
        $response = $this->erase($this->orgA, $this->memberA, 'large-erasure');
        self::assertSame(202, $response->status);
        $data = self::data($response);
        self::assertSame('cleanup_queued', $data['state']);
        self::assertNull($data['receipts']);
        self::assertFalse($data['erasure_complete']);
        self::assertSame('left', $this->row('org_memberships', $this->memberA)['state']);
        self::assertSame(50, (int) $this->row('org_memberships', $this->memberA)['user_id']);
        self::assertSame($this->orgA, (int) $this->row('org_access_termination_jobs', (int) $data['cleanup']['job_id'])['organisation_id']);
        self::assertSame(409, $this->erase($this->orgA, $this->memberA, 'duplicate-large-erasure')->status);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('org_access_termination_jobs')));
    }

    public function testErasureInvalidatesOnlyThisOrganizationsPendingInvitationAndProofs(): void
    {
        $db = $this->fixture->env->database;
        $ids = [];
        foreach ([$this->orgA, $this->orgB] as $orgId) {
            $ids[$orgId] = $db->insert('invitations', ['organisation_id' => $orgId, 'email' => 'lena@example.test',
                'role_key' => 'org_member', 'state' => 'pending', 'token_hash' => hash('sha256', 'token-' . $orgId),
                'pending_key' => hash('sha256', 'pending-' . $orgId), 'issued_by' => 1, 'expires_at' => '2027-01-01 00:00:00',
                'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00']);
            $db->insert('org_invitation_claims', ['organisation_id' => $orgId, 'invitation_id' => $ids[$orgId],
                'invitation_revision' => 1, 'user_id' => 50, 'email_hash' => hash('sha256', 'lena@example.test'),
                'proof_hash' => hash('sha256', 'proof-' . $orgId), 'expires_at' => '2027-01-01 00:00:00',
                'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00']);
        }
        $export = $this->get($this->orgA, '/privacy/export', ['record_class' => 'invitations']);
        self::assertSame(200, $export->status);
        self::assertArrayNotHasKey('token_hash', self::data($export)['items'][0]);
        self::assertSame(200, $this->erase($this->orgA, $this->memberA, 'invitation-erasure')->status);
        self::assertNull($this->row('invitations', $ids[$this->orgA])['token_hash']);
        self::assertSame('', $this->row('invitations', $ids[$this->orgA])['email']);
        self::assertSame('pending', $this->row('invitations', $ids[$this->orgB])['state']);
        self::assertSame(0, $db->count(Query::from('org_invitation_claims')->where('organisation_id', '=', $this->orgA)));
        self::assertSame(1, $db->count(Query::from('org_invitation_claims')->where('organisation_id', '=', $this->orgB)));
    }

    public function testContributorFailureRollsBackEarlierRevocationAndIdentityWrites(): void
    {
        $grant = $this->grant($this->orgA, $this->memberA);
        $this->fixture->kernel->registries->privacyContributors->register(new class implements
            \Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface
        {
            public function key(): string
            {
                return 'failing-provider';
            }
            public function describes(): array
            {
                return [];
            }
            public function subjectGroups(int $userId, string $email): array
            {
                return [];
            }
            public function exportFor(
                \Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext $context,
                string $recordClass,
                int $page,
                int $perPage
            ): array { return ['items' => [], 'total' => 0];
            }
            public function eraseFor(\Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext $context): array
            {
                throw new \RuntimeException('Synthetic contributor failure.');
            }
        });
        self::assertSame(500, $this->erase($this->orgA, $this->memberA, 'rollback-erasure')->status);
        self::assertSame('active', $this->row('entitlements', $grant)['status']);
        self::assertSame(50, (int) $this->row('org_memberships', $this->memberA)['user_id']);
        self::assertSame('active', $this->row('org_memberships', $this->memberA)['state']);
        self::assertSame(2, $this->revision($this->orgA));
    }

    public function testErasureReplayDoesNotSurviveLossOfTheDelegatedOwnerScope(): void
    {
        $this->fixture->env->authorization->grant(10, Capability::MANAGE_GROUPS);
        $owner = $this->fixture->kernel->organisationMembers->add(
            $this->fixture->manager(),
            $this->orgA,
            ['expected_revision' => $this->revision($this->orgA), 'user_id' => 10, 'role_key' => 'org_owner']
        );
        $body = ['expected_revision' => $this->revision($this->orgA), 'acknowledgement' => 'remove_organization_relationship'];
        $path = '/manage/organisations/' . $this->orgA . '/members/' . $this->memberA . '/erase';
        $result = $this->fixture->call('POST', $path, $this->fixture->teacher(), $body, idempotencyKey: 'owner-replay');
        self::assertSame(200, $result->status);
        $this->fixture->kernel->organisationMembers->update(
            $this->fixture->manager(),
            $this->orgA,
            (int) $owner['item']['id'],
            ['expected_revision' => $this->revision($this->orgA), 'state' => 'suspended']
        );
        self::assertSame(404, $this->fixture->call(
            'POST',
            $path,
            $this->fixture->teacher(),
            $body,
            idempotencyKey: 'owner-replay'
        )->status);
    }

    public function testStaleRevisionAndLastOwnerErasureAreRefusedWithoutMutation(): void
    {
        $owner = $this->fixture->env->database->selectOne(Query::from('org_memberships')
            ->where('organisation_id', '=', $this->orgA)->where('user_id', '=', 1));
        self::assertIsArray($owner);
        self::assertSame(409, $this->erase($this->orgA, (int) $owner['id'], 'last-owner')->status);
        $response = $this->fixture->call(
            'POST',
            '/manage/organisations/' . $this->orgA . '/members/' . $this->memberA . '/erase',
            $this->fixture->manager(),
            ['expected_revision' => 1, 'acknowledgement' => 'remove_organization_relationship'],
            idempotencyKey: 'stale-erasure'
        );
        self::assertSame(409, $response->status);
        self::assertSame(2, $this->revision($this->orgA));
    }

    private function organisation(string $slug): int
    {
        $row = $this->fixture->kernel->organisations->create($this->fixture->manager(), ['name' => $slug, 'slug' => $slug]);
        return (int) $row['id'];
    }

    private function member(int $org): int
    {
        $result = $this->fixture->kernel->organisationMembers->add(
            $this->fixture->manager(),
            $org,
            ['expected_revision' => $this->revision($org), 'user_id' => 50, 'role_key' => 'org_member']
        );
        return (int) $result['item']['id'];
    }

    private function revision(int $org): int
    {
        return (int) $this->fixture->kernel->organisations->get($this->fixture->manager(), $org)['revision'];
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $id): array
    {
        $row = $this->fixture->env->database->selectOne(Query::from($table)->where('id', '=', $id));
        self::assertIsArray($row);
        return $row;
    }

    private function grant(int $org, int $member): int
    {
        return $this->fixture->env->database->insert('entitlements', ['organisation_id' => $org, 'org_membership_id' => $member,
            'course_id' => $this->fixture->courseId, 'user_id' => 50, 'source' => 'org', 'status' => 'active',
            'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00']);
    }

    /** @param array<string, string> $query */
    private function get(int $org, string $suffix, array $query = []): ApiResponse
    {
        return $this->fixture->call('GET', '/manage/organisations/' . $org . $suffix, $this->fixture->manager(), query: $query);
    }

    private function erase(int $org, int $member, string $key): ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/manage/organisations/' . $org . '/members/' . $member . '/erase',
            $this->fixture->manager(),
            ['expected_revision' => $this->revision($org),
            'acknowledgement' => 'remove_organization_relationship'],
            idempotencyKey: $key
        );
    }

    /** @return array<string, mixed> */
    private static function data(ApiResponse $response): array
    {
        self::assertIsArray($response->data);
        return $response->data;
    }
}
