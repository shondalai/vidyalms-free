<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCertificateReissueStatus;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseScheduledTaskInspector;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\InspectsDatabaseQueue;
use Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\UnresolvedTaskType;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\FrozenClock;
use Shondalai\VidyaLMS\Core\Tests\Support\SqliteDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\StubAuthorization;

final class OperationsReadTest extends TestCase
{
    private SqliteDatabase $db;
    private FrozenClock $clock;
    private DatabaseScheduledTaskInspector $inspector;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::inMemory();
        $this->db->pdo()->exec('CREATE TABLE vidyalms_test_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT, payload TEXT,'
            . ' dedupe_key TEXT UNIQUE, group_key TEXT, status TEXT, attempts INTEGER, last_error TEXT, run_at TEXT,'
            . ' reserved_at TEXT, finished_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->clock = new FrozenClock();
        $this->inspector = new DatabaseScheduledTaskInspector($this->db, $this->clock, 'test_jobs');
    }

    public function testQueueCountsAndPagingNeverDisclosePayloadOrRawErrors(): void
    {
        for ($i = 1; $i <= 203; $i++) {
            $this->row('job:' . $i, null, $i % 2 === 0 ? 'failed' : 'pending');
        }
        $page = $this->inspector->page(2, 25, 'failed', null);
        self::assertSame(101, $page['total']);
        self::assertCount(25, $page['items']);
        self::assertSame(152, $page['items'][0]['id']);
        self::assertStringNotContainsString('private-token', json_encode($page, JSON_THROW_ON_ERROR));
        $overview = $this->inspector->overview();
        self::assertSame(102, $overview['counts']['queued']);
        self::assertSame(102, $overview['counts']['due']);
        self::assertSame(101, $overview['counts']['failed']);
    }

    public function testGroupProgressUsesPersistedWorkAndNeverInventsAPercentage(): void
    {
        $key = 'reissue:template:7:2';
        $root = $this->row($key, $key, 'pending', 'fanout');
        self::assertSame('queued', self::data($this->inspector->group($key))['state']);
        $this->db->update('test_jobs', ['status' => 'done'], ['id' => $root]);
        $child = $this->row('certificate:1:2', $key, 'running');
        $running = $this->inspector->group($key);
        self::assertIsArray($running);
        self::assertSame('running', $running['state']);
        self::assertNull($running['percent']);
        self::assertTrue($running['selection_complete']);
        self::assertSame(2, $running['counts']['total']);
        $this->db->update('test_jobs', ['status' => 'done'], ['id' => $child]);
        self::assertSame('complete', self::data($this->inspector->group($key))['state']);
    }

    public function testLatestRunUsesRootCreationNotAnOlderContinuationThatRanLater(): void
    {
        $old = 'reissue:template:7:2';
        $new = 'reissue:template:7:3';
        $this->row($old, $old, 'done', 'fanout');
        $this->row($new, $new, 'pending', 'fanout');
        $this->row($old . ':900', $old, 'pending', 'fanout');
        self::assertSame($new, self::data($this->inspector->latestGroup('reissue:template:7:'))['key']);
        self::assertNull($this->inspector->latestGroup('reissue:template:70:'));
    }

    public function testCancelRetainsLedgerAndRetryDoesNotResetFinishedChildren(): void
    {
        $key = 'reissue:template:7:2';
        $this->row($key, $key, 'running', 'fanout');
        $this->row($key . ':100', $key, 'pending', 'fanout');
        $done = $this->row('certificate:1:2', $key, 'done');
        $live = $this->row('certificate:2:2', $key, 'pending');
        $this->inspector->cancel($key);
        self::assertTrue($this->inspector->cancelled($key));
        self::assertSame('complete', self::data($this->inspector->record($done))['state']);
        self::assertSame('queued', self::data($this->inspector->record($live))['state']);
        self::assertSame(2, $this->inspector->retry($key));
        self::assertFalse($this->inspector->cancelled($key));
        self::assertSame('complete', self::data($this->inspector->record($done))['state']);
    }

    public function testOptionalQueueCapabilityComposesWithTheGuardedLatestRunUseCase(): void
    {
        $scheduler = new class ($this->inspector) implements SchedulerPort, InspectsScheduledTasks {
            use InspectsDatabaseQueue;

            public function __construct(private readonly DatabaseScheduledTaskInspector $inspector)
            {
            }

            private function queueInspector(): DatabaseScheduledTaskInspector
            {
                return $this->inspector;
            }

            public function schedule(ScheduledTask $task): void
            {
                throw new \LogicException('This test must never schedule work from a status read.');
            }

            public function cancelByDedupeKey(string $dedupeKey): void
            {
                throw new \LogicException('This test must never cancel work from a status read.');
            }
        };
        $key = 'reissue:template:7:2';
        $id = $this->row($key, $key, 'pending', 'fanout');
        $useCase = new GetCertificateReissueStatus($scheduler, new AccessGuard(new StubAuthorization(true)));
        $result = $useCase->execute(Principal::user(1), 7, null);
        self::assertSame(2, $result['revision']);
        self::assertSame('queued', $result['state']);
        self::assertSame($id, $result['root_job']['id']);
        self::assertSame('/manage/certificate-templates/7/reissue?revision=2', $result['audit_url']);
        self::assertSame(1, $scheduler->taskPage(1, 10)['total']);
        self::assertSame(1, $scheduler->queueOverview()['counts']['total']);
        self::assertSame('queued', self::data($scheduler->taskRecord($id))['state']);
    }

