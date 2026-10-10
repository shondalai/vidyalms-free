<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AccessLogRetentionTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogQuery;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessRecord;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The admin access log: the table, its chain, its anchors and its sweep.
 *
 * The reason this exists before any report over it is the one the design
 * gives and this suite pins: a chain started later cannot cover the rows
 * written before it, so the value of the whole record depends on the first
 * row being chained.
 */
final class AdminAccessLogTest extends TestCase
{
    private TestPorts $env;
    private AdminAccessLogStore $log;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->log = new AdminAccessLogStore($this->env->database, $this->env->clock);
    }

    public function testTheTableCarriesTheSixAuditElementsBothOrganisationAnchorsAndTheChain(): void
    {
        $table = SchemaDefinition::load()->table('admin_access_log');

        // The six NIST SP 800-53 AU-3 elements.
        foreach (['event_type', 'occurred_at', 'route', 'outcome', 'actor_id', 'subject_user_id'] as $column) {
            self::assertTrue($table->hasColumn($column), $column);
        }

        // The two organisation anchors, the chain, and the rest of the record.
        foreach (
            ['actor_org_id', 'subject_org_id', 'actor_role_key', 'resource', 'record_count',
                'scope_digest', 'request_ref', 'ip_hash', 'prev_hash', 'hash'] as $column
        ) {
            self::assertTrue($table->hasColumn($column), $column);
        }

        self::assertFalse($table->hasColumn('updated_at'), 'An append-only row may not advertise an updated_at.');
        self::assertFalse($table->hasColumn('created_at'), 'occurred_at is the only instant this row has.');
        self::assertSame(64, $table->column('hash')->length);

        $indexed = array_map(static fn ($index): array => $index->columns, $table->indexes);
        self::assertContains(['actor_org_id', 'occurred_at'], $indexed);
        self::assertContains(['subject_org_id', 'occurred_at'], $indexed);
    }

    public function testEveryRowIsChainedToTheOneBeforeItAndTheFirstOneToTheGenesisHash(): void
    {
        $scope = $this->scope(2, 1);

        $first = $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/manage/organisations/1/members', 3));
        $second = $this->log->record(AdminAccessRecord::allowed('org.seats.read', $scope, '/manage/organisations/1/seats', 1));

        $rows = $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'));
        self::assertCount(2, $rows);
        self::assertSame(AdminAccessLogStore::GENESIS, $rows[0]['prev_hash']);
        self::assertSame($rows[0]['hash'], $rows[1]['prev_hash']);
        self::assertNotSame($rows[0]['hash'], $rows[1]['hash']);
        self::assertSame([$first, $second], [(int) $rows[0]['id'], (int) $rows[1]['id']]);

        $verified = $this->log->verify();
        self::assertSame(2, $verified['checked']);
        self::assertSame([], $verified['breaks']);
        self::assertFalse($verified['pruned']);
    }

    public function testATamperedRowIsDetected(): void
    {
        $scope = $this->scope(2, 1);
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/manage/organisations/1/members', 3));
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/manage/organisations/1/members', 40));

        // The edit an administrator covering their tracks would make: the
        // count of learner rows they read, changed to a smaller number.
        $this->env->database->update('admin_access_log', ['record_count' => 1], ['id' => 2]);

        $verified = $this->log->verify();
        self::assertSame(2, $verified['checked']);
        self::assertSame([['id' => 2, 'reason' => 'content']], $verified['breaks']);
    }

    public function testARowRemovedFromTheMiddleBreaksTheLinkWhileAPrunedPrefixDoesNot(): void
    {
        $scope = $this->scope(2, 1);
        foreach (['a', 'b', 'c'] as $route) {
            $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/' . $route, 1));
        }

        $this->env->database->delete('admin_access_log', ['id' => 2]);
        $broken = $this->log->verify();
        self::assertSame([['id' => 3, 'reason' => 'link']], $broken['breaks']);
        self::assertFalse($broken['pruned']);

        // The retention sweep removes a prefix, which is the one deletion the
        // design permits, and it is reported as pruned rather than as a break.
        $this->env->database->delete('admin_access_log', ['id' => 1]);
        $pruned = $this->log->verify();
        self::assertSame([], $pruned['breaks']);
        self::assertTrue($pruned['pruned']);
    }

    public function testTheDefaultVerificationWalksTheWholeTableAndNotOnlyItsOldestPage(): void
    {
        $scope = $this->scope(2, 1);

        for ($i = 0; $i < 1050; $i++) {
            $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/r' . $i, 1));
        }

        // The row an administrator covering recent tracks would edit: past the
        // thousand the first draft read, which answered "intact" about it.
        $this->env->database->update('admin_access_log', ['record_count' => 999999], ['id' => 1040]);

        $verified = $this->log->verify();
        self::assertSame(1050, $verified['checked']);
        self::assertSame(1050, $verified['total']);
        self::assertTrue($verified['complete']);
        self::assertSame([['id' => 1040, 'reason' => 'content']], $verified['breaks']);
    }

    public function testAWalkTheCallerDeliberatelyCappedSaysItIsIncomplete(): void
    {
        $scope = $this->scope(2, 1);

        foreach (range(1, 5) as $i) {
            $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/r' . $i, 1));
        }

        $capped = $this->log->verify(2, 4);
        self::assertSame(4, $capped['checked']);
        self::assertSame(5, $capped['total']);
        self::assertFalse($capped['complete'], 'a truncated walk may never read as a clean one');
    }

    public function testTwoConcurrentAppendsAreReportedAsAForkAndNotAsADeletion(): void
    {
        $scope = $this->scope(2, 1);
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/first', 1));
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/second', 1));

        // The second of two console reads that arrived together: it read the
        // same head the first one did, so both rows carry one prev_hash.
        $rows = $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'));
        $racing = AdminAccessLogStore::numbers([
            'event_type' => 'org.members.read',
            'occurred_at' => '2026-01-01 09:00:00',
            'actor_id' => 2,
            'actor_org_id' => 1,
            'actor_role_key' => 'org_admin',
            'subject_user_id' => null,
            'subject_org_id' => 1,
            'resource' => 'org:1',
            'route' => '/racing',
            'outcome' => 'allowed',
            'record_count' => 1,
            'scope_digest' => null,
            'request_ref' => null,
            'ip_hash' => null,
            'prev_hash' => (string) $rows[0]['hash'],
        ]);
        $racing['hash'] = AdminAccessLogStore::digest($racing);
        $this->env->database->insert('admin_access_log', $racing);

        $forked = $this->log->verify();
        self::assertSame([['id' => 3, 'reason' => 'fork']], $forked['breaks']);

        // The same reason must not be what a deleted row produces, or the
        // action that silences the alarm is deleting audit rows.
        $this->env->database->delete('admin_access_log', ['id' => 3]);
        $this->env->database->delete('admin_access_log', ['id' => 1]);
        $this->env->database->insert('admin_access_log', ['id' => 9] + $racing);
        $deleted = $this->log->verify();
        self::assertSame([['id' => 9, 'reason' => 'link']], $deleted['breaks']);
    }

    public function testDeletingTheNewestRowsLeavesAChainThatOnlyTheAnchorCatches(): void
    {
        $log = $this->anchoredLog();
        $scope = $this->scope(2, 1);

        foreach (range(1, 4) as $i) {
            $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/r' . $i, 1));
        }

        self::assertSame([], $log->verify()['breaks']);

        $this->env->database->delete('admin_access_log', ['id' => 4]);
        $verified = $log->verify();

        self::assertSame(3, $verified['checked']);
        self::assertSame(AdminAccessLogStore::ANCHOR_MISMATCH, $verified['anchor']);
        self::assertContains(['id' => 0, 'reason' => 'missing'], $verified['breaks']);
        self::assertContains(['id' => 3, 'reason' => 'head'], $verified['breaks']);
    }

    public function testDeletingAnOldPrefixIsNotAcceptedAsTheRetentionSweep(): void
    {
        $log = $this->anchoredLog();
        $scope = $this->scope(2, 1);

        foreach (range(1, 4) as $i) {
            $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/r' . $i, 1));
        }

        $this->env->database->delete('admin_access_log', ['id' => 1]);
        $verified = $log->verify();

        self::assertTrue($verified['pruned'], 'the chain alone still reads this as a prune');
        self::assertSame(AdminAccessLogStore::ANCHOR_MISMATCH, $verified['anchor']);
        self::assertContains(['id' => 0, 'reason' => 'missing'], $verified['breaks']);
    }

    public function testRewritingTheNewestSurvivingRowAndRehashingItIsCaught(): void
    {
        $log = $this->anchoredLog();
        $scope = $this->scope(2, 1);

        foreach (range(1, 3) as $i) {
            $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/r' . $i, 4));
        }

        // Everything a database editor needs is public and static, which is
        // exactly why the anchor and not the row hash is the control.
        $row = $this->env->database->selectOne(Query::from('admin_access_log')->where('id', '=', 3));
        self::assertIsArray($row);
        unset($row['hash']);
        $row['outcome'] = 'refused';
        $row['record_count'] = 0;
        $this->env->database->update(
            'admin_access_log',
            ['outcome' => 'refused', 'record_count' => 0, 'hash' => AdminAccessLogStore::digest($row)],
            ['id' => 3]
        );

        $verified = $log->verify();
        self::assertSame([], array_filter($verified['breaks'], static fn (array $b): bool => $b['reason'] === 'content'));
        self::assertContains(['id' => 3, 'reason' => 'head'], $verified['breaks']);
        self::assertSame(AdminAccessLogStore::ANCHOR_MISMATCH, $verified['anchor']);
    }

    public function testTheRetentionSweepAdvancesTheAnchorSoItsOwnPrefixVerifiesClean(): void
    {
        $log = $this->anchoredLog();
        $scope = $this->scope(2, 1);

        $this->env->clock->set('2024-01-01 09:00:00');
        $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/ancient', 1));
        $this->env->clock->set('2026-03-01 09:00:00');
        $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/recent', 1));

        self::assertSame(1, $log->prune($log->cutOff(400)));

        $verified = $log->verify();
        self::assertSame([], $verified['breaks']);
        self::assertTrue($verified['pruned']);
        $anchor = $log->anchor();
        self::assertIsArray($anchor);
        self::assertSame(1, $anchor['pruned']);
        self::assertSame(1, $anchor['pruned_to_id']);
        self::assertSame(2, $anchor['from_id']);
    }

    public function testAnAnchorWithNoHostSecretReportsItselfUnkeyedRatherThanProven(): void
    {
        $unkeyed = new AdminAccessLogStore(
            $this->env->database,
            $this->env->clock,
            new DatabaseSettingsRepository($this->env->database, $this->env->clock),
        );
        $unkeyed->record(AdminAccessRecord::allowed('org.members.read', $this->scope(2, 1), '/one', 1));

        self::assertSame(AdminAccessLogStore::ANCHOR_UNKEYED, $unkeyed->verify()['anchor']);

        // The same site once its host supplies a secret: the next append
        // re-keys the anchor and the state stops being an admission.
        $keyed = $this->anchoredLog();
        $keyed->record(AdminAccessRecord::allowed('org.members.read', $this->scope(2, 1), '/two', 1));
        self::assertSame(AdminAccessLogStore::ANCHOR_OK, $keyed->verify()['anchor']);
    }

    public function testAKeyedAnchorCannotBeRecomputedFromTheDatabaseAlone(): void
    {
        $log = $this->anchoredLog();
        $scope = $this->scope(2, 1);
        $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/one', 1));
        $log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/two', 1));

        // The whole attack: delete a row, rebuild the chain forward, and
        // rewrite the anchor to agree with it using only what the database
        // holds. Without the key the last step cannot be done.
        $this->env->database->delete('admin_access_log', ['id' => 1]);
        $head = $this->env->database->selectOne(Query::from('admin_access_log')->orderBy('id', Query::SORT_DESC));
        self::assertIsArray($head);
        $forged = ['shape' => AdminAccessLogStore::ANCHOR_SHAPE, 'from_id' => 2, 'count' => 1,
            'head' => (string) $head['hash'], 'pruned' => 1, 'pruned_to_id' => 1, 'pruned_at' => ''];
        $settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $settings->set(AdminAccessLogStore::ANCHOR_SETTING, $forged + [
            'mac' => hash('sha256', implode('|', ['v1', '2', '1', (string) $head['hash'], '1', '1', ''])),
        ]);

        self::assertSame(AdminAccessLogStore::ANCHOR_MISMATCH, $log->verify()['anchor']);
    }

    public function testAnOrganisationAnchorMatchesBothTheActorSideAndTheSubjectSide(): void
    {
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $this->scope(2, 1), '/one', 5));
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $this->scope(3, 2), '/two', 5));
        // A caller with no claim on organisation 1 who asked about it anyway.
        $this->log->record(AdminAccessRecord::refused('org.members.read', 4, 1, '/one'));

        $page = $this->log->page(AdminAccessLogQuery::forOrganisation($this->scope(2, 1)));
        self::assertSame(2, $page['total']);
        $routes = array_map(static fn (array $row): string => (string) $row['route'], $page['items']);
        self::assertSame(['/one', '/one'], $routes);

        $guard = new AccessGuard($this->env->authorization);
        $this->env->authorization->grant(1, Capability::ADMIN);
        $siteWide = $this->log->page(AdminAccessLogQuery::siteWide($guard, Principal::user(1)));
        self::assertSame(3, $siteWide['total']);

        // The unanchored form is the one a delegated administrator may never
        // reach, and the refusal is the type rather than a sentence in a
        // docblock that says callers must have checked.
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);
        AdminAccessLogQuery::siteWide($guard, Principal::user(2));
    }

    public function testARefusalIsRecordedWithNoActorOrganisationAndNoRowsDisclosed(): void
    {
        $this->log->record(AdminAccessRecord::refused('org.members.read', 9, 7, '/manage/organisations/7/members'));

        $row = $this->env->database->selectOne(Query::from('admin_access_log'));
        self::assertIsArray($row);
        self::assertSame(AdminAccessRecord::OUTCOME_REFUSED, $row['outcome']);
        self::assertNull($row['actor_org_id']);
        self::assertSame(7, (int) $row['subject_org_id']);
        self::assertSame('org:7', $row['resource']);
        self::assertSame(0, (int) $row['record_count']);
        self::assertSame(9, (int) $row['actor_id']);
    }

    public function testTheSiteManagerPathIsRecordedWithTheSameTwoAnchorsAsADelegatedRead(): void
    {
        // A vidyalms.manage holder bypasses the role predicate but not the
        // tenant one, and their read is recorded as scoped exactly like
        // anybody else's. This is the compensating record the manage waiver
        // otherwise has none of.
        $scope = $this->scope(1, 1);
        self::assertTrue($scope->siteManager);
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/manage/organisations/1/members', 4));

        $row = $this->env->database->selectOne(Query::from('admin_access_log'));
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['actor_org_id']);
        self::assertSame(1, (int) $row['subject_org_id']);
        self::assertSame('org:1', $row['resource']);
        self::assertSame(4, (int) $row['record_count']);
    }

    /**
     * actor_role_key recorded $scope->roles[0], and OrgScope sorts its roles,
     * so a caller holding two was recorded under whichever sorted first and
     * the other was lost, permanently, in the append-only record that exists
     * to answer under what authority a read was made.
     */
    public function testTheWholeRoleSetIsRecordedAndNotTheFirstOneAlphabetically(): void
    {
        self::assertSame('org_admin', AdminAccessRecord::roleKeys(['org_admin']));
        self::assertSame('acme.regional_lead,org_admin', AdminAccessRecord::roleKeys(['acme.regional_lead', 'org_admin']));
        self::assertNull(AdminAccessRecord::roleKeys([]));

        // A set too wide for the column keeps whole keys and says how many it
        // dropped, because a silently truncated audit value is worse than a
        // short one that admits it.
        $many = ['acme.auditor', 'acme.regional_lead', 'org_admin', 'org_reporter'];
        $recorded = AdminAccessRecord::roleKeys($many);
        self::assertIsString($recorded);
        self::assertLessThanOrEqual(AdminAccessRecord::ROLE_KEY_MAX, \strlen($recorded));
        self::assertSame('acme.auditor,acme.regional_lead,+2 more', $recorded);

        // And when not one key fits, the count is still the honest answer.
        $vast = array_map(static fn (int $i): string => 'acme.a_very_long_registered_role_key_' . $i, range(1, 3));
        self::assertSame('+3 more', AdminAccessRecord::roleKeys($vast));
    }

    public function testTheSweepRemovesOnlyRowsOlderThanTheCutOff(): void
    {
        $scope = $this->scope(2, 1);
        $this->env->clock->set('2026-01-01 09:00:00');
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/old', 1));
        $this->env->clock->set('2026-03-01 09:00:00');
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/new', 1));

        $removed = $this->log->prune($this->log->cutOff(30));
        self::assertSame(1, $removed);

        $rows = $this->env->database->selectAll(Query::from('admin_access_log'));
        self::assertCount(1, $rows);
        self::assertSame('/new', $rows[0]['route']);
    }

    public function testTheRetentionTaskPrunesOnTheSiteFloorAndReportsWhatItDid(): void
    {
        $scope = $this->scope(2, 1);
        $settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $task = new AccessLogRetentionTask($this->log, $settings);

        $this->env->clock->set('2024-01-01 09:00:00');
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/ancient', 1));
        $this->env->clock->set('2026-03-01 09:00:00');
        $this->log->record(AdminAccessRecord::allowed('org.members.read', $scope, '/recent', 1));

        $line = $task->run(new ScheduledTaskRun(1, [], Principal::user(1)));

        // The summary now names the third number as well, because a sweep that
        // kept rows for an organisation and did not say so is a deletion an
        // operator cannot reconcile against the count they expected.
        self::assertSame('pruned=1 floor_days=400 lengthened_organisations=0', $line);
        self::assertSame(1, $this->env->database->count(Query::from('admin_access_log')));
    }

    public function testAPerOrganisationPolicyMayLengthenTheFloorAndNeverShortenIt(): void
    {
        $settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $task = new AccessLogRetentionTask($this->log, $settings);

        $settings->set(AccessLogRetentionTask::RETENTION_SETTING, 730);

        self::assertSame(730, $task->floorDays());
        self::assertSame(730, $task->retentionDaysFor(1, 30), 'a shorter policy is clamped at the floor');
        self::assertSame(1095, $task->retentionDaysFor(1, 1095), 'a longer policy is honoured');
        self::assertSame(730, $task->retentionDaysFor(1));
    }

    public function testANonsensicalStoredFloorFallsBackToTheLongerReading(): void
    {
        $settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $task = new AccessLogRetentionTask($this->log, $settings);

        $settings->set(AccessLogRetentionTask::RETENTION_SETTING, 0);
        self::assertSame(AccessLogRetentionTask::DEFAULT_RETENTION_DAYS, $task->floorDays());

        $settings->set(AccessLogRetentionTask::RETENTION_SETTING, 'forever');
        self::assertSame(AccessLogRetentionTask::DEFAULT_RETENTION_DAYS, $task->floorDays());
    }

    /** The store as the kernel builds it: anchored, and keyed by a host secret. */
    private function anchoredLog(): AdminAccessLogStore
    {
        return new AdminAccessLogStore(
            $this->env->database,
            $this->env->clock,
            new DatabaseSettingsRepository($this->env->database, $this->env->clock),
            str_repeat('access-log-secret-', 3),
        );
    }

    /** The four free roles, seated the way the kernel seats them. */
    private static function roleRegistry(): OrgRoleRegistry
    {
        $registry = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $registry->register($role);
        }

        return $registry;
    }

    private function scope(int $actorId, int $organisationId): OrgScope
    {
        $roles = self::roleRegistry();
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $roles);
        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->env->authorization->grant($actorId, $actorId === 1 ? Capability::MANAGE : Capability::MANAGE_GROUPS);

        if ($this->env->database->selectOne(Query::from('organisations')->where('id', '=', $organisationId)) === null) {
            $created = $store->create([
                'name' => 'Organisation ' . $organisationId,
                'slug' => 'org-' . $organisationId,
                'created_by' => 1,
            ]);
            self::assertSame($organisationId, $created);
        }

        $siteScope = OrgScope::fromResolver($resolver, Principal::user(1), $organisationId, 'people');

        if ($actorId !== 1 && $store->activeMemberId($siteScope, $actorId) === null) {
            $store->addMember($siteScope, $actorId, 'org_admin', 'manual', null);
        }

        return OrgScope::fromResolver($resolver, Principal::user($actorId), $organisationId, 'read');
    }
}
