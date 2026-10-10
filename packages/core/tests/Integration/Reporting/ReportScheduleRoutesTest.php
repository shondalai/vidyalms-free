<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\KernelReady;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The five report-schedule routes through the real routed kernel, and every
 * refusal.
 *
 * Routed rather than direct, because three of the properties are properties of
 * the routing and of the middleware around it rather than of the use case: that
 * the required idempotency key is enforced before the body is read, that a
 * replay returns the first answer, and that the collection and the item paths
 * do not shadow each other.
 */
final class ReportScheduleRoutesTest extends TestCase
{
    private const MANAGER = 1;
    private const TEACHER = 10;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::TEACHER, Capability::TEACH);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::TEACHER, 'Tara Teacher', 'tara@example.test');

        $this->kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $this->kernel->apiKernel();
    }

    public function testASavedScheduleDefaultsToPerRecipientRenderingAndToDisabled(): void
    {
        $response = $this->create();

        self::assertSame(201, $response->status);
        self::assertIsArray($response->data);
        self::assertSame('recipient', $response->data['render_as']);
        self::assertSame('disabled', $response->data['state']);
        self::assertSame('csv', $response->data['format_key']);
        self::assertSame('notification-link', $response->data['delivery_key']);
        self::assertSame(1, $response->data['definition_version']);
        self::assertNull($response->data['organisation_id']);
    }

    /**
     * The whole reason a report publishes a parameter schema: the refusal
     * happens with the author present rather than at 03:00 on a row nobody
     * opens.
     */
    public function testAParameterSetTheReportRefusesIsRefusedAtSaveTime(): void
    {
        // The roster declares course_id required and status enumerated, so
        // both of these fail against its own published schema rather than
        // against anything this route knows.
        $missing = $this->create(['parameters' => []], 'missing-key');

        self::assertSame(422, $missing->status);
        self::assertSame(0, $this->env->database->count(Query::from('report_schedules')));

        $wrong = $this->create(['parameters' => ['course_id' => 1, 'status' => 'not-a-status']], 'wrong-key');

        self::assertSame(422, $wrong->status);
        self::assertSame(0, $this->env->database->count(Query::from('report_schedules')));
    }

    public function testAFormatTheSiteCannotWriteIsRefusedWithWhatItCanWrite(): void
    {
        $response = $this->create(['format_key' => 'xlsx']);

        self::assertSame(422, $response->status);
        self::assertIsArray($response->data);
        self::assertStringContainsString('csv', json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    public function testAnAudienceNobodyResolvesIsRefusedRatherThanReachingNobodyEveryNight(): void
    {
        self::assertSame(422, $this->create(['audience_key' => 'nobody-registers-this'])->status);
    }

    public function testAReportNobodyRegistersIsRefused(): void
    {
        self::assertSame(422, $this->create(['report_key' => 'no-such-report'])->status);
    }

    /**
     * The tenancy anchor is resolved on the organisation surface and never
     * accepted as a field, so a schedule cannot be pointed at a tenant its
     * author was not authorised for.
     */
    public function testAnOrganisationIdInTheBodyIsRefusedRatherThanIgnored(): void
    {
        self::assertSame(422, $this->create(['organisation_id' => 4])->status);
    }

    public function testAMissingIdempotencyKeyIsFourHundredAndWritesNothing(): void
    {
        $response = $this->api->handle(new ApiRequest(
            'POST',
            '/manage/report-schedules',
            $this->manager(),
            [],
            $this->body(),
        ));

        self::assertSame(400, $response->status);
        self::assertSame(0, $this->env->database->count(Query::from('report_schedules')));
    }

    public function testAReplayedSaveReturnsTheFirstAnswerAndWritesOneRow(): void
    {
        $first = $this->create([], 'replay-key');
        $second = $this->create([], 'replay-key');

        self::assertSame(201, $first->status);
        self::assertSame(201, $second->status);
        self::assertIsArray($first->data);
        self::assertIsArray($second->data);
        self::assertSame($first->data['id'], $second->data['id']);
        self::assertSame(1, $this->env->database->count(Query::from('report_schedules')));
    }

    public function testTheListPagesAndFiltersAndIsBehindManage(): void
    {
        $this->create([], 'one');
        $this->create(['title' => 'Second'], 'two');

        $all = $this->call('GET', '/manage/report-schedules', $this->manager());
        self::assertSame(200, $all->status);
        self::assertIsArray($all->data);
        self::assertSame(2, $all->data['total']);

        $filtered = $this->call('GET', '/manage/report-schedules', $this->manager(), [], [
            'report_key' => 'no-such-report',
        ]);
        self::assertIsArray($filtered->data);
        self::assertSame(0, $filtered->data['total']);

        self::assertSame(403, $this->call('GET', '/manage/report-schedules', $this->teacher())->status);
        self::assertSame(401, $this->call('GET', '/manage/report-schedules', Principal::guest())->status);
    }

    public function testAnEditValidatesLikeACreationAndRecomputesTheNextRun(): void
    {
        $created = $this->create();
        self::assertIsArray($created->data);
        $id = (int) $created->data['id'];

        $this->env->database->update('report_schedules', ['next_run_at' => '2026-10-01 06:00:00'], ['id' => $id]);

        $updated = $this->write('PATCH', '/manage/report-schedules/' . $id, [
            'recurrence' => 'weekly',
            'recurrence_day' => 3,
        ]);

        self::assertSame(200, $updated->status);
        self::assertIsArray($updated->data);
        self::assertSame('weekly', $updated->data['recurrence']);
        self::assertSame(3, $updated->data['recurrence_day']);
        // The boundary is recomputed from the new values rather than left to
        // fire once more under a schedule that no longer says so.
        self::assertIsString($updated->data['next_run_at']);
        self::assertNotSame('2026-10-01 06:00:00', $updated->data['next_run_at']);

        $refused = $this->write('PATCH', '/manage/report-schedules/' . $id, ['timezone' => 'Mars/Olympus']);
        self::assertSame(422, $refused->status);
    }

    public function testDeletingASchedulLeavesItsFinishedRunsAlone(): void
    {
        $created = $this->create();
        self::assertIsArray($created->data);
        $id = (int) $created->data['id'];

        $this->env->database->insert('report_runs', [
            'report_key' => EnrolmentRosterReport::KEY,
            'format_key' => 'csv',
            'timezone' => 'UTC',
            'scope_digest' => 'v1|site',
            'requested_by' => self::MANAGER,
            'schedule_id' => $id,
            'state' => 'done',
            'row_count' => 3,
            'expires_at' => '2027-01-01 00:00:00',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);

        $removed = $this->write('DELETE', '/manage/report-schedules/' . $id, []);

        self::assertSame(204, $removed->status);
        self::assertSame(0, $this->env->database->count(Query::from('report_schedules')));
        self::assertSame(1, $this->env->database->count(Query::from('report_runs')));
        // A fresh key, because the same one replays the first answer rather
        // than reaching the use case again, which is what the key is for.
        self::assertSame(404, $this->write('DELETE', '/manage/report-schedules/' . $id, [], 'second-delete')->status);
    }

    /**
     * One run per recipient under the default, and the recurrence untouched:
     * this is a test firing and not an advance, and a manual run that consumed
     * the next occurrence would skip the one the site was waiting for.
     */
    public function testRunningOneByHandAnswers202WithARunListAndDoesNotAdvanceTheRecurrence(): void
    {
        $ready = new KernelReady($this->kernel);
        (static function (KernelReady $event): void {
            $event->registries()->audiences->register(new ThrowawayAudience([self::MANAGER]));
            $event->registries()->reports->register(new ThrowawayReport());
        })($ready);

        // A report with no course claim of its own, because the roster is
        // scoped to the courses a reader teaches and a per-recipient render of
        // it correctly refuses a recipient who teaches none. That refusal is
        // the seam working; it is not what this case is about.
        $created = $this->create([
            'state' => 'enabled',
            'audience_key' => ThrowawayAudience::KEY,
            'report_key' => ThrowawayReport::KEY,
            'parameters' => ['colour' => 'green'],
        ]);
        self::assertIsArray($created->data);
        $id = (int) $created->data['id'];

        $this->env->database->update('report_schedules', ['next_run_at' => '2026-10-01 06:00:00'], ['id' => $id]);

        $response = $this->write('POST', '/manage/report-schedules/' . $id . '/run', []);

        self::assertSame(202, $response->status);
        self::assertSame('5', $response->headers['Retry-After'] ?? null);
        self::assertIsArray($response->data);
        self::assertSame($id, $response->data['schedule_id']);
        self::assertSame('recipient', $response->data['render_as']);
        self::assertIsArray($response->data['runs']);
        self::assertCount(1, $response->data['runs']);
        self::assertSame(1, $this->env->database->count(Query::from('report_runs')));

        $row = $this->env->database->selectOne(Query::from('report_schedules')->where('id', '=', $id));
        self::assertIsArray($row);
        self::assertSame('2026-10-01 06:00:00', $row['next_run_at']);
    }

    public function testARunOfAScheduleThatIsNotThereIsNotFound(): void
    {
        self::assertSame(404, $this->write('POST', '/manage/report-schedules/999/run', [])->status);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function create(array $overrides = [], string $key = 'create-key'): ApiResponse
    {
        return $this->write('POST', '/manage/report-schedules', $this->body($overrides), $key);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Weekly roster',
            'report_key' => EnrolmentRosterReport::KEY,
            'audience_key' => 'site_managers',
            'parameters' => ['course_id' => 1],
            'recurrence' => 'daily',
            'run_at_minute' => 360,
            'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-09-01T06:00:00+00:00',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function write(string $method, string $path, array $body, string $key = 'write-key'): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $this->manager(), [], $body, $key));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     */
    private function call(
        string $method,
        string $path,
        Principal $principal,
        array $body = [],
        array $query = [],
    ): ApiResponse {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query, $body));
    }

    private function manager(): Principal
    {
        return Principal::user(self::MANAGER);
    }

    private function teacher(): Principal
    {
        return Principal::user(self::TEACHER);
    }
}
