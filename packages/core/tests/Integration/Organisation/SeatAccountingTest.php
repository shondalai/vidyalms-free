<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\CloseAttemptsOnAccessLoss;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccessTermination;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatOverAllocationOutcome;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The seat subsystem's accounting, its states and the revocation ceremony.
 *
 * Every test here fails on the wave's starting point for a stated reason:
 * four of the five seat registries had no provider and no consumer, four pool
 * states and four assignment states had no writer, the ledger's two clocks
 * carried one value, and a reclaim mid-attempt left the learner's answers
 * stranded with no grade.
 */
final class SeatAccountingTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private Registries $registries;
    private OrganisationLearningStore $store;
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

        foreach ([1, 2, 3, 4] as $id) {
            $this->env->users->add($id, 'Synthetic ' . $id, 'synthetic-' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->kernel = new CoreKernel($this->env->ports);
        $this->registries = new Registries();
        CoreOrganisationProviders::seed($this->registries);
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $this->registries->orgRoles);
        $orgAccess = new OrganisationAccess($access);
        $this->store = new OrganisationLearningStore($this->env->database, $this->env->clock, $this->env->ports->random);
        $events = new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events);
        $composed = new ComposedAccess($orgAccess, new CourseReleasePolicy($this->env->database, $this->env->clock));
        // The ceremony is wired exactly as the kernel wires it, because a
        // harness that left it out would prove the old behaviour.
        $closeAttempts = new CloseAttemptsOnAccessLoss($this->env->database, $this->kernel->submitAttempt, $events);
        $lifecycle = new SeatLifecycle(
            $this->store,
            $this->registries->seatReclaim,
            $this->kernel->revokeEntitlement,
            $events,
            $this->env->clock,
            $closeAttempts
        );
        $termination = new OrganisationAccessTermination(
            $this->store,
            $lifecycle,
            $this->kernel->revokeEntitlement,
            $events,
            $closeAttempts
        );
        $seats = new ManageOrganisationSeats(
            $access,
            $store,
            $this->store,
            $composed,
            $this->kernel->grantEntitlement,
            $events,
            $lifecycle,
            $this->env->users,
            $this->registries->seatReclaim,
            $this->registries->seatScopes,
            $this->registries->seatOverAllocation,
            $this->registries->seatExpiryDisposition,
            $this->registries->seatEventReasons
        );
        $idempotent = new IdempotentExecution(new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
            $this->env->clock
        ));
        $this->api = new ApiKernel(new NullLogger());
        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations(
                $access,
                $store,
                $this->env->users,
                $this->registries->orgRoles,
                $this->registries->orgTypes,
                $termination
            ),
            new ManageOrganisationMembers(
                $orgAccess,
                $store,
                $this->registries->orgRoles,
                $this->env->users,
                $this->env->translator,
                $termination
            ),
            $access,
            $idempotent,
            $this->env->database,
            $events
        );
        $learning = new ManageOrganisationLearning(
            $access,
            $store,
            $this->store,
            $composed,
            $this->kernel->grantEntitlement,
            $this->kernel->revokeEntitlement,
            $events,
            $termination,
            $this->env->users
        );
        OrganisationLearningRoutes::register($this->api, $learning, $seats, $access, $idempotent, $this->env->database, $events);
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', ['title' => 'Synthetic Course', 'slug' => 'synthetic-course',
            'state' => 'published', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->prepare();
    }

    /**
     * The four registries R1 seated now have free providers, and every one of
     * them is reached from a free route.
     *
     * Seating a registry nothing consults is a seam with a wall behind it,
     * which is what four of these five were: SeatScope, SeatOverAllocation,
     * SeatExpiryDisposition and SeatEventReason existed as empty registries
     * with label-only interfaces and no code path anywhere asked them
     * anything.
     */
    public function testTheFourSeatRegistriesHaveFreeProvidersAndAFreeRouteReachesEachOfThem(): void
    {
        self::assertSame(['course', 'programme'], $this->registries->seatScopes->keys());
        self::assertSame(['refuse_and_queue'], $this->registries->seatOverAllocation->keys());
        self::assertSame(['lapse_access_keep_progress'], $this->registries->seatExpiryDisposition->keys());
        // Eleven since the review: returned_but_not_credited is the reason on
        // the assigned-to-forfeited and consumed-to-forfeited edges, which had
        // no writer at all while the release ceremony threw instead.
        self::assertCount(11, $this->registries->seatEventReasons->keys());

        $pool = $this->pool();
        // SeatScope, on the assigning route: the pool's key and its arity
        // decide whether a seat may be spent on this course at all.
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        self::assertSame('assigned', $assigned['outcome']);

        // SeatOverAllocation, on the same route once the pool is short.
        $queued = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        self::assertSame('pending_capacity', $queued['outcome']);
        self::assertSame('CountViolation', $queued['item']['problem_code']);

        // SeatEventReason, on the ledger read: the label travels beside the
        // rows and an unresolved key answers null rather than disappearing.
        $ledger = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger');
        self::assertSame('org.seat_reason.granted_by_admin', $ledger['reason_labels']['granted_by_admin']);

        // SeatExpiryDisposition, on the pool save that ends the term.
        $expired = $this->write('/seat-pools/' . $pool, ['state' => 'expired'], 'PATCH');
        self::assertSame(2, $expired['expired_assignments']);
        self::assertSame('expired', $expired['item']['state']);
    }

    /**
     * A pool whose scope provider is not installed writes no assignment, and
     * says which provider it needed.
     *
     * This is the published miss direction for SeatScope and it is the one
     * that fails closed at the pool: assuming one seat per course is how a
     * catalogue pool goes silently over its purchased count.
     */
    public function testAnUnresolvedSeatScopeRefusesTheAssignmentAndNamesTheProvider(): void
    {
        $pool = $this->pool();
        $this->env->database->update('seat_pools', ['scope_key' => 'acme.catalogue'], ['id' => $pool]);

        $refused = $this->request('POST', '/manage/organisations/1/seat-pools/' . $pool . '/assignments', [
            'expected_revision' => $this->revision(), 'user_id' => 3,
        ]);

        self::assertSame(409, $refused->status);
        self::assertIsArray($refused->data);
        self::assertStringContainsString('acme.catalogue', (string) ($refused->data['detail'] ?? ''));
        self::assertSame(0, $this->env->database->count(Query::from('seat_assignments')));
    }

    /**
     * A registered over-allocation provider decides, and the free one is not
     * privileged.
     *
     * The stance was a hardcoded comparison, so the two Business stances
     * could not exist without editing the assigning use case. Here a
     * third-party provider seats somebody the pool has no room for, which is
     * the Enterprise Agreement behaviour, and nothing in the core changed to
     * allow it.
     */
    public function testARegisteredOverAllocationProviderDecidesInsteadOfTheHardcodedRefusal(): void
    {
        $this->registries->seatOverAllocation->register(new class () implements SeatOverAllocationInterface {
            public function key(): string
            {
                return 'acme.allow_and_flag';
            }

            public function label(): string
            {
                return 'acme.allow_and_flag';
            }

            public function decide(SeatDecisionContext $context): SeatOverAllocationOutcome
            {
                return SeatOverAllocationOutcome::allowAndFlag();
            }
        });

        $pool = $this->pool();
        $this->env->database->update('seat_pools', ['over_allocation_key' => 'acme.allow_and_flag'], ['id' => $pool]);
        $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $second = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);

        self::assertSame('assigned', $second['outcome']);
        self::assertSame('OverBaseline', $second['item']['problem_code']);
        // Both seats are held, so the pool is one over its funded count and
        // the ledger says so rather than hiding it.
        self::assertSame(2, $second['balance']['held']);
        self::assertSame(-1, $second['balance']['available']);
        self::assertTrue($second['balance']['reconciled']);
    }

    /**
     * The four unreachable pool states are reachable, their edges are
     * guarded, and each writes the event the published vocabulary names.
     *
     * The saving use case accepted active and suspended only, so a pool
     * waiting on a purchase order, one a seller had put on hold, one whose
     * term had ended and one an administrator had closed were all indis
     * tinguishable, and every edit wrote one generic pool_updated row.
     */
    public function testTheFourUnreachablePoolStatesAreReachableWithGuardedEdgesAndDesignedEvents(): void
    {
        $draft = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Ordered pool',
            'seats_granted' => 0, 'seats_purchased' => 5, 'state' => 'draft']);
        $pool = (int) $draft['item']['id'];
        self::assertSame('draft', $draft['item']['state']);

        // A draft pool cannot jump to expired: the edge does not exist.
        $refused = $this->request('PATCH', '/manage/organisations/1/seat-pools/' . $pool, [
            'expected_revision' => $this->revision(), 'state' => 'expired',
        ]);
        self::assertSame(409, $refused->status);

        self::assertSame('awaiting_payment', $this->write(
            '/seat-pools/' . $pool,
            ['state' => 'awaiting_payment'],
            'PATCH'
        )['item']['state']);
        $this->write('/seat-pools/' . $pool, ['state' => 'active'], 'PATCH');
        $this->write('/seat-pools/' . $pool, ['state' => 'suspended'], 'PATCH');
        $this->write('/seat-pools/' . $pool, ['state' => 'active'], 'PATCH');
        $this->write('/seat-pools/' . $pool, ['state' => 'expired'], 'PATCH');
        $this->write('/seat-pools/' . $pool, ['state' => 'closed'], 'PATCH');

        $events = array_column($this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger')['items'], 'event_key');
        self::assertSame([
            'pool_created', 'pool_funded', 'pool_suspended', 'pool_resumed', 'pool_expired', 'pool_closed',
        ], $events);
        self::assertNotContains('pool_updated', $events);
    }

    /**
     * The ledger's arithmetic still closes once a creation writes two rows.
     *
     * pool_created carries zero and pool_funded carries the funded quantity,
     * which is what the published delta table says and what makes the signed
     * sum the availability rather than a number that only matches when the
     * pool is empty.
     */
    public function testAFundingChangeWritesTheSignedQuantityAndTheTwoDerivationsAgree(): void
    {
        $pool = $this->pool();
        $balance = $this->read('/manage/organisations/1/seat-pools/' . $pool)['balance'];
        self::assertSame(1, $balance['capacity']);
        self::assertSame(1, $balance['ledger_balance']);
        self::assertTrue($balance['reconciled']);

        $raised = $this->write('/seat-pools/' . $pool, ['seats_granted' => 4], 'PATCH');
        self::assertSame(4, $raised['balance']['capacity']);
        self::assertSame(4, $raised['balance']['ledger_balance']);

        $lowered = $this->write('/seat-pools/' . $pool, ['seats_granted' => 2], 'PATCH');
        self::assertSame(2, $lowered['balance']['ledger_balance']);
        self::assertTrue($lowered['balance']['reconciled']);

        $events = array_column($this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger')['items'], 'event_key');
        self::assertSame(['pool_created', 'pool_funded', 'pool_quantity_increased', 'pool_quantity_decreased'], $events);
        $deltas = array_column($this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger')['items'], 'seat_delta');
        self::assertSame([0, 1, 3, -2], $deltas);
    }

    /**
     * A finalised funding document fixes the purchased quantity.
     *
     * Stripe states the legal reason and the design makes it a domain
     * invariant rather than an adapter behaviour, so a Business package
     * cannot reach around it through a route the core allows.
     */
    public function testAFinalisedFundingDocumentRefusesAQuantityEdit(): void
    {
        $created = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Invoiced pool',
            'seats_granted' => 0, 'seats_purchased' => 3, 'funding_state' => 'finalised', 'funding_ref' => 'PO-7781']);
        $pool = (int) $created['item']['id'];

        $refused = $this->request('PATCH', '/manage/organisations/1/seat-pools/' . $pool, [
            'expected_revision' => $this->revision(), 'seats_purchased' => 4,
        ]);
        self::assertSame(409, $refused->status);
        self::assertSame(3, $this->read('/manage/organisations/1/seat-pools/' . $pool)['seats_purchased']);

        // The name is still editable: it is the monetary quantity that a
        // finalised document fixes, not the row.
        self::assertSame('Renamed', $this->write('/seat-pools/' . $pool, ['name' => 'Renamed'], 'PATCH')['item']['name']);
    }

    public function testSeatAccessRetainsItsExternalReferenceWithoutFinancialFields(): void
    {
        $refused = $this->request('POST', '/manage/organisations/1/seat-pools', [
            'expected_revision' => $this->revision(), 'course_id' => 1, 'name' => 'Priced pool',
            'seats_granted' => 1, 'unit_amount_minor' => 4999, 'currency' => 'EUR',
        ]);
        self::assertSame(422, $refused->status);

        $accepted = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Externally funded pool',
            'seats_granted' => 1, 'funding_ref' => 'shop-order-42']);
        self::assertSame('shop-order-42', $accepted['item']['funding_ref']);
        self::assertArrayNotHasKey('unit_amount_minor', $accepted['item']);
        self::assertArrayNotHasKey('currency', $accepted['item']);
    }

    /**
     * The ledger has two clocks, an actor kind, an external reference and one
     * row per command.
     *
     * Both clocks carried the same value on every row, so an as-at
     * reconciliation returned today's answer for every date and a backdated
     * correction was indistinguishable from a tamper. The idempotency key had
     * a column and a unique index since R0 and no writer at all.
     */
    public function testTheLedgerCarriesTwoClocksAnActorKindAndOneRowPerCommand(): void
    {
        $pool = $this->pool();
        $scope = $this->scope();
        $backdated = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));

        $first = $this->store->ledger(
            $scope,
            $this->revision(),
            $pool,
            null,
            'pool_quantity_increased',
            2,
            'granted_by_admin',
            null,
            null,
            [],
            $backdated,
            OrganisationLearningStore::ACTOR_IMPORTER,
            'roster-2026-01',
            'import:2026-01:pool:' . $pool
        );
        $replay = $this->store->ledger(
            $scope,
            $this->revision(),
            $pool,
            null,
            'pool_quantity_increased',
            2,
            'granted_by_admin',
            null,
            null,
            [],
            $backdated,
            OrganisationLearningStore::ACTOR_IMPORTER,
            'roster-2026-01',
            'import:2026-01:pool:' . $pool
        );

        self::assertSame($first, $replay, 'a replayed command answers the row that won');
        $row = $this->env->database->selectOne(Query::from('seat_events')->where('id', '=', $first));
        self::assertNotNull($row);
        self::assertSame('2026-01-02 03:04:05', (string) $row['occurred_at']);
        self::assertNotSame((string) $row['occurred_at'], (string) $row['recorded_at']);
        self::assertSame('importer', (string) $row['actor_type']);
        self::assertSame('roster-2026-01', (string) $row['external_ref']);
        self::assertSame(1, $this->env->database->count(Query::from('seat_events')
            ->where('idempotency_key', '=', 'import:2026-01:pool:' . $pool)));

        // The two appended columns are inside the hash chain when they carry
        // a value, so a row that names an importer cannot be re-attributed.
        self::assertSame(OrganisationLearningStore::digest($row), (string) $row['hash']);
    }

    /**
     * The as-at reconciliation answers about the past, not about today.
     *
     * The seat delivery document and the free reconciliation task both need
     * it: a pool invoiced in January has to be answerable in March, and an
     * auditor asking about the year end cannot be shown the year to date.
     */
    public function testTheReconciliationReadAnswersAsAtAPastInstant(): void
    {
        $pool = $this->pool();
        $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);

        $now = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/reconciliation');
        self::assertSame(1, $now['capacity']);
        self::assertSame(1, $now['held']);
        self::assertSame(0, $now['available']);
        self::assertTrue($now['reconciled']);
        self::assertSame(0, $now['divergence']);

        // Before anything happened: no capacity, no seats, and still
        // reconciled, because both derivations are empty rather than one of
        // them being today's answer.
        $before = $this->read(
            '/manage/organisations/1/seat-pools/' . $pool . '/reconciliation?as_at=2020-01-01 00:00:00'
        );
        self::assertSame('2020-01-01 00:00:00', $before['as_at']);
        self::assertSame(0, $before['held']);
        self::assertSame(0, $before['ledger_balance']);

        // A malformed instant is refused rather than quietly meaning now,
        // which is the answer that made the old reconciliation useless.
        self::assertSame(422, $this->request(
            'GET',
            '/manage/organisations/1/seat-pools/' . $pool . '/reconciliation',
            query: ['as_at' => 'last Tuesday']
        )->status);
    }

    /**
     * A reclaim closes the attempt the learner had open, grades what was
     * stored, and records the ledger row that caused it.
     *
     * This is the one genuine invariant break the wave closes.
     * RequireCurrentOrganisationAccess threw a conflict from SaveAnswers and
     * SubmitAttempt, so an administrator reclaiming at minute forty left the
     * learner unable to save or submit with answers in attempt_answer_events
     * and no grade: exactly the stranded attempt the ceremony exists to
     * prevent.
     */
    public function testAReclaimClosesTheOpenAttemptUnderItsDispositionAndRecordsTheCause(): void
    {
        $pool = $this->pool('always');
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $attemptId = $this->openAttempt();

        $released = $this->write(
            '/seat-assignments/' . $assigned['item']['id'] . '/release',
            ['attempt_disposition' => 'submit_and_grade']
        );
        self::assertSame('submit_and_grade', $released['attempt_disposition']);

        $attempt = $this->env->database->selectOne(Query::from('attempts')->where('id', '=', $attemptId));
        self::assertNotNull($attempt);
        self::assertNotSame(Attempt::STATUS_IN_PROGRESS, (string) $attempt['status']);
        self::assertNotNull($attempt['submitted_at']);
        self::assertSame('reclaimed_by_admin', (string) $attempt['closure_reason']);

        // The drill-through: the attempt names the ledger row that closed it,
        // and that row is the reclaim.
        $cause = $this->env->database->selectOne(Query::from('seat_events')
            ->where('id', '=', (int) $attempt['closed_by_seat_event_id']));
        self::assertNotNull($cause);
        self::assertSame('seat_reclaimed', (string) $cause['event_key']);
        self::assertSame((int) $assigned['item']['id'], (int) $cause['assignment_id']);
    }

    /** A void disposition abandons the attempt instead of grading it. */
    public function testAVoidDispositionAbandonsTheAttemptRatherThanGradingIt(): void
    {
        $pool = $this->pool('always');
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $attemptId = $this->openAttempt();

        $this->write('/seat-assignments/' . $assigned['item']['id'] . '/release', ['attempt_disposition' => 'void']);

        $attempt = $this->env->database->selectOne(Query::from('attempts')->where('id', '=', $attemptId));
        self::assertNotNull($attempt);
        self::assertSame(Attempt::STATUS_ABANDONED, (string) $attempt['status']);
        self::assertNull($attempt['percentage']);
        self::assertSame('reclaimed_by_admin', (string) $attempt['closure_reason']);
    }

    /** A disposition the vocabulary does not publish is refused, never guessed. */
    public function testAnUnknownAttemptDispositionIsRefused(): void
    {
        $pool = $this->pool('always');
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);

        $refused = $this->request('POST', '/manage/organisations/1/seat-assignments/' . $assigned['item']['id'] . '/release', [
            'expected_revision' => $this->revision(), 'attempt_disposition' => 'voide',
        ]);

        self::assertSame(422, $refused->status);
        self::assertSame('assigned', (string) $this->assignment((int) $assigned['item']['id'])['state']);
    }

    /**
     * Progress reaches the seat through the listener, and the reclaim policy
     * reads the assignment rather than the enrolment.
     *
     * consumed and completed had no writer at all, which is why the policy
     * queried the learner's enrolment row: the one query the design forbids
     * it to make.
     */
    public function testTheListenerWritesConsumedAndCompletedAndTheSeatAggregateIsClosed(): void
    {
        $pool = $this->pool();
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $assignmentId = (int) $assigned['item']['id'];
        self::assertSame('assigned', (string) $this->assignment($assignmentId)['state']);

        $enrolment = $this->env->database->selectOne(Query::from('enrolments')
            ->where('user_id', '=', 3)->where('course_id', '=', 1));
        self::assertNotNull($enrolment);
        $this->kernel->events->dispatch(new CourseCompleted($this->env->clock->now(), (int) $enrolment['id'], 1, 3));

        $row = $this->assignment($assignmentId);
        self::assertSame('completed', (string) $row['state']);
        self::assertNotNull($row['completed_at']);

        // Zero delta, so the seat is still spent and the two derivations
        // still agree.
        $ledger = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger');
        self::assertContains('seat_completed', array_column($ledger['items'], 'event_key'));
        self::assertTrue($ledger['balance']['reconciled']);

        // And the seat aggregate is closed: a completed seat cannot be
        // reclaimed, decided from the row and not from a progress query.
        $refused = $this->request('POST', '/manage/organisations/1/seat-assignments/' . $assignmentId . '/release', [
            'expected_revision' => $this->revision(),
        ]);
        self::assertSame(409, $refused->status);
    }

    /** A re-fired completion writes one row, because the ledger key is the transition. */
    public function testTheProgressListenerIsIdempotent(): void
    {
        $pool = $this->pool();
        $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $enrolment = $this->env->database->selectOne(Query::from('enrolments')
            ->where('user_id', '=', 3)->where('course_id', '=', 1));
        self::assertNotNull($enrolment);

        $event = new CourseCompleted($this->env->clock->now(), (int) $enrolment['id'], 1, 3);
        $this->kernel->events->dispatch($event);
        $this->kernel->events->dispatch($event);

        self::assertSame(1, $this->env->database->count(Query::from('seat_events')
            ->where('event_key', '=', 'seat_completed')));
    }

    /**
     * A return by the holder is released; a withdrawal by somebody else is
     * reclaimed.
     *
     * Both landed on reclaimed, so the designed distinction between a
     * voluntary return and an administrator taking a seat back was gone and
     * one of the eight states had no writer at all.
     */
    public function testAHolderReturningTheirOwnSeatIsReleasedAndNotReclaimed(): void
    {
        // The creator is already the owner, so the acting principal is a
        // member and can hold a seat like anybody else.
        $pool = (int) $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Shared pool',
            'seats_granted' => 2, 'reclaim_policy' => 'always'])['item']['id'];
        $mine = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 1]);
        $theirs = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);

        $this->write('/seat-assignments/' . $mine['item']['id'] . '/release');
        self::assertSame('released', (string) $this->assignment((int) $mine['item']['id'])['state']);

        $this->write('/seat-assignments/' . $theirs['item']['id'] . '/release');
        self::assertSame('reclaimed', (string) $this->assignment((int) $theirs['item']['id'])['state']);

        $keys = array_column($this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger')['items'], 'event_key');
        self::assertContains('seat_released', $keys);
        self::assertContains('seat_reclaimed', $keys);
    }

    /**
     * Every ledger row claims an idempotency key, whether or not its writer
     * supplied one.
     *
     * R0 added the column and its unique index and left the writer for this
     * wave. A row with no key is a row a replay can duplicate, and the two
     * derivations of a pool's balance disagree by exactly the duplicate.
     */
    public function testEveryLedgerRowClaimsAnIdempotencyKey(): void
    {
        $pool = $this->pool();
        $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $this->write('/seat-pools/' . $pool, ['seats_granted' => 3], 'PATCH');

        $rows = $this->env->database->selectAll(Query::from('seat_events')->where('pool_id', '=', $pool));
        self::assertGreaterThan(3, \count($rows));

        $keys = [];

        foreach ($rows as $row) {
            self::assertNotNull($row['idempotency_key'], (string) $row['event_key']);
            $keys[] = (string) $row['idempotency_key'];
        }

        self::assertSame($keys, array_values(array_unique($keys)));
    }

    /** Every assignment carries the opaque reference source_ref is meant to name. */
    public function testEveryAssignmentIsMintedWithAnOpaqueReference(): void
    {
        $pool = $this->pool();
        $assigned = $this->write('/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $ref = (string) $this->assignment((int) $assigned['item']['id'])['assignment_ref'];

        self::assertSame(32, \strlen($ref));
        self::assertMatchesRegularExpression('/^[A-Z0-9]{32}$/', $ref);
    }

    private function openAttempt(): int
    {
        $dates = ['created_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s')];
        $moduleId = $this->env->database->insert('modules', ['course_id' => 1, 'title' => 'Synthetic module',
            'position' => 1, 'state' => 'published'] + $dates);
        $lessonId = $this->env->database->insert('lessons', ['course_id' => 1, 'module_id' => $moduleId,
            'title' => 'Synthetic lesson', 'slug' => 'synthetic-quiz', 'type' => 'quiz', 'position' => 1,
            'state' => 'published'] + $dates);
        $quizId = $this->env->database->insert(
            'quizzes',
            ['course_id' => 1, 'lesson_id' => $lessonId, 'title' => 'Synthetic quiz'] + $dates
        );
        $enrolment = $this->env->database->selectOne(Query::from('enrolments')
            ->where('user_id', '=', 3)->where('course_id', '=', 1));
        self::assertNotNull($enrolment);

        return $this->env->database->insert('attempts', ['quiz_id' => $quizId, 'user_id' => 3,
            'enrolment_id' => (int) $enrolment['id'], 'attempt_number' => 1,
            'status' => Attempt::STATUS_IN_PROGRESS, 'started_at' => $dates['created_at']] + $dates);
    }

    /** @return array<string, mixed> */
    private function assignment(int $id): array
    {
        $row = $this->env->database->selectOne(Query::from('seat_assignments')->where('id', '=', $id));
        self::assertNotNull($row);

        return $row;
    }

    private function scope(): \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope
    {
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $this->registries->orgRoles);

        return \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope::fromResolver(
            $resolver,
            Principal::user(1),
            1,
            'seats'
        );
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

    private function pool(string $policy = 'before_completion'): int
    {
        $result = $this->write('/seat-pools', ['course_id' => 1, 'name' => 'Synthetic pool',
            'seats_granted' => 1, 'reclaim_policy' => $policy]);
        self::assertIsInt($result['item']['id']);

        return $result['item']['id'];
    }

    private function revision(): int
    {
        $row = $this->read('/manage/organisations/1');
        self::assertIsInt($row['revision']);

        return $row['revision'];
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function write(string $suffix, array $body = [], string $method = 'POST', int $expectedStatus = 200): array
    {
        $response = $this->request($method, '/manage/organisations/1' . $suffix, ['expected_revision' => $this->revision()] + $body);
        self::assertSame($expectedStatus, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        return $response->data;
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        $query = [];

        if (str_contains($path, '?')) {
            [$path, $raw] = explode('?', $path, 2);
            parse_str($raw, $parsed);

            foreach ($parsed as $key => $value) {
                $query[(string) $key] = \is_string($value) ? $value : '';
            }
        }

        $response = $this->request('GET', $path, query: $query);
        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        return $response->data;
    }

    /** @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function request(string $method, string $path, array $body = [], array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest(
            $method,
            $path,
            Principal::user(1),
            $query,
            $body,
            'seat-accounting-' . ++$this->sequence
        ));
    }
}
