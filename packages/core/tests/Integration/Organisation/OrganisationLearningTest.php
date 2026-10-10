<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccessTermination;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationTerminationTask;
use Shondalai\VidyaLMS\Core\Application\Organisation\RequireCurrentOrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationUnitRoutes;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreSeatReclaimPolicy;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEntitlementRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class OrganisationLearningTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private int $sequence = 0;
    private OrganisationTerminationTask $terminationTask;
    private Registries $registries;

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
        $this->env->database->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS org_grant_digest_uq ON vidyalms_entitlements(source_ref_digest)'
        );
        foreach ([1, 2, 3, 4] as $id) {
            $this->env->users->add($id, 'Synthetic ' . $id, 'synthetic-' . $id . '@example.test');
        }
        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $this->kernel = new CoreKernel($this->env->ports);
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);
        $this->registries = $registries;
        $roles = $registries->orgRoles;
        $store = new OrganisationStore($this->env->database, $this->env->clock);
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $store, $roles);
        $orgAccess = new OrganisationAccess($access);
        $learningStore = new OrganisationLearningStore($this->env->database, $this->env->clock);
        $events = new OrganisationLearningEvents($this->kernel->notificationEnqueue, $this->kernel->events);
        $policy = new CourseReleasePolicy($this->env->database, $this->env->clock);
        $composed = new ComposedAccess($orgAccess, $policy);
        $lifecycle = new SeatLifecycle(
            $learningStore,
            $registries->seatReclaim,
            $this->kernel->revokeEntitlement,
            $events,
            $this->env->clock
        );
        $termination = new OrganisationAccessTermination($learningStore, $lifecycle, $this->kernel->revokeEntitlement, $events);
        $this->terminationTask = new OrganisationTerminationTask($termination, $store, $this->env->scheduler, $this->env->clock);
        $learning = new ManageOrganisationLearning(
            $access,
            $store,
            $learningStore,
            $composed,
            $this->kernel->grantEntitlement,
            $this->kernel->revokeEntitlement,
            $events,
            $termination,
            $this->env->users
        );
        $seats = new ManageOrganisationSeats(
            $access,
            $store,
            $learningStore,
            $composed,
            $this->kernel->grantEntitlement,
            $events,
            $lifecycle,
            $this->env->users,
            $registries->seatReclaim,
            $registries->seatScopes,
            $registries->seatOverAllocation,
            $registries->seatExpiryDisposition,
            $registries->seatEventReasons
        );
        $idempotent = new IdempotentExecution(new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
            $this->env->clock
        ));
        $this->api = new ApiKernel(new NullLogger());
        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations($access, $store, $this->env->users, $roles, $registries->orgTypes, $termination),
            new ManageOrganisationMembers($orgAccess, $store, $roles, $this->env->users, $this->env->translator, $termination),
            $access,
            $idempotent,
            $this->env->database,
            $events
        );
        OrganisationLearningRoutes::register($this->api, $learning, $seats, $access, $idempotent, $this->env->database, $events);
        OrganisationUnitRoutes::register(
            $this->api,
            new ManageOrganisationUnits($access, $store),
            $access,
            $idempotent,
            $this->env->database
        );
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', ['title' => 'Synthetic Course', 'slug' => 'synthetic-course', 'state' => 'published',
            'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function testReleasesDirectGrantsAndReportsStayScopedAndShareOneLearningRecord(): void
    {
        $this->prepare(1);
        $this->prepare(2);
        $first = $this->write(1, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        $second = $this->write(2, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        self::assertNotSame($first['entitlement_id'], $second['entitlement_id']);
        self::assertSame(1, $this->env->database->count(Query::from('enrolments')));
        $list = $this->read('/manage/organisations/1/learning');
        self::assertSame(1, $list['total']);
        self::assertSame(1, $list['items'][0]['organisation_id']);
        self::assertSame(3, $list['items'][0]['user_id']);
        $this->write(1, '/learning/' . $first['entitlement_id'] . '/revoke');
        self::assertSame('active', $this->enrolment()['status']);
        $this->write(2, '/learning/' . $second['entitlement_id'] . '/revoke');
        self::assertSame('suspended', $this->enrolment()['status']);
        $this->write(1, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        self::assertSame('active', $this->enrolment()['status']);
        self::assertSame(1, $this->env->database->count(Query::from('enrolments')));
    }

    public function testAtomicAssignmentReclaimPendingRetryAndLedgerNeverOversubscribe(): void
    {
        $this->prepare();
        $pool = $this->pool(1);
        $assigned = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        self::assertSame($pool, $this->read('/manage/organisations/1/seat-pools/' . $pool)['id']);
        self::assertSame('assigned', $assigned['outcome']);
        self::assertSame(0, $assigned['balance']['available']);
        foreach (
            [
            fn () => $this->kernel->revokeEntitlement->execute(Principal::user(1), $assigned['item']['entitlement_id']),
            fn () => $this->kernel->extendEntitlement->execute(Principal::user(1), $assigned['item']['entitlement_id'], null),
            ] as $genericWrite
        ) {
            try {
                $genericWrite();
                self::fail('Generic enrolment controls must not bypass organisation seat accounting.');
            } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException $refusal) {
                self::assertStringContainsString('organisation grant or seat assignment', $refusal->getMessage());
            }
        }
        $pending = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        self::assertSame('pending_capacity', $pending['outcome']);
        self::assertNull($pending['item']['entitlement_id']);
        self::assertSame('CountViolation', $pending['item']['problem_code']);
        self::assertSame(1, $this->env->database->count(Query::from('entitlements')));
        $returned = $this->write(1, '/seat-assignments/' . $assigned['item']['id'] . '/release');
        self::assertSame(1, $returned['balance']['available']);
        $promoted = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        self::assertSame($pending['item']['id'], $promoted['item']['id']);
        self::assertSame('assigned', $promoted['outcome']);
        self::assertTrue($promoted['balance']['reconciled']);
        self::assertSame(1, $promoted['balance']['held']);
        $ledger = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/ledger');
        // Six rather than five: a pool creation now writes the two rows the
        // published machine draws, pool_created at zero and pool_funded
        // carrying the quantity, in place of one generic row.
        self::assertSame(6, $ledger['total']);
        $hash = str_repeat('0', 64);
        foreach ($ledger['items'] as $event) {
            self::assertSame($hash, $event['prev_hash']);
            self::assertSame(OrganisationLearningStore::digest($event), $event['hash']);
            $hash = $event['hash'];
        }
    }

    public function testMembershipTerminationPreservesPersonalAccessAndNeverRecyclesCompletedSeats(): void
    {
        $this->prepare();
        $personal = $this->kernel->grantEntitlement->execute(Principal::user(1), 3, 1, 'manual');
        $pool = $this->pool(1, 'always');
        $assigned = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $enrolment = $this->enrolment();
        $this->env->database->update('enrolments', ['status' => 'completed', 'progress_percent' => 100], ['id' => $enrolment['id']]);
        // The completion reaches the seat through the listener on the
        // existing domain event, which is the whole of how the seat aggregate
        // closes: the reclaim decision reads the assignment's own state and
        // never the enrolment row this test also writes.
        $this->kernel->events->dispatch(new CourseCompleted($this->env->clock->now(), (int) $enrolment['id'], 1, 3));
        self::assertSame('completed', (string) $this->assignmentRow((int) $assigned['item']['id'])['state']);
        $blocked = $this->request(
            'POST',
            '/manage/organisations/1/seat-assignments/' . $assigned['item']['id'] . '/release',
            ['expected_revision' => $this->revision(1)]
        );
        self::assertSame(409, $blocked->status);
        $member = $this->env->database->selectOne(Query::from('org_memberships')
            ->where('organisation_id', '=', 1)->where('user_id', '=', 3));
        self::assertNotNull($member);
        $this->write(1, '/members/' . $member['id'], ['state' => 'left'], 'PATCH');
        $row = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/assignments')['items'][0];
        self::assertSame('expired', $row['state']);
        self::assertSame(0, $this->read('/manage/organisations/1/seat-pools')['items'][0]['balance']['available']);
        $personalRow = $this->env->database->selectOne(Query::from('entitlements')->where('id', '=', $personal->id()));
        self::assertSame('active', $personalRow['status'] ?? null);
        self::assertSame('completed', $this->enrolment()['status']);
    }

    public function testSuspendOnlyTerminatesThisOrganisationAndCancelsPendingSeats(): void
    {
        $this->prepare(1);
        $this->prepare(2);
        $this->write(2, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        $pool = $this->pool(1, 'never');
        $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        $this->write(1, '', ['state' => 'suspended'], 'PATCH');
        self::assertSame('active', $this->enrolment()['status']);
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')
            ->where('organisation_id', '=', 1)->where('status', '=', 'active')));
        $assignments = $this->read('/manage/organisations/1/seat-pools/' . $pool . '/assignments');
        self::assertSame(['forfeited', 'cancelled'], array_column($assignments['items'], 'state'));
        self::assertSame(404, $this->request('GET', '/manage/organisations/1', actor: 2)->status);
        self::assertSame(409, $this->request(
            'POST',
            '/manage/organisations/1/learning/grant',
            ['expected_revision' => $this->revision(1), 'user_id' => 3, 'course_id' => 1]
        )->status);
    }

    public function testCrossTenantReadsAndIdsFailAndDelegatesCannotFundOrReleaseCourses(): void
    {
        $this->prepare(1);
        $this->prepare(2);
        $pool = $this->pool(2);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1/seat-pools/' . $pool)->status);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1/seat-pools/' . $pool . '/assignments')->status);
        self::assertSame(404, $this->request('GET', '/manage/organisations/2/learning', actor: 2)->status);
        self::assertSame(403, $this->request(
            'POST',
            '/manage/organisations/1/seat-pools',
            ['expected_revision' => $this->revision(1), 'course_id' => 1, 'name' => 'Not authorised', 'seats_granted' => 5],
            actor: 2
        )->status);
        self::assertSame(403, $this->request(
            'POST',
            '/manage/organisations/1/releases',
            ['expected_revision' => $this->revision(1), 'course_id' => 1],
            actor: 2
        )->status);
        self::assertSame(422, $this->request('GET', '/manage/organisations/1/learning', query: ['per_page' => '101'])->status);
        $roles = $this->read('/manage/organisations/1/roles', 2)['items'];
        self::assertSame([false, false, false, true], array_column($roles, 'assignable'));
        self::assertSame('org.role.org_owner', $roles[0]['label']);
    }

    public function testStaleRevisionAndIdempotentReplayDoNotDuplicateSeatsOrExternalEvents(): void
    {
        $this->prepare();
        $pool = $this->pool(1);
        $path = '/manage/organisations/1/seat-pools/' . $pool . '/assignments';
        $body = ['expected_revision' => $this->revision(1), 'user_id' => 3];
        $first = $this->request('POST', $path, $body, key: 'same-seat');
        self::assertSame(200, $first->status);
        $events = $this->env->eventBridge->eventNames();
        $replay = $this->request('POST', $path, $body, key: 'same-seat');
        self::assertSame($first->data, $replay->data);
        self::assertSame('true', $replay->headers['Idempotent-Replayed']);
        self::assertSame($events, $this->env->eventBridge->eventNames());
        self::assertSame(409, $this->request('POST', $path, array_replace($body, ['user_id' => 4]))->status);
        self::assertSame(1, $this->env->database->count(Query::from('seat_assignments')));
        self::assertSame(3, $this->env->database->count(Query::from('seat_events')));
    }

    public function testPoolReductionBelowHeldAndBeforeStartPolicyCannotCorruptBalance(): void
    {
        $this->prepare();
        $pool = $this->pool(1, 'before_start');
        $assigned = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 3]);
        $this->env->database->update('enrolments', ['progress_percent' => 20], ['id' => $this->enrolment()['id']]);
        // The seat learns that the learner started from the progress event
        // itself. passed is false on purpose, so the quiz lesson does not
        // complete and the assignment stops at consumed: this test is about
        // before_start refusing a seat somebody has touched, not a finished
        // one.
        [$lessonId, $quizId, $attemptId] = $this->openAttempt();
        $this->kernel->events->dispatch(new AttemptSubmitted(
            $this->env->clock->now(),
            $attemptId,
            $quizId,
            $lessonId,
            (int) $this->enrolment()['id'],
            3,
            1,
            20.0,
            false,
            false
        ));
        self::assertSame('consumed', (string) $this->assignmentRow((int) $assigned['item']['id'])['state']);
        // A policy that refuses a credit forfeits the seat rather than
        // refusing the return. It used to throw, which left the designed
        // consumed-to-forfeited edge with no writer: the row stayed consumed,
        // the learner's access stayed open, and an administrator could not end
        // it at all. The seat is spent either way, which is the property this
        // test is about: the ledger row is a zero delta, the seat stays held
        // and the pool cannot be reduced under it.
        $released = $this->request(
            'POST',
            '/manage/organisations/1/seat-assignments/' . $assigned['item']['id'] . '/release',
            ['expected_revision' => $this->revision(1)]
        );
        self::assertSame(200, $released->status, json_encode($released->data, JSON_THROW_ON_ERROR));
        self::assertSame('forfeited', (string) $this->assignmentRow((int) $assigned['item']['id'])['state']);
        $forfeit = $this->env->database->selectOne(Query::from('seat_events')
            ->where('assignment_id', '=', (int) $assigned['item']['id'])
            ->where('event_key', '=', 'seat_forfeited'));
        self::assertNotNull($forfeit);
        self::assertSame(0, (int) $forfeit['seat_delta']);
        self::assertSame('returned_but_not_credited', (string) $forfeit['reason_key']);
        self::assertSame(409, $this->request(
            'PATCH',
            '/manage/organisations/1/seat-pools/' . $pool,
            ['expected_revision' => $this->revision(1), 'seats_granted' => 0]
        )->status);
        self::assertSame(1, $this->read('/manage/organisations/1/seat-pools')['items'][0]['balance']['held']);
        self::assertTrue($this->read('/manage/organisations/1/seat-pools')['items'][0]['balance']['reconciled']);
        self::assertSame(20.0, (float) $this->enrolment()['progress_percent']);
    }

    public function testWithdrawAndReleaseWindowsFailClosedAndPreserveProgress(): void
    {
        $this->prepare();
        $this->write(1, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        $this->env->database->update('enrolments', ['progress_percent' => 38], ['id' => $this->enrolment()['id']]);
        $this->write(1, '/releases', ['course_id' => 1, 'state' => 'withdrawn']);
        self::assertSame('suspended', $this->enrolment()['status']);
        self::assertSame(38.0, (float) $this->enrolment()['progress_percent']);
        self::assertSame(409, $this->request(
            'POST',
            '/manage/organisations/1/learning/grant',
            ['expected_revision' => $this->revision(1), 'user_id' => 3, 'course_id' => 1]
        )->status);
        $this->write(1, '/releases', ['course_id' => 1, 'starts_at' => '2099-01-01 00:00:00']);
        self::assertSame(409, $this->request(
            'POST',
            '/manage/organisations/1/learning/grant',
            ['expected_revision' => $this->revision(1), 'user_id' => 3, 'course_id' => 1]
        )->status);
    }

    public function testEnterpriseTerminationImmediatelyDeniesAccessThenDrainsBoundedDurableChunks(): void
    {
        $this->prepare();
        $grant = $this->write(1, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        [$lessonId, $quizId, $attemptId] = $this->openAttempt();
        $row = $this->env->database->selectOne(Query::from('entitlements')->where('id', '=', $grant['entitlement_id']));
        self::assertNotNull($row);
        unset($row['id']);
        for ($index = 0; $index < 501; $index++) {
            $this->env->database->insert('entitlements', array_replace(
                $row,
                ['source_ref' => 'synthetic-org-grant-' . $index, 'source_ref_digest' => hash('sha256', 'synthetic-' . $index)]
            ));
        }
        $suspended = $this->write(1, '', ['state' => 'suspended'], 'PATCH');
        self::assertSame('disabled', $suspended['access_state']);
        self::assertSame('queued', $suspended['cleanup']['state']);
        self::assertSame(502, $this->env->database->count(Query::from('entitlements')->where('status', '=', 'active')));
        foreach (
            [
            fn () => $this->kernel->startAttempt->execute(Principal::user(3), $quizId),
            fn () => $this->kernel->saveAnswers->execute(Principal::user(3), $attemptId, []),
            fn () => $this->kernel->submitAttempt->execute(Principal::user(3), $attemptId),
            fn () => $this->kernel->recordLessonProgress->execute(Principal::user(3), (int) $this->enrolment()['id'], $lessonId),
            ] as $mutation
        ) {
            try {
                $mutation();
                self::fail('A learner mutation accepted disabled organisation access.');
            } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException $refusal) {
                self::assertStringContainsString('Organisation course access has ended', $refusal->getMessage());
            }
        }
        self::assertSame('in_progress', $this->env->database->selectOne(Query::from('attempts')
            ->where('id', '=', $attemptId))['status'] ?? null);
        self::assertSame([], (new DatabaseEntitlementRepository($this->env->database, $this->env->clock))->activeForUserAndCourse(3, 1));
        try {
            RequireCurrentOrganisationAccess::check($this->env->database, $this->env->clock, 3, 1);
            self::fail('An active historical enrolment cannot bypass immediate relationship suspension.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException) {
            self::assertSame('active', $this->enrolment()['status']);
        }
        self::assertSame(409, $this->request(
            'PATCH',
            '/manage/organisations/1',
            ['expected_revision' => $this->revision(1), 'state' => 'active']
        )->status);
        $run = new ScheduledTaskRun(10, ['organisation_id' => 999, 'user_id' => 999], Principal::guest());
        $this->terminationTask->run($run);
        self::assertSame(50, $this->read('/manage/organisations/1/cleanup-jobs')['items'][0]['processed_count']);
        self::assertSame(452, $this->env->database->count(Query::from('entitlements')->where('status', '=', 'active')));
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $this->terminationTask->run($run);
        }
        $job = $this->read('/manage/organisations/1/cleanup-jobs')['items'][0];
        self::assertSame('completed', $job['state']);
        self::assertSame(502, $job['processed_count']);
        self::assertSame('suspended', $this->enrolment()['status']);
        self::assertSame('abandoned', $this->env->database->selectOne(Query::from('attempts')
            ->where('id', '=', $attemptId))['status'] ?? null);
        $this->write(1, '', ['state' => 'active'], 'PATCH');
        self::assertSame([], (new DatabaseEntitlementRepository($this->env->database, $this->env->clock))->activeForUserAndCourse(3, 1));
        self::assertSame('suspended', $this->enrolment()['status']);
    }

    public function testUnitTreeMembershipMovesPathPaginationAndCrossTenantProtection(): void
    {
        $this->prepare(1);
        $this->prepare(2);
        $parent = $this->write(1, '/units', ['name' => 'Engineering'])['item']['id'];
        $leaf = $this->write(1, '/units', ['name' => 'Quality', 'parent_id' => $parent])['item']['id'];
        self::assertSame(3, $this->env->database->count(Query::from('org_unit_closure')));
        $page = $this->request('GET', '/manage/organisations/1/units', query: ['per_page' => '1', 'page' => '2']);
        self::assertSame('Engineering/Quality', $page->data['items'][0]['path'] ?? null);
        self::assertSame(2, $page->data['total'] ?? null);
        self::assertSame('Engineering/Quality', $this->read('/manage/organisations/1/units/' . $leaf)['path']);
        self::assertSame(404, $this->request('GET', '/manage/organisations/2/units/' . $leaf)->status);
        $member = $this->env->database->selectOne(Query::from('org_memberships')
            ->where('organisation_id', '=', 1)->where('user_id', '=', 3));
        self::assertNotNull($member);
        $assigned = $this->write(1, '/members/' . $member['id'], ['org_unit_id' => $leaf], 'PATCH');
        self::assertSame($leaf, $assigned['item']['org_unit_id']);
        self::assertSame(409, $this->request(
            'PATCH',
            '/manage/organisations/1/units/' . $leaf,
            ['expected_revision' => $this->revision(1), 'state' => 'inactive']
        )->status);
        self::assertSame(404, $this->request(
            'POST',
            '/manage/organisations/2/units',
            ['expected_revision' => $this->revision(2), 'name' => 'Wrong', 'parent_id' => $parent]
        )->status);
        $this->write(1, '/members/' . $member['id'], ['org_unit_id' => null], 'PATCH');
        $renamed = $this->write(1, '/units/' . $leaf, ['name' => 'Testing'], 'PATCH');
        self::assertSame('Engineering/Testing', $renamed['item']['path']);
        self::assertSame(409, $this->request(
            'PATCH',
            '/manage/organisations/1/units/' . $parent,
            ['expected_revision' => $this->revision(1), 'name' => 'Unbounded rename']
        )->status);
    }

    public function testUnitScopedRoleCannotReadMutateOrReplayOutsideItsSubtree(): void
    {
        $this->prepare();
        $engineering = $this->write(1, '/units', ['name' => 'Engineering'])['item']['id'];
        $quality = $this->write(1, '/units', ['name' => 'Quality', 'parent_id' => $engineering])['item']['id'];
        $sales = $this->write(1, '/units', ['name' => 'Sales'])['item']['id'];
        $people = $this->read('/manage/organisations/1/members')['items'];
        $ids = array_column($people, 'id', 'user_id');
        $this->write(1, '/members/' . $ids[3], ['org_unit_id' => $quality], 'PATCH');
        $this->write(1, '/members/' . $ids[4], ['org_unit_id' => $sales], 'PATCH');
        $this->write(1, '/members/' . $ids[2], ['role_key' => 'org_admin', 'role_unit_id' => $engineering], 'PATCH');
        $first = $this->write(1, '/learning/grant', ['user_id' => 3, 'course_id' => 1]);
        $second = $this->write(1, '/learning/grant', ['user_id' => 4, 'course_id' => 1]);
        self::assertSame([3], array_column($this->read('/manage/organisations/1/members', 2)['items'], 'user_id'));
        self::assertSame([3], array_column($this->read('/manage/organisations/1/learning', 2)['items'], 'user_id'));
        self::assertSame([$engineering, $quality], array_column($this->read('/manage/organisations/1/units', 2)['items'], 'id'));
        self::assertSame('Engineering/Quality', $this->read('/manage/organisations/1/units/' . $quality, 2)['path']);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1/units/' . $sales, actor: 2)->status);
        $summary = $this->read('/manage/organisations/1/summary', 2);
        self::assertSame(1, $summary['active_members']);
        self::assertSame(1, $summary['enrolments']);
        self::assertSame($engineering, $this->read('/manage/organisations/1/roles', 2)['access']['unit_id']);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1/events', actor: 2)->status);
        self::assertSame(404, $this->request(
            'PATCH',
            '/manage/organisations/1/members/' . $ids[4],
            ['expected_revision' => $this->revision(1), 'state' => 'left'],
            actor: 2
        )->status);
        self::assertSame(404, $this->request(
            'POST',
            '/manage/organisations/1/learning/' . $second['entitlement_id'] . '/revoke',
            ['expected_revision' => $this->revision(1)],
            actor: 2
        )->status);
        $pool = $this->pool(1);
        $this->write(1, '/seat-pools/' . $pool, ['seats_granted' => 2], 'PATCH');
        $assignmentPath = '/manage/organisations/1/seat-pools/' . $pool . '/assignments';
        $assignmentBody = ['expected_revision' => $this->revision(1), 'user_id' => 3];
        self::assertSame(200, $this->request(
            'POST',
            $assignmentPath,
            $assignmentBody,
            actor: 2,
            key: 'unit-seat-retry'
        )->status);
        $foreignSeat = $this->write(1, '/seat-pools/' . $pool . '/assignments', ['user_id' => 4]);
        self::assertSame([3], array_column($this->read($assignmentPath, 2)['items'], 'user_id'));
        self::assertSame(404, $this->request(
            'POST',
            '/manage/organisations/1/seat-assignments/' . $foreignSeat['item']['id'] . '/release',
            ['expected_revision' => $this->revision(1)],
            actor: 2
        )->status);
        self::assertSame(404, $this->request('GET', '/manage/organisations/1/seat-pools/' . $pool . '/ledger', actor: 2)->status);
        $body = ['expected_revision' => $this->revision(1), 'org_unit_id' => $quality];
        $path = '/manage/organisations/1/members/' . $ids[3];
        self::assertSame(200, $this->request('PATCH', $path, $body, actor: 2, key: 'unit-member-retry')->status);
        $this->write(1, '/members/' . $ids[3], ['org_unit_id' => $sales], 'PATCH');
        self::assertSame(404, $this->request('PATCH', $path, $body, actor: 2, key: 'unit-member-retry')->status);
        self::assertSame(404, $this->request(
            'POST',
            $assignmentPath,
            $assignmentBody,
            actor: 2,
            key: 'unit-seat-retry'
        )->status);
        self::assertSame([], $this->read('/manage/organisations/1/learning', 2)['items']);
        self::assertNotSame($first['entitlement_id'], $second['entitlement_id']);
    }

    private function prepare(int $org = 1): void
    {
        self::assertSame(201, $this->request(
            'POST',
            '/manage/organisations',
            ['name' => 'Synthetic ' . $org, 'slug' => 'synthetic-' . $org]
        )->status);
        foreach ([3, 4] as $user) {
            $this->write($org, '/members', ['user_id' => $user, 'role_key' => 'org_member'], expectedStatus: 201);
        }
        if ($org === 1) {
            $this->write($org, '/members', ['user_id' => 2, 'role_key' => 'org_admin'], expectedStatus: 201);
        }
        $this->write($org, '/releases', ['course_id' => 1]);
    }

    /** @return array{int, int, int} */
    private function openAttempt(): array
    {
        $dates = ['created_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s')];
        $moduleId = $this->env->database->insert('modules', ['course_id' => 1, 'title' => 'Synthetic module',
            'position' => 1, 'state' => 'published'] + $dates);
        $lessonId = $this->env->database->insert('lessons', ['course_id' => 1, 'module_id' => $moduleId,
            'title' => 'Synthetic lesson', 'slug' => 'synthetic-quiz', 'type' => 'quiz', 'position' => 1, 'state' => 'published'] + $dates);
        $quizId = $this->env->database->insert(
            'quizzes',
            ['course_id' => 1, 'lesson_id' => $lessonId, 'title' => 'Synthetic quiz'] + $dates
        );
        $attemptId = $this->env->database->insert('attempts', ['quiz_id' => $quizId, 'user_id' => 3,
            'enrolment_id' => $this->enrolment()['id'], 'attempt_number' => 1, 'started_at' => $dates['created_at']] + $dates);

        return [$lessonId, $quizId, $attemptId];
    }

    /**
     * Saving a pool is the free route that reaches the reclaim registry.
     *
     * The validation was a literal list of the four free keys, which meant a
     * fifth policy could be registered and never chosen: a seam with a wall
     * behind it. It now asks the registry, so a registered policy is
     * selectable the moment it is registered and an unregistered key is
     * refused by name.
     */
    public function testSavingAPoolValidatesItsReclaimPolicyThroughTheRegistry(): void
    {
        $this->prepare();

        $refused = $this->request('POST', '/manage/organisations/1/seat-pools', [
            'expected_revision' => $this->revision(1), 'course_id' => 1, 'name' => 'Pool',
            'seats_granted' => 1, 'reclaim_policy' => 'acme.before_review',
        ]);
        self::assertSame(422, $refused->status);

        // Registering the fifth provider is the whole change: no core edit,
        // no migration, and the same route now accepts the key.
        $this->registries->seatReclaim->register(new CoreSeatReclaimPolicy('acme.before_review'));

        $accepted = $this->write(1, '/seat-pools', [
            'course_id' => 1, 'name' => 'Pool', 'seats_granted' => 1, 'reclaim_policy' => 'acme.before_review',
        ]);
        self::assertSame('acme.before_review', $accepted['item']['reclaim_policy']);

        // And the read says whether the site owner still releases the course
        // this pool spends seats on, asked through the same composed rule the
        // write path uses rather than a second copy of it.
        self::assertTrue($this->read('/manage/organisations/1/seat-pools/' . $accepted['item']['id'])['course_released']);
    }

    /** @return array<string, mixed> */
    private function assignmentRow(int $id): array
    {
        $row = $this->env->database->selectOne(Query::from('seat_assignments')->where('id', '=', $id));
        self::assertNotNull($row);

        return $row;
    }

    private function pool(int $org, string $policy = 'before_completion'): int
    {
        $result = $this->write(
            $org,
            '/seat-pools',
            ['course_id' => 1, 'name' => 'Synthetic pool', 'seats_granted' => 1, 'reclaim_policy' => $policy]
        );
        self::assertIsInt($result['item']['id']);

        return $result['item']['id'];
    }

    /** @return array<string, mixed> */
    private function enrolment(): array
    {
        $row = $this->env->database->selectOne(Query::from('enrolments')->where('user_id', '=', 3)->where('course_id', '=', 1));
        self::assertNotNull($row);

        return $row;
    }

    private function revision(int $org): int
    {
        $row = $this->read('/manage/organisations/' . $org);
        self::assertIsInt($row['revision']);

        return $row['revision'];
    }

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function write(int $org, string $suffix, array $body = [], string $method = 'POST', int $expectedStatus = 200): array
    {
        $response = $this->request(
            $method,
            '/manage/organisations/' . $org . $suffix,
            ['expected_revision' => $this->revision($org)] + $body
        );
        self::assertSame($expectedStatus, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        return $response->data;
    }

    /** @return array<string, mixed> */
    private function read(string $path, int $actor = 1): array
    {
        $response = $this->request('GET', $path, actor: $actor);
        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        return $response->data;
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
            $key ?? 'learning-' . ++$this->sequence
        ));
    }
}