    public function testRoutesEnforceDifferentReportAndOperationsCapabilities(): void
    {
        $fixture = ManagementFixture::create();
        foreach (
            [
            '/manage/reports/overview', '/manage/operations', '/manage/operations/jobs', '/manage/certificate-templates/1/reissue',
            ] as $path
        ) {
            self::assertSame(401, $fixture->call('GET', $path, Principal::guest())->status);
        }
        self::assertSame(403, $fixture->call('GET', '/manage/operations', $fixture->manager())->status);
        self::assertSame(200, $fixture->call('GET', '/manage/reports/overview', $fixture->manager())->status);
        $scoped = $fixture->call('GET', '/manage/reports/overview', $fixture->teacher());
        self::assertSame('private, no-store', $scoped->headers['Cache-Control']);
        self::assertSame('assigned_courses', self::data($scoped->data)['scope']);
        self::assertSame(1, self::data($scoped->data)['total']);
        $fixture->env->authorization->grant(ManagementFixture::MANAGER_ID, Capability::ADMIN);
        $overview = $fixture->call('GET', '/manage/operations', $fixture->manager());
        self::assertSame(200, $overview->status);
        self::assertSame('private, no-store', $overview->headers['Cache-Control']);
        self::assertFalse(self::data($overview->data)['scheduler']['available']);
        self::assertNull(self::data($overview->data)['worker_heartbeat']);
        $idle = $fixture->call('GET', '/manage/certificate-templates/1/reissue', $fixture->manager());
        self::assertSame('private, no-store', $idle->headers['Cache-Control']);
        self::assertSame('idle', self::data($idle->data)['state']);
    }

    public function testReportReconcilesTheFilteredPopulationAndDoesNotAverageCourseRates(): void
    {
        $fixture = ManagementFixture::create();
        foreach (
            [
            [$fixture->courseId, 'active', null], [$fixture->courseId, 'completed', '2026-01-15 00:00:00'],
            [$fixture->otherCourseId, 'active', null],
            ] as $i => [$course, $status, $completed]
        ) {
            $fixture->env->database->insert('enrolments', ['user_id' => 100 + $i, 'course_id' => $course,
                'entitlement_id' => $fixture->entitlementId, 'status' => $status,
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
                'enrolled_at' => '2026-01-01 00:00:00', 'completed_at' => $completed]);
        }
        $result = $fixture->call('GET', '/manage/reports/overview', $fixture->manager(), query: ['per_page' => '1']);
        self::assertSame(200, $result->status, json_encode($result->data, JSON_THROW_ON_ERROR));
        self::assertIsArray($result->data);
        self::assertCount(1, $result->data['items']);
        self::assertSame(4, $result->data['summary']['enrolments']);
        self::assertSame(1, $result->data['summary']['completed']);
        self::assertEqualsWithDelta(1 / 4, $result->data['summary']['completion_rate'], 0.00001);
        self::assertSame('all_time', $result->data['period']);
    }

    /**
     * The ScheduledTask miss direction, from the reading end.
     *
     * A drain that meets a job type nothing is registered for leaves the row
     * pending rather than failing it, which is the whole direction: the work
     * has not been lost and installing the package finishes it. That is only
     * half an answer while the operations screen shows such a row as an
     * ordinary queued job, indistinguishable from one waiting its turn, so
     * the row carries the shared marker and this is where it is read back.
     *
     * Three rows and three answers, and the middle one is the reason the
     * marker exists at all: a job requeued after a transient failure is also
     * pending and also carries an error, and it says nothing, because it is
     * going to run again by itself and there is nothing for anyone to do.
     */
    public function testAJobLeftPendingBecauseNothingAnswersItsTypeSaysSoAndARequeuedOneDoesNot(): void
    {
        $unresolved = $this->row('pro:sweep:1', null, 'pending', 'pro.licence_sweep');
        $this->db->update('test_jobs', ['last_error' => UnresolvedTaskType::note('pro.licence_sweep')], ['id' => $unresolved]);
        $requeued = $this->row('certificate:9:1', null, 'pending');
        $this->db->update('test_jobs', ['last_error' => 'SQLSTATE[HY000]: private-token timed out'], ['id' => $requeued]);
        $failed = $this->row('certificate:8:1', null, 'failed');

        $said = self::data($this->inspector->record($unresolved))['diagnostic'];
        self::assertIsString($said);
        self::assertStringContainsString('pro.licence_sweep', $said, 'The operator has to know which type.');
        self::assertStringContainsString('not marked failed', $said, 'And that the work is still there.');
        self::assertSame('queued', self::data($this->inspector->record($unresolved))['state']);

        self::assertNull(
            self::data($this->inspector->record($requeued))['diagnostic'],
            'A job that will retry itself is nobody\'s problem and says nothing.'
        );
        self::assertSame(
            'Task failed. Review the protected server logs using this job id.',
            self::data($this->inspector->record($failed))['diagnostic']
        );

        // The stored note is read and never published, like every other
        // stored error on this surface.
        $page = json_encode($this->inspector->page(1, 25, null, null), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-token', $page);
        self::assertStringNotContainsString(UnresolvedTaskType::MARKER, $page);
    }

    private function row(string $key, ?string $group, string $state, string $type = 'certificate'): int
    {
        return $this->db->insert('test_jobs', ['dedupe_key' => $key, 'group_key' => $group, 'job_type' => $type,
            'payload' => '{"secret":"private-token"}', 'last_error' => 'private-token', 'status' => $state, 'attempts' => 1,
            'run_at' => '2026-01-15 09:00:00', 'reserved_at' => null, 'finished_at' => null,
            'created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00']);
    }

    /**
     * @param array<string, mixed>|null $value
     * @return array<string, mixed>
     */
    private static function data(?array $value): array
    {
        self::assertIsArray($value);
        return $value;
    }
}
