<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\CloseAttemptsOnAccessLoss;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\InvitationAttemptLimit;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccessTermination;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatReservations;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatThresholdAlerts;
use Shondalai\VidyaLMS\Core\Application\Organisation\TransferSeat;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatOverAllocationOutcome;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\SeatThresholdTrigger;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PoolTermSweepTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ReservationExpiryTask;
use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The seat review's closures, one case per finding.
 *
 * Every case here fails on the tree as the review found it, and most of them
 * fail on an accounting difference rather than a crash: the pool term sweep
 * and the administrator's term end wrote two different ledgers for one
 * transition, the as-at reconciliation reported a divergence on a pool that
 * reconciles perfectly today, and a reservation with no account behind it plus
 * a direct assignment charged one person two seats.
 */
final class SeatReviewClosuresTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private Registries $registries;
    private OrganisationStore $organisations;
    private OrganisationLearningStore $learning;
    private OrgScopeResolver $access;
    private ComposedAccess $composed;
    private OrganisationLearningEvents $events;
    private CloseAttemptsOnAccessLoss $closeAttempts;
    private SeatLifecycle $lifecycle;
    private SeatReservations $reservations;
    private ManageOrganisationSeats $seats;
    private ManageOrganisationInvitations $invitations;
    private RecordingInvitationDelivery $delivery;
    private Settings $settings;
    private SweptRecordStore $records;
    private OrganisationSweepAuthority $authority;
    private SeatThresholdAlerts $alerts;
    private ApiKernel $api;
    private int $sequence = 0;

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
        $this->learning = new OrganisationLearningStore($this->env->database, $this->env->clock, $this->env->ports->random);
        $this->access = new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->organisations,
            $this->registries->orgRoles
        );
        $orgAccess = new OrganisationAccess($this->access);
        $this->composed = new ComposedAccess(
            $orgAccess,
            new CourseReleasePolicy($this->env->database, $this->env->clock)
        );
        $this->events = new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events);
        $this->closeAttempts = new CloseAttemptsOnAccessLoss($this->env->database, $this->kernel->submitAttempt, $this->events);
        $this->lifecycle = new SeatLifecycle(
            $this->learning,
            $this->registries->seatReclaim,
            $this->kernel->revokeEntitlement,
            $this->events,
            $this->env->clock,
            $this->closeAttempts
        );
        $this->settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $this->alerts = new SeatThresholdAlerts($this->kernel->notificationEnqueue, $this->env->clock, $this->settings);
        $this->seats = new ManageOrganisationSeats(
            $this->access,
            $this->organisations,
            $this->learning,
            $this->composed,
            $this->kernel->grantEntitlement,
            $this->events,
            $this->lifecycle,
            $this->env->users,
            $this->registries->seatReclaim,
            $this->registries->seatScopes,
            $this->registries->seatOverAllocation,
            $this->registries->seatExpiryDisposition,
            $this->registries->seatEventReasons,
            $this->alerts
        );
        $this->reservations = new SeatReservations(
            $this->learning,
            $this->organisations,
            $this->composed,
            $this->kernel->grantEntitlement,
            $this->events,
            $this->alerts
        );
        $this->delivery = new RecordingInvitationDelivery();
        $members = new ManageOrganisationMembers(
            $orgAccess,
            $this->organisations,
            $this->registries->orgRoles,
            $this->env->users,
            $this->env->translator
        );
        $this->invitations = new ManageOrganisationInvitations(
            $this->access,
            $this->organisations,
            $members,
            $this->registries->orgRoles,
            $this->env->users,
            $this->env->clock,
            $this->env->random,
            $this->delivery,
            new InvitationAttemptLimit($this->env->database, $this->env->clock),
            $this->registries->orgJoinRules,
            $this->registries->invitationMatch,
            $this->settings,
            $this->reservations
        );
        $this->records = new SweptRecordStore($this->env->database, $this->env->clock);
        $this->authority = new OrganisationSweepAuthority($this->records);
        $termination = new OrganisationAccessTermination(
            $this->learning,
            $this->lifecycle,
            $this->kernel->revokeEntitlement,
            $this->events,
            $this->closeAttempts
        );
        $idempotent = new IdempotentExecution(new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
            $this->env->clock
        ));
        $this->api = new ApiKernel(new NullLogger());
        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations(
                $this->access,
                $this->organisations,
                $this->env->users,
                $this->registries->orgRoles,
                $this->registries->orgTypes,
                $termination,
                $this->registries->invitationMatch
            ),
            $members,
            $this->access,
            $idempotent,
            $this->env->database,
            $this->events
        );
        OrganisationLearningRoutes::register(
            $this->api,
            new ManageOrganisationLearning(
                $this->access,
                $this->organisations,
                $this->learning,
                $this->composed,
                $this->kernel->grantEntitlement,
                $this->kernel->revokeEntitlement,
                $this->events,
                $termination,
                $this->env->users,
                $this->closeAttempts
            ),
            $this->seats,
            $this->access,
            $idempotent,
            $this->env->database,
            $this->events,
            new TransferSeat(
                $this->access,
                $this->organisations,
                $this->learning,
                $this->composed,
                $this->env->users,
                $this->kernel->grantEntitlement,
                $this->kernel->revokeEntitlement,
                $this->events,
                $this->alerts
            )
        );
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', ['title' => 'Synthetic Course', 'slug' => 'synthetic-course',
            'state' => 'published', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->prepare();
    }

    /**
     * One term end, one writer, and the two paths write byte-identical
     * ledgers.
     *
     * The sweep used to hand a live seat to release() with `terminate` set,
     * whose credit comes from the reclaim policy, so a pool whose policy is
     * `before_completion` was credited a seat back at its term end and ended
     * with more availability than it was sold, while the administrator's PATCH
     * to state=expired wrote seat_expired at zero for the identical shape.
     * Both derivations moved together, so seat_reconciliation never saw it.
     */
    public function testTheTermSweepAndTheAdministratorTermEndWriteTheSameLedger(): void
    {
        $swept = $this->fundedPool(2);
        $patched = $this->fundedPool(2);
        $this->assign($swept, 3);
        $this->assign($patched, 3);
        $ends = $this->env->clock->now()->format('Y-m-d H:i:s');
        $scope = $this->scope();
        $this->learning->update('seat_pools', $scope, $swept, ['ends_at' => $ends]);
        $this->learning->update('seat_pools', $scope, $patched, ['ends_at' => $ends]);
        $this->env->clock->advance('PT1H');

        $this->write('/seat-pools/' . $patched, ['state' => SeatPoolState::EXPIRED], 'PATCH');
        self::assertSame(
            'pools_expired=1 seats_disposed=1 substituted=0 skipped=0',
            $this->termSweep()->run($this->taskRun())
        );

        // The same rows with the same deltas and the same reasons. They are
        // compared as a set rather than as a sequence because the sweep is
        // paged and cannot write its pool event until the last held seat is
        // dealt with, so a term end that needs two runs writes the seats
        // first; the accounting is what has to match, and it did not.
        self::assertSame($this->ledgerShape($patched), $this->ledgerShape($swept));
        self::assertSame(
            [[SeatEventType::POOL_CREATED, 0, SeatEventReason::GRANTED_BY_ADMIN],
                [SeatEventType::POOL_EXPIRED, 0, SeatEventReason::EXPIRED_WITH_TERM],
                [SeatEventType::POOL_FUNDED, 2, SeatEventReason::GRANTED_BY_ADMIN],
                [SeatEventType::SEAT_ASSIGNED, -1, SeatEventReason::GRANTED_BY_ADMIN],
                [SeatEventType::SEAT_EXPIRED, 0, SeatEventReason::EXPIRED_WITH_TERM]],
            $this->ledgerShape($swept)
        );
        // The seat stays spent on both. Bulk seat sales are final, so a term
        // end returns nothing to a pool that has ended.
        foreach ([$swept, $patched] as $poolId) {
            $balance = $this->learning->balance($scope, $poolId);
            self::assertSame(1, $balance['available'], 'A term end credits nothing back.');
            self::assertTrue($balance['reconciled']);
        }
    }

    /**
     * A page of already-closed rows cannot stall a pool's term end.
     *
     * The sweep paged HOLDING, which contains forfeited and expired, and
     * dispose() could do nothing with either: a pool whose first page was full
     * of terminal rows was rescanned unchanged every run, the full-page branch
     * took the early return, and the pool never reached `expired` while its
     * live seat kept its access.
     */
    public function testAPoolWhoseFirstPageIsAlreadyTerminalStillEndsItsTerm(): void
    {
        $pool = $this->fundedPool(400);
        $scope = $this->scope();
        $live = $this->assign($pool, 3);

        for ($index = 0; $index < PoolTermSweepTask::BATCH; ++$index) {
            $this->learning->insert('seat_assignments', $scope, [
                'pool_id' => $pool, 'membership_id' => null, 'user_id' => null,
                'state' => SeatAssignmentState::EXPIRED, 'holder_key' => null,
                'assigned_by' => 1, 'assigned_at' => $this->learning->now(),
                'closed_at' => $this->learning->now(),
            ]);
        }

        $this->learning->update('seat_pools', $scope, $pool, [
            'ends_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
        ]);
        $this->env->clock->advance('PT1H');

        self::assertSame(
            'pools_expired=1 seats_disposed=1 substituted=0 skipped=0',
            $this->termSweep()->run($this->taskRun())
        );
        self::assertSame(SeatPoolState::EXPIRED, (string) $this->learning->pool($scope, $pool)['state']);
        self::assertSame(SeatAssignmentState::EXPIRED, (string) $this->assignment($live)['state']);
    }

    /**
     * The as-at read answers about the instant, on both sides.
     *
     * It took the capacity from the pool row as it stands now, so a pool
     * funded with one seat and later raised to five reported a divergence of
     * minus four for every date before the rise, on a pool that is perfectly
     * healthy. This is the read the seat delivery document takes.
     */
    public function testTheAsAtReadTakesItsCapacityFromTheFundingEventsAndNotFromTodaysRow(): void
    {
        $pool = $this->fundedPool(1);
        $january = $this->env->clock->now();
        $this->assign($pool, 3);
        $this->env->clock->advance('P60D');
        $this->write('/seat-pools/' . $pool, ['seats_granted' => 5], 'PATCH');

        $asAt = $this->learning->reconciliationAt($this->scope(), $pool, $january->add(new \DateInterval('PT1H')));

        self::assertSame(1, $asAt['capacity'], 'The capacity is the funding at the instant, not the funding today.');
        self::assertSame(1, $asAt['held']);
        self::assertSame(0, $asAt['available']);
        self::assertSame(0, $asAt['divergence']);
        self::assertTrue($asAt['reconciled']);
        // And today still answers about today.
        self::assertSame(5, $this->learning->balance($this->scope(), $pool)['capacity']);
    }

    /**
     * An over-allocation row that was queued at the instant held no seat then.
     *
     * The read added a count of today's HOLDING states to a count of today's
     * TERMINAL states. forfeited and expired are in both lists, so they were
     * counted twice, and a pending row that was later cancelled was counted as
     * having held a seat it never held: a pool of one with one seat assigned
     * and one person queued answered held 2, available -1 and a divergence of
     * 1 on a pool that reconciles perfectly at now.
     */
    public function testAQueuedOverAllocationRowIsNotCountedAsHavingHeldASeat(): void
    {
        $pool = $this->fundedPool(1);
        $this->assign($pool, 3);
        $queued = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        self::assertSame('pending_capacity', $queued['outcome']);
        $instant = $this->env->clock->now()->add(new \DateInterval('PT1H'));
        $this->env->clock->advance('P1D');
        $this->write('/seat-assignments/' . $queued['item']['id'] . '/release', []);

        $asAt = $this->learning->reconciliationAt($this->scope(), $pool, $instant);

        self::assertSame(1, $asAt['held']);
        self::assertSame(0, $asAt['available']);
        self::assertSame(0, $asAt['divergence']);
        self::assertTrue($asAt['reconciled']);
    }

    /**
     * A forfeited seat is still spent at every later instant.
     *
     * The as-at read has to keep counting it, because seat_forfeited and
     * seat_expired carry a zero delta: a row that stopped being counted the
     * moment it closed would make the two sides disagree by every forfeited
     * seat.
     */
    public function testAForfeitedSeatStaysCountedAtEveryLaterInstant(): void
    {
        $pool = $this->fundedPool(2, 'never');
        $assignment = $this->assign($pool, 3);
        $this->env->clock->advance('P1D');
        $this->write('/seat-assignments/' . $assignment . '/release', []);
        self::assertSame(SeatAssignmentState::FORFEITED, (string) $this->assignment($assignment)['state']);

        $asAt = $this->learning->reconciliationAt($this->scope(), $pool, $this->env->clock->now()->add(new \DateInterval('P7D')));

        self::assertSame(1, $asAt['held']);
        self::assertSame(1, $asAt['available']);
        self::assertTrue($asAt['reconciled']);
    }

    /**
     * One person, one seat, whether they were invited or added.
     *
     * A reservation taken for somebody with no account yet carries a null
     * user_id, so the assigning route's own lookup could not see it: the
     * person signed up, an administrator added them, and a two seat pool was
     * exhausted by one person. The acceptance then lost the unique index on
     * holder_key and answered a 500, so the invitation stayed pending for ever
     * and the second seat was never returned.
     */
    public function testAnInvitationHoldingASeatRefusesADirectAssignmentToTheSameAddress(): void
    {
        $pool = $this->fundedPool(2);
        $this->issue('newhire@example.test', $pool);
        $scope = $this->scope();
        self::assertSame(1, $this->learning->balance($scope, $pool)['held']);

        // The account arrives after the invitation was sent, which is the
        // normal case: an invitation is how somebody with no account joins.
        $this->env->users->add(9, 'New Hire', 'newhire@example.test');
        $this->organisations->addMember($scope, 9, 'org_member', 'manual', null);

        $refused = $this->request('POST', '/manage/organisations/1/seat-pools/' . $pool . '/assignments', [
            'expected_revision' => $this->revision(), 'user_id' => 9,
        ]);

        self::assertSame(409, $refused->status);
        self::assertIsArray($refused->data);
        self::assertStringContainsString('already holding a seat', (string) ($refused->data['detail'] ?? ''));
        $balance = $this->learning->balance($scope, $pool);
        self::assertSame(1, $balance['held'], 'One promise, one seat.');
        self::assertSame(1, $balance['available']);
        self::assertTrue($balance['reconciled']);
    }

    /**
     * The ceremony does not burn an attempt for somebody who keeps their
     * access.
     *
     * It called close() unconditionally before the revoke, while the revoke
     * itself asks whether any other grant is still effective before it touches
     * an attempt. A learner holding a personal grant beside their seat had the
     * attempt force-submitted and graded at minute forty and never lost
     * access.
     */
    public function testAReleaseDoesNotCloseAnAttemptTheLearnerStillHasAccessFor(): void
    {
        $pool = $this->fundedPool(2);
        $assignment = $this->assign($pool, 3);
        $this->manualGrant(3);
        $attempt = $this->openAttempt(3);

        $this->write('/seat-assignments/' . $assignment . '/release', []);

        $row = $this->attempt($attempt);
        self::assertSame(Attempt::STATUS_IN_PROGRESS, (string) $row['status']);
        self::assertNull($row['closure_reason']);
        self::assertNull($row['closed_by_seat_event_id']);
    }

    /**
     * And it does close one when the seat was the only access.
     *
     * The other half of the same question, so the narrowing cannot become a
     * ceremony that never runs.
     */
    public function testAReleaseStillClosesAnAttemptWhenTheSeatWasTheOnlyAccess(): void
    {
        $pool = $this->fundedPool(2);
        $assignment = $this->assign($pool, 3);
        $attempt = $this->openAttempt(3);

        $this->write('/seat-assignments/' . $assignment . '/release', []);

        $row = $this->attempt($attempt);
        self::assertSame('graded', (string) $row['status']);
        self::assertSame(SeatEventReason::RECLAIMED_BY_ADMIN, (string) $row['closure_reason']);
        self::assertNotNull($row['closed_by_seat_event_id']);
    }

    /**
     * The free organisation grant revoke runs the same ceremony.
     *
     * It called RevokeEntitlement directly with no disposition and no closure,
     * so the last-access branch abandoned the attempt outright: answers kept,
     * no grade, and nothing on the row saying who ended it. That is the
     * stranded ungraded attempt the ceremony exists to remove, surviving on
     * the one revoking route the seat work did not cover.
     */
    public function testTheOrganisationGrantRevokeGradesTheAttemptItEnds(): void
    {
        $granted = $this->write('/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        $attempt = $this->openAttempt(3);

        $this->write('/learning/' . $granted['entitlement_id'] . '/revoke', []);

        $row = $this->attempt($attempt);
        self::assertSame('graded', (string) $row['status']);
        self::assertSame(ManageOrganisationLearning::REVOKED_REASON, (string) $row['closure_reason']);
        // Null, because a direct grant spends no seat and writes no ledger
        // row: inventing one would put a number in the ledger nothing depends
        // on.
        self::assertNull($row['closed_by_seat_event_id']);
    }

    /**
     * An invitation whose reservation a sweep released is still acceptable and
     * still re-issuable.
     *
     * The reservation sweep cancels the reservation and leaves the invitation
     * pending with days to run, so the invitee passed both gates, received a
     * proof code, and then hit "No seat reservation here." from inside the
     * acceptance transaction. The administrator's repair, reissuing into the
     * same pool, threw the same exception and rolled the whole issue back.
     */
    public function testAnInvitationOutlivesTheReservationASweepReleased(): void
    {
        $pool = $this->fundedPool(1);
        $token = $this->issue('user2@example.test', $pool);
        $this->env->clock->advance('P8D');
        $sweep = new ReservationExpiryTask($this->records, $this->authority, $this->organisations, $this->reservations);
        self::assertSame('released=1 skipped=0', $sweep->run($this->taskRun()));
        // The link goes with the seat, so the console stops showing a promise
        // the pool is not holding.
        self::assertNull($this->invitation('user2@example.test')['seat_assignment_id']);
        $this->env->database->update('invitations', ['expires_at' => '2099-01-01 00:00:00'], ['email' => 'user2@example.test']);

        $claim = $this->invitations->claim(Principal::user(2), $token);
        $accepted = $this->invitations->accept(Principal::user(2), (int) $claim['claim_id'], $this->delivery->lastSecret());

        self::assertSame('active', $accepted['state']);
        self::assertNull($accepted['seat_assignment_id'], 'The membership completes with no seat.');
        self::assertSame(1, $this->learning->balance($this->scope(), $pool)['available']);
    }

    /**
     * And the repair works: reissuing into the same pool mints a fresh
     * reservation instead of throwing.
     */
    public function testReissuingAfterTheReservationSweepTakesAFreshSeat(): void
    {
        $pool = $this->fundedPool(1);
        $this->issue('user2@example.test', $pool);
        $this->env->clock->advance('P8D');
        (new ReservationExpiryTask($this->records, $this->authority, $this->organisations, $this->reservations))
            ->run($this->taskRun());

        $this->issue('user2@example.test', $pool);

        $row = $this->invitation('user2@example.test');
        self::assertNotNull($row['seat_assignment_id']);
        self::assertSame(SeatAssignmentState::RESERVED, (string) $this->assignment((int) $row['seat_assignment_id'])['state']);
        $balance = $this->learning->balance($this->scope(), $pool);
        self::assertSame(0, $balance['available']);
        self::assertTrue($balance['reconciled']);
    }

    /**
     * A reissue naming no pool does not relink a cancelled reservation.
     *
     * It returned the held assignment id unchanged, so the invitation went
     * pending again still naming a row the pool was not holding, and the
     * acceptance then failed on it.
     */
    public function testAReissueNamingNoPoolDropsAStaleReservationLink(): void
    {
        $pool = $this->fundedPool(2);
        $this->issue('user2@example.test', $pool);
        $this->env->clock->advance('P8D');
        (new ReservationExpiryTask($this->records, $this->authority, $this->organisations, $this->reservations))
            ->run($this->taskRun());

        $this->issue('user2@example.test');

        self::assertNull($this->invitation('user2@example.test')['seat_assignment_id']);
        self::assertSame(2, $this->learning->balance($this->scope(), $pool)['available']);
    }

    /**
     * An expired invitation is revived rather than duplicated.
     *
     * issue() found the existing row by pending_key, which the expiry sweep
     * nulls, so a reissue inserted a second row: one address, one
     * organisation, two invitations. The published machine draws expired to
     * pending as a reissue that reuses the same row precisely so a re-uploaded
     * corrected CSV cannot mail everybody twice.
     */
    public function testAReissueAfterAnExpiryReusesTheSameInvitationRow(): void
    {
        $this->issue('user2@example.test');
        $this->env->database->update(
            'invitations',
            ['state' => 'expired', 'pending_key' => null, 'token_hash' => null],
            ['email' => 'user2@example.test']
        );

        $this->issue('user2@example.test');

        self::assertSame(1, $this->env->database->count(Query::from('invitations')
            ->where('organisation_id', '=', 1)->where('email', '=', 'user2@example.test')));
        $row = $this->invitation('user2@example.test');
        self::assertSame('pending', (string) $row['state']);
        self::assertSame(2, (int) $row['revision']);
    }

    /**
     * A pool is created in a state the machine can reach from draft.
     *
     * The edge guard was conditioned on there being a previous row, so it ran
     * on every edit and on no creation: a pool could be written straight into
     * `expired` or `suspended`, states permits() answers false for, and be
     * funded on the way in with no entry event to say when.
     */
    public function testAPoolCannotBeCreatedInAStateTheMachineCannotReach(): void
    {
        foreach ([SeatPoolState::EXPIRED, SeatPoolState::SUSPENDED, SeatPoolState::CLOSED] as $state) {
            $refused = $this->request('POST', '/manage/organisations/1/seat-pools', [
                'expected_revision' => $this->revision(), 'course_id' => 1,
                'name' => 'Refused ' . $state, 'seats_granted' => 5, 'state' => $state,
            ]);

            self::assertSame(422, $refused->status, $state);
        }

        self::assertSame(0, $this->env->database->count(Query::from('seat_pools')));

        foreach (SeatPoolState::CREATABLE as $state) {
            $created = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Fine ' . $state,
                'seats_granted' => 5, 'state' => $state], 'POST');
            self::assertSame($state, (string) $created['item']['state']);
        }
    }

    /**
     * A draft pool is not funded, and entering active is what funds it.
     *
     * pool_funded was written at creation whatever state the pool was created
     * in, so a draft carrying a quantity nobody had paid for already read as
     * funded, and moving it to awaiting_payment and then active wrote no
     * ledger row at all: the settlement left no trace and an abandoned draft
     * carried a funding row for money never taken.
     */
    public function testAPoolIsFundedWhenItBecomesActiveAndNotWhenItIsDrafted(): void
    {
        $draft = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Draft pool', 'seats_granted' => 10,
            'state' => SeatPoolState::DRAFT], 'POST');
        $pool = (int) $draft['item']['id'];

        self::assertSame([[SeatEventType::POOL_CREATED, 0, SeatEventReason::GRANTED_BY_ADMIN]], $this->ledgerShape($pool));
        self::assertSame(0, $draft['balance']['capacity']);
        self::assertTrue($draft['balance']['reconciled']);

        $this->write('/seat-pools/' . $pool, ['state' => SeatPoolState::AWAITING_PAYMENT], 'PATCH');
        self::assertCount(1, $this->ledgerShape($pool));

        $active = $this->write('/seat-pools/' . $pool, ['state' => SeatPoolState::ACTIVE], 'PATCH');
        self::assertSame([[SeatEventType::POOL_CREATED, 0, SeatEventReason::GRANTED_BY_ADMIN],
            [SeatEventType::POOL_FUNDED, 10, SeatEventReason::GRANTED_BY_ADMIN]], $this->ledgerShape($pool));
        self::assertSame(10, $active['balance']['capacity']);
        self::assertTrue($active['balance']['reconciled']);
    }

    /**
     * A pool holding seats cannot be closed.
     *
     * savePool checked only the edge, and the published guards on the edges
     * into closed were implemented nowhere: the assignment stayed assigned,
     * its entitlement stayed active, and closed is terminal, so nothing in the
     * free build would ever dispose of that seat again.
     */
    public function testAPoolStillHoldingASeatCannotBeClosed(): void
    {
        $pool = $this->fundedPool(2);
        $assignment = $this->assign($pool, 3);

        $refused = $this->request('PATCH', '/manage/organisations/1/seat-pools/' . $pool, [
            'expected_revision' => $this->revision(), 'state' => SeatPoolState::CLOSED,
        ]);

        self::assertSame(409, $refused->status);
        self::assertSame(SeatPoolState::ACTIVE, (string) $this->learning->pool($this->scope(), $pool)['state']);

        // Ending the term first disposes of the seat, and then it closes.
        $this->write('/seat-pools/' . $pool, ['state' => SeatPoolState::EXPIRED], 'PATCH');
        self::assertSame(SeatAssignmentState::EXPIRED, (string) $this->assignment($assignment)['state']);
        $closed = $this->write('/seat-pools/' . $pool, ['state' => SeatPoolState::CLOSED], 'PATCH');
        self::assertSame(SeatPoolState::CLOSED, (string) $closed['item']['state']);
    }

    /**
     * A direct assignment that empties a pool raises the alert that afternoon.
     *
     * afterBalanceChange had exactly one caller, the reservation path, so an
     * administrator who filled the last seat by direct assignment (the primary
     * write path) heard nothing until the next day's reconciliation pass.
     */
    public function testADirectAssignmentThatExhaustsAPoolRaisesTheAlert(): void
    {
        $pool = $this->fundedPool(1);
        $this->assign($pool, 3);

        self::assertGreaterThan(0, $this->kernel->notificationFanout->run());
        self::assertSame(1, $this->env->database->count(Query::from('notification_queue')
            ->where('trigger_key', '=', SeatThresholdTrigger::KEY_EXHAUSTED)->whereNull('recipient_user_id')));
    }

    /**
     * The assignment state machine is consulted by the writer every state
     * change goes through.
     *
     * permits() had zero call sites anywhere in the repository, so the table
     * was documentation: the progress listener wrote assigned to completed, an
     * edge the table forbade, and nothing noticed.
     */
    public function testTheAssignmentStateMachineIsEnforcedAndCompletionIsARealEdge(): void
    {
        $pool = $this->fundedPool(2);
        $assignment = $this->assign($pool, 3);
        $scope = $this->scope();

        // A course completed with no lesson and no attempt event first, which
        // is what the listener writes and what the table used to forbid.
        $enrolment = $this->env->database->selectOne(Query::from('enrolments')
            ->where('user_id', '=', 3)->where('course_id', '=', 1));
        self::assertNotNull($enrolment);
        $this->kernel->events->dispatch(new CourseCompleted($this->env->clock->now(), (int) $enrolment['id'], 1, 3));
        self::assertSame(SeatAssignmentState::COMPLETED, (string) $this->assignment($assignment)['state']);
        self::assertTrue(SeatAssignmentState::permits(SeatAssignmentState::ASSIGNED, SeatAssignmentState::COMPLETED));

        // And an edge the machine does not draw is refused by the store rather
        // than written.
        $this->expectException(ConflictException::class);
        $this->learning->update('seat_assignments', $scope, $assignment, ['state' => SeatAssignmentState::RESERVED]);
    }

    /**
     * A registered expand_pool stance actually raises the pool.
     *
     * expandBy was read by nothing and no quantity event was written, so the
     * stance the design names as one of the three defensible ones degraded
     * silently into allow_and_flag with the pool one seat over its funded
     * count and a negative availability.
     */
    public function testARegisteredExpandPoolStanceRaisesTheQuantityAndWritesTheEvent(): void
    {
        $this->registries->seatOverAllocation->register(new class () implements SeatOverAllocationInterface {
            public function key(): string
            {
                return 'acme.expand';
            }

            public function label(): string
            {
                return 'acme.expand';
            }

            public function decide(SeatDecisionContext $context): SeatOverAllocationOutcome
            {
                return SeatOverAllocationOutcome::expandPool(5);
            }
        });
        $pool = $this->fundedPool(1);
        $this->learning->update('seat_pools', $this->scope(), $pool, ['over_allocation_key' => 'acme.expand']);
        $this->assign($pool, 3);

        $second = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);

        self::assertSame('assigned', $second['outcome']);
        self::assertSame(6, (int) $this->learning->pool($this->scope(), $pool)['seats_granted']);
        self::assertSame(1, $this->env->database->count(Query::from('seat_events')
            ->where('pool_id', '=', $pool)->where('event_key', '=', SeatEventType::POOL_QUANTITY_INCREASED)));
        $balance = $this->learning->balance($this->scope(), $pool);
        self::assertSame(4, $balance['available']);
        self::assertTrue($balance['reconciled']);
    }

    /**
     * A provider's configuration can be written through the free route.
     *
     * Every provider read scope_params and policy_params and nothing wrote
     * either: the save allowlist did not carry the names, so a third-party or
     * Business provider could not be configured without a core edit or direct
     * SQL.
     */
    public function testAProvidersConfigurationIsWritableThroughTheSaveRoute(): void
    {
        $created = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Configured pool', 'seats_granted' => 2,
            'scope_params' => ['course_ids' => [1, 2]], 'policy_params' => ['queue_limit' => 20]], 'POST');
        $pool = $this->learning->pool($this->scope(), (int) $created['item']['id']);

        self::assertSame(['course_ids' => [1, 2]], OrganisationLearningStore::params($pool, 'scope_params')[0]);
        self::assertSame(1, OrganisationLearningStore::params($pool, 'scope_params')[1]);
        self::assertSame(['queue_limit' => 20], OrganisationLearningStore::params($pool, 'policy_params')[0]);

        // A list is not an object, and a document nested past the bound is
        // somebody storing a different thing in the wrong column.
        $refused = $this->request('POST', '/manage/organisations/1/seat-pools', [
            'expected_revision' => $this->revision(), 'course_id' => 1, 'name' => 'Bad params',
            'seats_granted' => 1, 'policy_params' => [1, 2, 3],
        ]);
        self::assertSame(422, $refused->status);
    }

    /**
     * An organisation chooses its own invitation match policy.
     *
     * All three call sites named the registry's compile-time DEFAULT_KEY, so a
     * registered provider recorded zero calls across a real issue, claim and
     * accept: the seam was registrable and not selectable.
     */
    public function testAnOrganisationSelectsItsOwnInvitationMatchPolicy(): void
    {
        $policy = new class () implements InvitationMatchPolicyInterface {
            public int $calls = 0;

            public function key(): string
            {
                return 'acme.domain_match';
            }

            public function label(): string
            {
                return 'acme.domain_match';
            }

            public function matches(InvitationMatchContext $context): bool
            {
                ++$this->calls;

                return true;
            }
        };
        $this->registries->invitationMatch->register($policy);
        $this->write('', ['invitation_match_key' => 'acme.domain_match'], 'PATCH');
        self::assertSame(
            'acme.domain_match',
            InvitationMatchRegistry::keyFor($this->organisations->organisation($this->scope()))
        );

        $token = $this->issue('user2@example.test');
        // A forwarded token, which strict_email refuses and this policy admits,
        // so the call is proved by its effect and not only by its counter.
        self::assertIsInt($this->invitations->claim(Principal::user(3), $token)['claim_id']);
        self::assertGreaterThan(0, $policy->calls);

        // An unresolved key is refused at the save rather than stored.
        $refused = $this->request('PATCH', '/manage/organisations/1', [
            'expected_revision' => $this->revision(), 'invitation_match_key' => 'acme.not_installed',
        ]);
        self::assertSame(422, $refused->status);
    }

    /**
     * A registered reason key can be written by a free route.
     *
     * release() passed a literal and no route input named a reason, so a
     * third-party provider was resolved correctly on the ledger read and
     * nothing shipped could ever write one.
     */
    public function testAReleaseCanCarryARegisteredReasonKeyAndRefusesAnUnknownOne(): void
    {
        $pool = $this->fundedPool(2);
        $assignment = $this->assign($pool, 3);

        $released = $this->write('/seat-assignments/' . $assignment . '/release', [
            'reason_key' => SeatEventReason::REVOKED_REFUND,
        ]);
        self::assertSame(SeatEventReason::REVOKED_REFUND, $released['reason_key']);
        $row = $this->env->database->selectOne(Query::from('seat_events')
            ->where('assignment_id', '=', $assignment)->where('event_key', '=', SeatEventType::SEAT_RECLAIMED));
        self::assertNotNull($row);
        self::assertSame(SeatEventReason::REVOKED_REFUND, (string) $row['reason_key']);

        $second = $this->assign($pool, 4);
        $refused = $this->request('POST', '/manage/organisations/1/seat-assignments/' . $second . '/release', [
            'expected_revision' => $this->revision(), 'reason_key' => 'acme.not_installed',
        ]);
        self::assertSame(422, $refused->status);
    }

    /**
     * The reason providers answer a group, and the shape is settled.
     *
     * The interface's docblock still said nothing implemented it and that the
     * shape might still be corrected, in the same release as eleven free
     * providers: the grouping the same paragraph promised could never have
     * been added afterwards without breaking the no-new-method rule.
     */
    public function testEveryFreeReasonAnswersAPublishedGroup(): void
    {
        foreach ($this->registries->seatEventReasons->keys() as $key) {
            self::assertContains(
                $this->registries->seatEventReasons->get($key)->group(),
                SeatEventReason::GROUPS_LIST,
                $key
            );
        }

        self::assertSame(SeatEventReason::GROUP_COMMERCE, SeatEventReason::group(SeatEventReason::REVOKED_REFUND));
        self::assertSame(SeatEventReason::GROUP_MEMBERSHIP, SeatEventReason::group(SeatEventReason::MEMBER_LEFT_ORG));
        self::assertSame(SeatEventReason::GROUP_LIFECYCLE, SeatEventReason::group('acme.partner_transfer'));
    }

    /**
     * The ledger's idempotency claim matches the index that enforces it.
     *
     * The lookup narrowed by organisation and the unique index does not, so a
     * key repeated across two organisations was invisible to the select and
     * refused by the index, and the loser of that race raised an uncaught
     * violation. A replay under one key is still one row and still answers the
     * id of the row that won.
     */
    public function testALedgerKeyIsClaimedOnceAndACrossTenantReuseIsRefused(): void
    {
        $pool = $this->fundedPool(2);
        $scope = $this->scope();
        $first = $this->learning->ledger(
            $scope,
            $this->revision(),
            $pool,
            null,
            SeatEventType::POOL_SUSPENDED,
            0,
            SeatEventReason::GRANTED_BY_ADMIN,
            null,
            null,
            [],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            'review:one'
        );
        $replay = $this->learning->ledger(
            $scope,
            $this->revision(),
            $pool,
            null,
            SeatEventType::POOL_SUSPENDED,
            0,
            SeatEventReason::GRANTED_BY_ADMIN,
            null,
            null,
            [],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            'review:one'
        );

        self::assertSame($first, $replay);
        self::assertSame(1, $this->env->database->count(Query::from('seat_events')
            ->where('idempotency_key', '=', 'review:one')));

        $second = $this->organisations->create(['name' => 'Globex', 'slug' => 'globex', 'created_by' => 1]);
        $otherScope = $this->access->require(Principal::user(1), $second, 'seats');
        $otherPool = $this->learning->insert('seat_pools', $otherScope, ['course_id' => 1, 'name' => 'Other',
            'seats_granted' => 1, 'state' => SeatPoolState::ACTIVE, 'funded_at' => $this->learning->now(), 'created_by' => 1]);

        $this->expectException(ConflictException::class);
        $this->learning->ledger(
            $otherScope,
            1,
            $otherPool,
            null,
            SeatEventType::POOL_SUSPENDED,
            0,
            SeatEventReason::GRANTED_BY_ADMIN,
            null,
            null,
            [],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            'review:one'
        );
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    private function ledgerShape(int $poolId): array
    {
        $rows = array_map(
            static fn (array $row): array => [(string) $row['event_key'], (int) $row['seat_delta'], (string) $row['reason_key']],
            $this->env->database->selectAll(Query::from('seat_events')->where('pool_id', '=', $poolId)->orderBy('id'))
        );
        sort($rows);

        return $rows;
    }

    private function termSweep(): PoolTermSweepTask
    {
        return new PoolTermSweepTask(
            $this->records,
            $this->authority,
            $this->organisations,
            $this->learning,
            $this->lifecycle,
            $this->registries->seatExpiryDisposition,
            $this->env->clock
        );
    }

    private function fundedPool(int $seats, string $policy = 'before_completion'): int
    {
        $result = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Pool ' . ++$this->sequence,
            'seats_granted' => $seats, 'reclaim_policy' => $policy], 'POST');
        self::assertIsInt($result['item']['id']);

        return $result['item']['id'];
    }

    private function assign(int $poolId, int $userId): int
    {
        $result = $this->write('/seat-pools/' . $poolId . '/assignments', ['user_id' => $userId]);
        self::assertSame('assigned', $result['outcome']);

        return (int) $result['item']['id'];
    }

    private function manualGrant(int $userId): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('entitlements', ['user_id' => $userId, 'course_id' => 1, 'source' => 'manual',
            'status' => 'active', 'granted_by' => 1, 'starts_at' => null, 'expires_at' => null,
            'created_at' => $now, 'updated_at' => $now]);
    }

    private function openAttempt(int $userId): int
    {
        $dates = ['created_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s')];
        $moduleId = $this->env->database->insert('modules', ['course_id' => 1, 'title' => 'Synthetic module',
            'position' => 1, 'state' => 'published'] + $dates);
        $lessonId = $this->env->database->insert('lessons', ['course_id' => 1, 'module_id' => $moduleId,
            'title' => 'Synthetic lesson', 'slug' => 'synthetic-quiz-' . $userId, 'type' => 'quiz', 'position' => 1,
            'state' => 'published'] + $dates);
        $quizId = $this->env->database->insert('quizzes', ['course_id' => 1, 'lesson_id' => $lessonId,
            'title' => 'Synthetic quiz'] + $dates);
        $enrolment = $this->env->database->selectOne(Query::from('enrolments')
            ->where('user_id', '=', $userId)->where('course_id', '=', 1));
        self::assertNotNull($enrolment);

        return $this->env->database->insert('attempts', ['quiz_id' => $quizId, 'user_id' => $userId,
            'enrolment_id' => (int) $enrolment['id'], 'attempt_number' => 1,
            'status' => Attempt::STATUS_IN_PROGRESS, 'started_at' => $dates['created_at']] + $dates);
    }

    /** @return array<string, mixed> */
    private function attempt(int $id): array
    {
        $row = $this->env->database->selectOne(Query::from('attempts')->where('id', '=', $id));
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

    /** @return array<string, mixed> */
    private function invitation(string $email): array
    {
        $row = $this->env->database->selectOne(Query::from('invitations')
            ->where('organisation_id', '=', 1)->where('email', '=', $email));
        self::assertNotNull($row);

        return $row;
    }

    private function issue(string $email, ?int $poolId = null): string
    {
        $body = ['expected_revision' => $this->revision(), 'email' => $email, 'role_key' => 'org_member'];

        if ($poolId !== null) {
            $body['pool_id'] = $poolId;
        }

        $this->invitations->issue(Principal::user(1), 1, $body);

        return $this->delivery->lastSecret();
    }

    private function scope(): OrgScope
    {
        return $this->access->require(Principal::user(1), 1, 'seats');
    }

    private function revision(): int
    {
        return (int) $this->organisations->organisation($this->scope())['revision'];
    }

    private function taskRun(): ScheduledTaskRun
    {
        return new ScheduledTaskRun(1, [], Principal::user(1));
    }

    private function prepare(): void
    {
        self::assertSame(201, $this->request('POST', '/manage/organisations', [
            'name' => 'Synthetic 1', 'slug' => 'synthetic-1',
        ])->status);

        foreach ([3, 4] as $user) {
            $this->write('/members', ['user_id' => $user, 'role_key' => 'org_member'], 'POST', 201);
        }

        $this->write('/releases', ['course_id' => 1]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function write(string $suffix, array $body = [], string $method = 'POST', int $expectedStatus = 200): array
    {
        $response = $this->request($method, '/manage/organisations/1' . $suffix, ['expected_revision' => $this->revision()] + $body);
        self::assertSame($expectedStatus, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        return $response->data;
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest(
            $method,
            $path,
            Principal::user(1),
            [],
            $body,
            'seat-review-' . ++$this->sequence
        ));
    }
}
