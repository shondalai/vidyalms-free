<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\InvitationAttemptLimit;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatThresholdAlerts;
use Shondalai\VidyaLMS\Core\Application\Organisation\TransferSeat;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\FieldConflictPolicy;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\SeatThresholdTrigger;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\InvitationExpiryTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PoolTermSweepTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ReservationExpiryTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\SeatReconciliationTask;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ReassertsInvitations;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Reserve at issue, the four free organisation sweeps, the transfer and the
 * seat threshold alerts.
 *
 * Every case here fails against the pre-wave tree, and most of them fail for
 * the same reason: a column was written and nothing read it. `reserved_until`
 * did not exist, `ends_at` and `expires_at` existed and were never acted on,
 * and the two derivations of a pool's balance were never compared.
 */
final class SeatReservationsAndSweepsTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private Registries $registries;
    private OrganisationStore $organisations;
    private OrganisationLearningStore $learning;
    private OrgScopeResolver $access;
    private SeatReservations $reservations;
    private TransferSeat $transfer;
    private ManageOrganisationInvitations $invitations;
    private RecordingInvitationDelivery $delivery;
    private Settings $settings;
    private SweptRecordStore $records;
    private OrganisationSweepAuthority $authority;
    private SeatThresholdAlerts $alerts;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $statement = $this->env->database->pdo()->query('PRAGMA table_info(vidyalms_entitlements)');
        self::assertNotFalse($statement);
        $columns = array_column($statement->fetchAll(), 'name');

        foreach (
            ['organisation_id' => 'INTEGER', 'org_membership_id' => 'INTEGER', 'source_ref_digest' => 'TEXT',
                'revoked_reason' => 'TEXT', 'revoked_ref' => 'TEXT'] as $key => $type
        ) {
            if (!\in_array($key, $columns, true)) {
                $this->env->database->pdo()->exec('ALTER TABLE vidyalms_entitlements ADD COLUMN ' . $key . ' ' . $type);
            }
        }

        foreach (range(1, 8) as $id) {
            $this->env->users->add($id, 'Synthetic ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->kernel = new CoreKernel($this->env->ports);
        $this->registries = new Registries();
        CoreOrganisationProviders::seed($this->registries);
        $this->organisations = new OrganisationStore($this->env->database, $this->env->clock);
        $this->learning = new OrganisationLearningStore($this->env->database, $this->env->clock);
        $this->access = new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->organisations,
            $this->registries->orgRoles
        );
        $composed = new ComposedAccess(
            new OrganisationAccess($this->access),
            new CourseReleasePolicy($this->env->database, $this->env->clock)
        );
        $events = new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events);
        $this->settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $this->alerts = new SeatThresholdAlerts($this->kernel->notificationEnqueue, $this->env->clock, $this->settings);
        $this->reservations = new SeatReservations(
            $this->learning,
            $this->organisations,
            $composed,
            $this->kernel->grantEntitlement,
            $events,
            $this->alerts
        );
        $this->transfer = new TransferSeat(
            $this->access,
            $this->organisations,
            $this->learning,
            $composed,
            $this->env->users,
            $this->kernel->grantEntitlement,
            $this->kernel->revokeEntitlement,
            $events
        );
        $this->delivery = new RecordingInvitationDelivery();
        $this->invitations = $this->invitationsWith($this->registries->invitationMatch, $composed, $events);
        $this->records = new SweptRecordStore($this->env->database, $this->env->clock);
        $this->authority = new OrganisationSweepAuthority($this->records);
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', ['title' => 'Synthetic Course', 'slug' => 'synthetic-course',
            'state' => 'published', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function testTheFreeStrictEmailPolicyIsRegisteredAndARegistryMissRefusesAcceptance(): void
    {
        self::assertTrue($this->registries->invitationMatch->has(InvitationMatchRegistry::DEFAULT_KEY));

        $this->organisation('acme');
        $token = $this->issue('user2@example.test');

        // The same ceremony, the same correct account, and an empty registry.
        // It refuses, which is the published fail-closed direction, and it
        // refuses with the same answer an unknown token gets so the endpoint
        // still cannot be enumerated.
        $unregistered = $this->invitationsWith(
            new InvitationMatchRegistry(),
            new ComposedAccess(
                new OrganisationAccess($this->access),
                new CourseReleasePolicy($this->env->database, $this->env->clock)
            ),
            new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events)
        );

        $this->expectException(EntityNotFoundException::class);
        $unregistered->claim(Principal::user(2), $token);
    }

    public function testForwardedTokensAreStillRefusedNowThatTheComparisonIsAProvider(): void
    {
        $this->organisation('acme');
        $token = $this->issue('user2@example.test');

        try {
            $this->invitations->claim(Principal::user(3), $token);
            self::fail('An account whose address is not the invited one must not begin the ceremony.');
        } catch (EntityNotFoundException $refusal) {
            self::assertSame('This invitation cannot be used.', $refusal->getMessage());
        }

        self::assertIsInt($this->invitations->claim(Principal::user(2), $token)['claim_id']);
    }

    public function testInvitingOneMoreThanTheSeatsRefusesAtIssueRatherThanAtAcceptance(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 40);

        foreach (range(1, 40) as $index) {
            $this->issue('invitee-' . $index . '@example.test', $pool);
        }

        $scope = $this->scope($orgId);
        self::assertSame(0, $this->learning->balance($scope, $pool)['available']);
        self::assertSame(40, $this->env->database->count(Query::from('seat_events')
            ->where('pool_id', '=', $pool)->where('event_key', '=', SeatEventType::SEAT_RESERVED)));

        try {
            $this->issue('invitee-41@example.test', $pool);
            self::fail('The forty first invitation into a forty seat pool must be refused at issue.');
        } catch (ConflictException $refusal) {
            self::assertSame('This pool has no seat available to reserve for this invitation.', $refusal->getMessage());
        }

        // The refusal rolls back the whole issue, so there is no invitation in
        // somebody's inbox promising a seat that was never taken.
        self::assertSame(40, $this->env->database->count(Query::from('invitations')->where('organisation_id', '=', $orgId)));
        self::assertTrue($this->learning->balance($scope, $pool)['reconciled']);
    }

    public function testAcceptanceConvertsTheReservationAndRevocationGivesTheSeatBack(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 2);
        $token = $this->issue('user2@example.test', $pool);
        $invitation = $this->invitation($orgId, 'user2@example.test');
        $reservationId = (int) $invitation['seat_assignment_id'];
        self::assertGreaterThan(0, $reservationId);
        self::assertSame(SeatAssignmentState::RESERVED, $this->assignment($reservationId)['state']);

        $claim = $this->invitations->claim(Principal::user(2), $token);
        $this->invitations->accept(Principal::user(2), (int) $claim['claim_id'], $this->delivery->lastSecret());
        $converted = $this->assignment($reservationId);
        self::assertSame(SeatAssignmentState::ASSIGNED, $converted['state']);
        self::assertNotNull($converted['membership_id']);
        self::assertSame(2, (int) $converted['user_id']);
        self::assertNotNull($converted['entitlement_id']);

        $scope = $this->scope($orgId);
        $balance = $this->learning->balance($scope, $pool);
        self::assertSame(1, $balance['held']);
        self::assertTrue($balance['reconciled']);
        // The conversion charges the pool nothing: the reservation already
        // spent the seat, and a second minus one is how the two derivations
        // stop agreeing.
        $assigned = $this->env->database->selectOne(Query::from('seat_events')
            ->where('assignment_id', '=', $reservationId)->where('event_key', '=', SeatEventType::SEAT_ASSIGNED));
        self::assertNotNull($assigned);
        self::assertSame(0, (int) $assigned['seat_delta']);

        $second = $this->invitation($orgId, 'user3@example.test', fn () => $this->issue('user3@example.test', $pool));
        $this->invitations->revoke(
            Principal::user(1),
            $orgId,
            (int) $second['id'],
            (int) $this->organisations->organisation($this->scope($orgId))['revision']
        );
        self::assertSame(SeatAssignmentState::CANCELLED, $this->assignment((int) $second['seat_assignment_id'])['state']);
        self::assertSame(1, $this->learning->balance($this->scope($orgId), $pool)['available']);
    }

    public function testTheInvitationExpiryIsASettingThatDefaultsToSevenDays(): void
    {
        $orgId = $this->organisation('acme');
        self::assertSame(7, $this->invitations->expiryDaysFor($orgId));
        $this->issue('user2@example.test');
        $expected = $this->env->clock->now()->add(new \DateInterval('P7D'))->format('Y-m-d H:i:s');
        self::assertSame($expected, (string) $this->invitation($orgId, 'user2@example.test')['expires_at']);

        $this->settings->set(ManageOrganisationInvitations::EXPIRY_SETTING, 14);
        self::assertSame(14, $this->invitations->expiryDaysFor($orgId));

        // A nonsense value is a misconfiguration, and the safe reading of one
        // is the shipped default rather than an invitation that never expires
        // or one that expires before it arrives.
        $this->settings->set(ManageOrganisationInvitations::EXPIRY_SETTING, 0);
        self::assertSame(7, $this->invitations->expiryDaysFor($orgId));
        $this->settings->set(ManageOrganisationInvitations::EXPIRY_SETTING, 100000);
        self::assertSame(ManageOrganisationInvitations::MAX_EXPIRY_DAYS, $this->invitations->expiryDaysFor($orgId));
    }

    public function testTheReservationSweepReturnsSeatsPastTheirWindowExactlyOnce(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 1);
        $this->issue('user2@example.test', $pool);
        $reservationId = (int) $this->invitation($orgId, 'user2@example.test')['seat_assignment_id'];
        self::assertSame(0, $this->learning->balance($this->scope($orgId), $pool)['available']);

        $task = new ReservationExpiryTask($this->records, $this->authority, $this->organisations, $this->reservations);
        self::assertSame('released=0 skipped=0', $task->run($this->taskRun()));

        $this->env->clock->advance('P8D');
        self::assertSame('released=1 skipped=0', $task->run($this->taskRun()));
        self::assertSame(SeatAssignmentState::CANCELLED, $this->assignment($reservationId)['state']);
        $balance = $this->learning->balance($this->scope($orgId), $pool);
        self::assertSame(1, $balance['available']);
        self::assertTrue($balance['reconciled']);

        $event = $this->env->database->selectOne(Query::from('seat_events')
            ->where('assignment_id', '=', $reservationId)
            ->where('event_key', '=', SeatEventType::SEAT_RESERVATION_EXPIRED));
        self::assertNotNull($event);
        self::assertSame(1, (int) $event['seat_delta']);
        self::assertSame(OrganisationLearningStore::ACTOR_SYSTEM, (string) $event['actor_type']);
        // The actor is the administrator whose invitation spent the seat, not
        // a zero and not a synthetic principal.
        self::assertSame(1, (int) $event['actor_id']);

        // A second run of the same period finds nothing left to do, and the
        // ledger still carries exactly one credit for this reservation.
        self::assertSame('released=0 skipped=0', $task->run($this->taskRun()));
        self::assertSame(1, $this->env->database->count(Query::from('seat_events')
            ->where('assignment_id', '=', $reservationId)
            ->where('event_key', '=', SeatEventType::SEAT_RESERVATION_EXPIRED)));
    }

    public function testTheInvitationSweepExpiresPendingRowsPrunesClaimsAndSkipsAReassertedOne(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 2);
        $token = $this->issue('user2@example.test', $pool);
        $this->invitations->claim(Principal::user(2), $token);
        $this->issue('user3@example.test');
        self::assertSame(1, $this->env->database->count(Query::from('org_invitation_claims')));

        $this->env->clock->advance('P8D');
        $task = new InvitationExpiryTask(
            $this->records,
            $this->authority,
            $this->organisations,
            $this->registries->provisioningSources,
            $this->reservations
        );
        self::assertSame('expired=2 renewed=0 skipped=0 stranded_released=0 claims_pruned=1', $task->run($this->taskRun()));

        $row = $this->invitation($orgId, 'user2@example.test');
        self::assertSame('expired', (string) $row['state']);
        self::assertNull($row['pending_key']);
        self::assertNull($row['token_hash']);
        self::assertSame(SeatAssignmentState::CANCELLED, $this->assignment((int) $row['seat_assignment_id'])['state']);
        self::assertSame(0, $this->env->database->count(Query::from('org_invitation_claims')));

        // GitHub's carve-out, narrowed: a source that is still asserting the
        // address renews rather than expiring, and a free build has no source
        // at all so nothing is skipped, which is what the two runs above and
        // below prove together.
        $this->issue('user4@example.test');
        $this->registries->provisioningSources->register($this->reassertingSource($orgId, 'user4@example.test'));
        $this->env->clock->advance('P8D');
        self::assertSame('expired=0 renewed=1 skipped=0 stranded_released=0 claims_pruned=0', $task->run($this->taskRun()));
        self::assertSame('pending', (string) $this->invitation($orgId, 'user4@example.test')['state']);
    }

    public function testASeatStrandedByAnInvitationThatEndedWithoutReleasingItIsGivenBack(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 1);
        $this->issue('user2@example.test', $pool);
        $row = $this->invitation($orgId, 'user2@example.test');
        $reservationId = (int) $row['seat_assignment_id'];
        // A hard bounce, written the way the delivery feedback path writes it:
        // the invitation is terminal at once and its seat is left committed to
        // an address the mail server has already refused.
        $this->env->database->update(
            'invitations',
            ['state' => 'bounced', 'pending_key' => null, 'token_hash' => null],
            ['id' => (int) $row['id']]
        );
        self::assertSame(0, $this->learning->balance($this->scope($orgId), $pool)['available']);

        $task = new InvitationExpiryTask(
            $this->records,
            $this->authority,
            $this->organisations,
            $this->registries->provisioningSources,
            $this->reservations
        );
        self::assertSame(
            'expired=0 renewed=0 skipped=0 stranded_released=1 claims_pruned=0',
            $task->run($this->taskRun())
        );
        self::assertSame(SeatAssignmentState::CANCELLED, $this->assignment($reservationId)['state']);
        self::assertSame(1, $this->learning->balance($this->scope($orgId), $pool)['available']);

        // The join is what makes the pass terminate: a link already released
        // matches nothing, so the same row is not rescanned every hour.
        self::assertSame(
            'expired=0 renewed=0 skipped=0 stranded_released=0 claims_pruned=0',
            $task->run($this->taskRun())
        );
    }

    public function testThePoolTermSweepExpiresThePoolAndDisposesEverySeatItWasHolding(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 2);
        $this->issue('user2@example.test', $pool);
        $reservationId = (int) $this->invitation($orgId, 'user2@example.test')['seat_assignment_id'];
        $this->learning->update('seat_pools', $this->scope($orgId), $pool, [
            'ends_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
        ]);
        $this->env->clock->advance('PT1H');

        $task = new PoolTermSweepTask(
            $this->records,
            $this->authority,
            $this->organisations,
            $this->learning,
            $this->lifecycle(),
            $this->registries->seatExpiryDisposition,
            $this->env->clock
        );
        self::assertSame('pools_expired=1 seats_disposed=1 substituted=0 skipped=0', $task->run($this->taskRun()));
        self::assertSame(SeatPoolState::EXPIRED, (string) $this->learning->pool($this->scope($orgId), $pool)['state']);
        // Forfeited and not cancelled: the term the seat was reserved against
        // has ended, so there is no pool left to return it to. That is what
        // this task's own docblock has always said it did and what the
        // administrator's term end already did; the sweep credited the seat
        // back instead, which is the divergence the review found.
        self::assertSame(SeatAssignmentState::FORFEITED, $this->assignment($reservationId)['state']);

        $poolEvent = $this->env->database->selectOne(Query::from('seat_events')
            ->where('pool_id', '=', $pool)->where('event_key', '=', SeatEventType::POOL_EXPIRED));
        self::assertNotNull($poolEvent);
        $payload = json_decode((string) $poolEvent['payload'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(PoolTermSweepTask::FREE_DISPOSITION, $payload['disposition_key']);
        self::assertTrue($payload['disposition_resolved']);

        // An expired pool is not swept twice, because it is no longer in a
        // state a term can end.
        self::assertSame('pools_expired=0 seats_disposed=0 substituted=0 skipped=0', $task->run($this->taskRun()));
    }

    public function testTheReconciliationTaskAlertsOnDivergenceRatherThanCorrectingIt(): void
    {
        $orgId = $this->organisation('acme');
        $pool = $this->pool($orgId, 4);
        $this->issue('user2@example.test', $pool);
        $reservationId = (int) $this->invitation($orgId, 'user2@example.test')['seat_assignment_id'];
        // A row that vanished without a ledger entry, which is exactly the
        // shape the two derivations exist to catch.
        $this->env->database->delete('seat_assignments', ['id' => $reservationId]);
        self::assertFalse($this->learning->balance($this->scope($orgId), $pool)['reconciled']);

        $task = new SeatReconciliationTask(
            $this->records,
            $this->authority,
            $this->learning,
            $this->alerts,
            $this->settings
        );
        self::assertSame('checked=1 diverged=1 skipped=0 cursor=' . $pool, $task->run($this->taskRun()));

        // Alerted and not corrected: the ledger still says what it said.
        self::assertFalse($this->learning->balance($this->scope($orgId), $pool)['reconciled']);
        $intent = $this->env->database->selectOne(Query::from('notification_queue')
            ->where('trigger_key', '=', SeatThresholdTrigger::KEY_LIMITED)->orderBy('id', 'DESC'));
        self::assertNotNull($intent);
        $parameters = json_decode((string) $intent['parameters'], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($parameters);
        self::assertFalse($parameters['reconciled']);
        self::assertSame($orgId, $parameters['organisation_id']);
    }

    public function testATransferAcquiresBeforeItReleasesAndRefusesWhenTheTargetIsFull(): void
    {
        $orgId = $this->organisation('acme');
        $source = $this->pool($orgId, 1, 'Source pool');
        $target = $this->pool($orgId, 1, 'Target pool');
        $full = $this->pool($orgId, 0, 'Full pool');
        $held = $this->seatFor($orgId, $source, 2, 'user2@example.test');

        try {
            $this->transfer->execute(Principal::user(1), $orgId, $held, [
                'expected_revision' => $this->revision($orgId), 'target_pool_id' => $full,
            ]);
            self::fail('A transfer into a pool with no seats must be refused.');
        } catch (ConflictException $refusal) {
            self::assertSame('The receiving pool has no seat available for this transfer.', $refusal->getMessage());
        }

        self::assertSame(SeatAssignmentState::ASSIGNED, $this->assignment($held)['state']);
        $result = $this->transfer->execute(Principal::user(1), $orgId, $held, [
            'expected_revision' => $this->revision($orgId), 'target_pool_id' => $target,
        ]);
        self::assertSame('transferred', $result['outcome']);
        self::assertSame(SeatAssignmentState::RELEASED, $this->assignment($held)['state']);
        self::assertSame(SeatAssignmentState::ASSIGNED, $this->assignment((int) $result['item']['id'])['state']);

        // The acquiring row is written first. Entra documents that removing
        // first leaves the person unlicensed while processing catches up, and
        // the ledger is where that order is provable.
        $rows = $this->env->database->selectAll(Query::from('seat_events')
            ->where('event_key', '=', SeatEventType::SEAT_TRANSFERRED)->orderBy('id'));
        self::assertCount(2, $rows);
        self::assertSame($target, (int) $rows[0]['pool_id']);
        self::assertSame(-1, (int) $rows[0]['seat_delta']);
        self::assertSame($source, (int) $rows[1]['pool_id']);
        self::assertSame(1, (int) $rows[1]['seat_delta']);
        self::assertTrue($this->learning->balance($this->scope($orgId), $source)['reconciled']);
        self::assertTrue($this->learning->balance($this->scope($orgId), $target)['reconciled']);
    }

    public function testAnExhaustingPoolReachesEveryAdministratorOfItsOwnOrganisationAndNobodyElse(): void
    {
        $first = $this->organisation('acme');
        $second = $this->organisation('globex');
        $this->administrator($first, 5);
        $this->administrator($first, 6);
        $this->administrator($second, 7);
        $pool = $this->pool($first, 1);
        $this->issue('user2@example.test', $pool, $first);

        self::assertGreaterThan(0, $this->kernel->notificationFanout->run());
        $recipients = array_map(
            static fn (array $row): int => (int) $row['recipient_user_id'],
            $this->env->database->selectAll(Query::from('notification_queue')
                ->select(['recipient_user_id'])
                ->where('trigger_key', '=', SeatThresholdTrigger::KEY_EXHAUSTED)
                ->whereNotNull('recipient_user_id')
                ->orderBy('recipient_user_id'))
        );

        self::assertSame([5, 6], array_values(array_unique($recipients)));
        self::assertCount(2, $recipients);
        self::assertNotContains(7, $recipients);
    }

    public function testTransferRefusesAnExistingTargetSeatWithoutChangingEitherPool(): void
    {
        $orgId = $this->organisation('acme');
        $source = $this->pool($orgId, 1);
        $target = $this->pool($orgId, 2);
        $held = $this->seatFor($orgId, $source, 2, 'user2@example.test');
        $this->kernel->organisationSeats->assign(Principal::user(1), $orgId, $target, [
            'expected_revision' => $this->revision($orgId), 'user_id' => 2,
        ]);
        $this->assertTransferRefusedWithoutWrites($orgId, $held, $target, 'already holds a seat');
    }

    public function testTransferRefusesAReservationMadeBeforeTheAccountUsedThatAddress(): void
    {
        $orgId = $this->organisation('acme');
        $source = $this->pool($orgId, 1);
        $target = $this->pool($orgId, 2);
        $held = $this->seatFor($orgId, $source, 2, 'user2@example.test');
        $this->issue('future@example.test', $target, $orgId);
        $this->env->users->add(2, 'Synthetic 2', 'future@example.test');
        $this->assertTransferRefusedWithoutWrites($orgId, $held, $target, 'already holding a seat');
    }

    private function assertTransferRefusedWithoutWrites(int $orgId, int $held, int $target, string $reason): void
    {
        $revision = $this->revision($orgId);
        $events = $this->env->database->selectAll(Query::from('seat_events')->orderBy('id'));
        $assignments = $this->env->database->selectAll(Query::from('seat_assignments')->orderBy('id'));
        try {
            $this->transfer->execute(Principal::user(1), $orgId, $held, [
                'expected_revision' => $revision, 'target_pool_id' => $target,
            ]);
            self::fail('A transfer consumed a second seat for the same holder.');
        } catch (ConflictException $refusal) {
            self::assertStringContainsString($reason, $refusal->getMessage());
        }
        self::assertSame($revision, $this->revision($orgId));
        self::assertSame($events, $this->env->database->selectAll(Query::from('seat_events')->orderBy('id')));
        self::assertSame($assignments, $this->env->database->selectAll(Query::from('seat_assignments')->orderBy('id')));
    }

    private function invitationsWith(
        InvitationMatchRegistry $matches,
        ComposedAccess $composed,
        OrganisationLearningEvents $events
    ): ManageOrganisationInvitations {
        return new ManageOrganisationInvitations(
            $this->access,
            $this->organisations,
            new ManageOrganisationMembers(
                new OrganisationAccess($this->access),
                $this->organisations,
                $this->registries->orgRoles,
                $this->env->users,
                $this->env->translator
            ),
            $this->registries->orgRoles,
            $this->env->users,
            $this->env->clock,
            $this->env->random,
            $this->delivery,
            new InvitationAttemptLimit($this->env->database, $this->env->clock),
            $this->registries->orgJoinRules,
            $matches,
            $this->settings,
            $this->reservations
        );
    }

    private function organisation(string $slug): int
    {
        $id = $this->organisations->create(['name' => ucfirst($slug), 'slug' => $slug, 'created_by' => 1]);
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('org_course_releases', ['organisation_id' => $id, 'course_id' => 1,
            'released_by' => 1, 'state' => 'active', 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    private function administrator(int $orgId, int $userId): void
    {
        $this->organisations->addMember($this->scope($orgId), $userId, 'org_admin', 'manual', null);
    }

    /**
     * A funded pool, with the ledger row that funds it.
     *
     * The row and the event together, because a pool whose ledger says
     * nothing funded it is a pool that never reconciles, and every write path
     * refuses to touch one.
     */
    private function pool(int $orgId, int $seats, string $name = 'Synthetic pool'): int
    {
        $scope = $this->scope($orgId);
        $poolId = $this->learning->insert('seat_pools', $scope, [
            'course_id' => 1, 'name' => $name, 'seats_granted' => $seats,
            // The funding stamp beside the funding row, because a pool with a
            // quantity and no funded_at is an unfunded pool with a capacity of
            // zero, which is what the saving route now writes for a draft.
            'state' => SeatPoolState::ACTIVE, 'funded_at' => $this->learning->now(), 'created_by' => 1,
        ]);
        $this->learning->ledger(
            $scope,
            $this->revision($orgId),
            $poolId,
            null,
            SeatEventType::POOL_FUNDED,
            $seats,
            SeatEventReason::GRANTED_BY_ADMIN
        );

        return $poolId;
    }

    /** The raw invitation token, taken from the recording delivery. */
    private function issue(string $email, ?int $poolId = null, int $orgId = 1): string
    {
        $body = ['expected_revision' => $this->revision($orgId), 'email' => $email, 'role_key' => 'org_member'];

        if ($poolId !== null) {
            $body['pool_id'] = $poolId;
        }

        $this->invitations->issue(Principal::user(1), $orgId, $body);

        return $this->delivery->lastSecret();
    }

    private function seatFor(int $orgId, int $poolId, int $userId, string $email): int
    {
        $token = $this->issue($email, $poolId, $orgId);
        $claim = $this->invitations->claim(Principal::user($userId), $token);
        $this->invitations->accept(Principal::user($userId), (int) $claim['claim_id'], $this->delivery->lastSecret());

        return (int) $this->invitation($orgId, $email)['seat_assignment_id'];
    }

    private function lifecycle(): \Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle
    {
        return new \Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle(
            $this->learning,
            $this->registries->seatReclaim,
            $this->kernel->revokeEntitlement,
            new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events),
            $this->env->clock
        );
    }

    /** @return array<string, mixed> */
    private function invitation(int $orgId, string $email, ?callable $before = null): array
    {
        if ($before !== null) {
            $before();
        }

        $row = $this->env->database->selectOne(Query::from('invitations')
            ->where('organisation_id', '=', $orgId)->where('email', '=', $email));
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function assignment(int $id): array
    {
        $row = $this->env->database->selectOne(Query::from('seat_assignments')->where('id', '=', $id));
        self::assertNotNull($row);

        return $row;
    }

    private function revision(int $orgId): int
    {
        return (int) $this->organisations->organisation($this->scope($orgId))['revision'];
    }

    private function scope(int $orgId): OrgScope
    {
        return $this->access->require(Principal::user(1), $orgId, 'seats');
    }

    private function taskRun(): ScheduledTaskRun
    {
        return new ScheduledTaskRun(1, [], Principal::user(1));
    }

    private function reassertingSource(int $orgId, string $email): ProvisioningSourceInterface
    {
        return new class ($orgId, $email) implements ProvisioningSourceInterface, ReassertsInvitations {
            public function __construct(private readonly int $orgId, private readonly string $email)
            {
            }

            public function key(): string
            {
                return 'acme.directory';
            }

            public function label(): string
            {
                return 'acme.directory';
            }

            /**
             * A directory that keeps asserting is continuous by definition:
             * that is what makes it able to renew an invitation at all.
             */
            public function isContinuous(): bool
            {
                return true;
            }

            public function conflictPolicy(): string
            {
                return FieldConflictPolicy::SOURCE_WINS;
            }

            public function reassertsInvitation(int $organisationId, string $email): bool
            {
                return $organisationId === $this->orgId && $email === $this->email;
            }
        };
    }
}
