<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\KernelReady;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Report\CourseOverviewReport;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgAccessLogReport;
use Shondalai\VidyaLMS\Core\Provider\Report\SiteLearningReport;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Every reporting route through the real routed kernel, and every refusal.
 *
 * These are routed rather than direct calls on purpose: the registration
 * order of `/manage/reports/overview` against `/manage/reports/{key}` is a
 * property of the kernel and not of any class, and a test that called the use
 * cases would pass with the two registered the wrong way round.
 */
final class ReportingRoutesTest extends TestCase
{
    private const MANAGER = 1;
    private const TEACHER = 10;
    private const LEARNER = 50;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private int $courseId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::TEACHER, Capability::TEACH);
        $this->env->authorization->grant(self::TEACHER, Capability::VIEW_REPORTS);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::TEACHER, 'Tara Teacher', 'tara@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');

        $this->kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $this->kernel->apiKernel();
        $this->seed();
    }

    // ── The site-scoped read and its miss contract ───────────────────────

    public function testTheOverviewAliasStillAnswersItsOwnShape(): void
    {
        $response = $this->call('GET', '/manage/reports/overview', $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertArrayHasKey('items', $response->data);
        self::assertArrayHasKey('completion_basis', $response->data);
        self::assertSame('sitewide', $response->data['scope']);
        self::assertSame(1, $response->data['total']);
    }

    /**
     * The alias is registered first, so the parameterised route may not
     * swallow it. Route matching is first-registration-wins with no
     * literal-over-parameter precedence, and swapping the two registrations
     * would answer the registry-miss shape here.
     */
    public function testTheAliasIsNotSwallowedByTheParameterisedRoute(): void
    {
        $alias = $this->call('GET', '/manage/reports/overview', $this->manager());

        self::assertIsArray($alias->data);
        self::assertArrayNotHasKey('computed', $alias->data, 'the alias answered the registry-miss shape');
        self::assertArrayHasKey('period', $alias->data);
    }

    public function testTheSiteRouteServesTheSameReportByItsKey(): void
    {
        $response = $this->call('GET', '/manage/reports/' . SiteLearningReport::KEY, $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertTrue($response->data['computed']);
        self::assertSame(SiteLearningReport::KEY, $response->data['computed_by']);
        self::assertSame(1, $response->data['total']);
    }

    public function testAKeyNothingRegistersIsAHundredAndTwoWithAReason(): void
    {
        $response = $this->call('GET', '/manage/reports/nothing-registers-this', $this->manager());

        self::assertSame(200, $response->status, 'a registry miss is never a 404');
        self::assertIsArray($response->data);
        self::assertFalse($response->data['computed']);
        self::assertNull($response->data['computed_by']);
        self::assertNotNull($response->data['reason']);
        self::assertSame([], $response->data['items']);
    }

    /**
     * An organisation-only key takes the same answer, so a caller cannot tell
     * an organisation report apart from a report nobody installed by reading
     * the reasons.
     */
    public function testAnOrganisationOnlyKeyAnswersTheMissShapeOnTheSiteRoute(): void
    {
        $response = $this->call('GET', '/manage/reports/' . OrgAccessLogReport::KEY, $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertFalse($response->data['computed']);
        self::assertNull($response->data['computed_by']);
    }

    public function testAGuestIsRefusedBeforeTheRegistryIsConsulted(): void
    {
        self::assertSame(401, $this->call('GET', '/manage/reports/nothing-here', Principal::guest())->status);
    }

    public function testALearnerIsRefusedForAKeyThatExistsAndOneThatDoesNot(): void
    {
        self::assertSame(403, $this->call('GET', '/manage/reports/' . SiteLearningReport::KEY, $this->learner())->status);
        self::assertSame(403, $this->call('GET', '/manage/reports/nothing-here', $this->learner())->status);
    }

    // ── The 202 export contract ──────────────────────────────────────────

    public function testAnExportAnswers202WithARunIdAndTheRunIsThenPollable(): void
    {
        $accepted = $this->call(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'csv', 'course_id' => (string) $this->courseId],
        );

        self::assertSame(202, $accepted->status);
        self::assertIsArray($accepted->data);
        self::assertIsInt($accepted->data['id']);
        self::assertSame('csv', $accepted->data['format']);
        self::assertArrayHasKey('Retry-After', $accepted->headers);

        $polled = $this->call('GET', '/manage/report-runs/' . $accepted->data['id'], $this->manager());

        self::assertSame(200, $polled->status);
        self::assertIsArray($polled->data);
        self::assertSame(ReportRun::STATE_DONE, $polled->data['state']);
        self::assertSame(2, $polled->data['row_count']);
        // Progress beside the answer, so a poll on a long export says how far
        // it has got rather than only that it is still going.
        self::assertSame(2, $polled->data['current_line']);
        self::assertIsString($polled->data['download_url']);
        self::assertNotSame('', $polled->data['expires_at'], 'expires_at is never null');
    }

    /**
     * A run belongs to the person who asked for it. The artefact was computed
     * under one caller's visibility scope, so a second caller holding the same
     * capability is served rows that were never computed for them.
     */
    public function testARunBelongsToItsRequesterAndNotToEveryReportsHolder(): void
    {
        $accepted = $this->call(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'json', 'course_id' => (string) $this->courseId],
        );

        self::assertIsArray($accepted->data);
        $runId = $accepted->data['id'];

        self::assertSame(403, $this->call('GET', '/manage/report-runs/' . $runId, $this->teacher())->status);
        $this->env->authorization->revoke(self::MANAGER, Capability::MANAGE);
        self::assertSame(403, $this->call('GET', '/manage/report-runs/' . $runId, $this->manager())->status,
            'Keeping reports permission after demotion does not retain the site-wide artefact');
    }

    /**
     * The roster leak. A principal holding only VIEW_REPORTS and teaching
     * nothing is handed a user-typed scope by the site routes; before the
     * fix the report saw a scope that was not TYPE_COURSE, fell through to the
     * caller's own course_id parameter, and served every learner's name, id
     * and progress for any course on the site, while the course-scoped route
     * answered the same person 403 for the same course.
     */
    public function testTheRosterRefusesACourseTheCallerDoesNotTeach(): void
    {
        $read = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->teacher(),
            query: ['course_id' => (string) $this->courseId],
        );

        self::assertSame(422, $read->status);

        $export = $this->call(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->teacher(),
            query: ['format' => 'csv', 'course_id' => (string) $this->courseId],
        );

        self::assertSame(422, $export->status);
        self::assertSame(0, $this->runCount(), 'a refused export wrote an artefact of personal data');
    }

    public function testTheRosterStillServesACourseTheCallerActuallyTeaches(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('course_instructors', [
            'course_id' => $this->courseId,
            'user_id' => self::TEACHER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $read = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->teacher(),
            query: ['course_id' => (string) $this->courseId],
        );

        self::assertSame(200, $read->status);
        self::assertIsArray($read->data);
        self::assertTrue($read->data['computed']);
    }

    /**
     * The published default for a provider that does not declare
     * FiltersByVisibility is that a scoped run refuses it. Before the fix the
     * site read and export routes probed nothing, so a report registered from
     * a KernelReady listener returned site-wide rows to a caller narrowed to
     * their own courses.
     */
    public function testAReportThatDoesNotDeclareTheVisibilityMarkerIsRefusedInAScopedRun(): void
    {
        $ready = new KernelReady($this->kernel);
        (static function (KernelReady $event): void {
            $event->registries()->reports->register(new SiteWideOnlyReport());
        })($ready);

        // A manager is site-wide, so the provider is trusted and answers.
        $wide = $this->call('GET', '/manage/reports/' . SiteWideOnlyReport::KEY, $this->manager());
        self::assertSame(200, $wide->status);
        self::assertIsArray($wide->data);
        self::assertTrue($wide->data['computed']);

        // The teacher is narrowed to their own courses, so the same provider
        // is refused with the miss shape rather than trusted to narrow itself.
        $narrow = $this->call('GET', '/manage/reports/' . SiteWideOnlyReport::KEY, $this->teacher());
        self::assertSame(200, $narrow->status);
        self::assertIsArray($narrow->data);
        self::assertFalse($narrow->data['computed']);
        self::assertSame([], $narrow->data['items']);

        $export = $this->call(
            'POST',
            '/manage/reports/' . SiteWideOnlyReport::KEY . '/exports',
            $this->teacher(),
            query: ['format' => 'csv'],
        );
        self::assertSame(422, $export->status);
        self::assertSame(0, $this->runCount());

        // The same probe closes a second instance of the roster leak that
        // nobody had named: course-overview declares no visibility marker and
        // reads the caller's own course_id, so on this route it answered a
        // teacher who teaches nothing with any course's enrolled, completed
        // and average-progress counts. It is served through
        // /manage/courses/{id}/reports/{key}, which authorises per course.
        $scalar = $this->call(
            'GET',
            '/manage/reports/' . CourseOverviewReport::KEY,
            $this->teacher(),
            query: ['course_id' => (string) $this->courseId],
        );

        self::assertSame(200, $scalar->status);
        self::assertIsArray($scalar->data);
        self::assertFalse($scalar->data['computed'], 'a scalar report that ignores the scope was still computed');

        // And a site-wide caller still gets it, so nothing was taken away.
        $wideScalar = $this->call(
            'GET',
            '/manage/reports/' . CourseOverviewReport::KEY,
            $this->manager(),
            query: ['course_id' => (string) $this->courseId],
        );

        self::assertIsArray($wideScalar->data);
        self::assertTrue($wideScalar->data['computed']);
    }

    /**
     * A 202 is exactly the answer a client retries, because the work is not
     * finished when the response arrives.
     *
     * Before the fix three identical POSTs produced three runs, three
     * artefacts of personal data on disk and three delivery rows, all under
     * one scope digest, and the operation declared no Idempotency-Key.
     */
    public function testARetriedExportProducesOneRunAndOneArtefact(): void
    {
        $responses = [];

        foreach ([1, 2, 3] as $attempt) {
            $responses[] = $this->api->handle(new ApiRequest(
                'POST',
                '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
                $this->manager(),
                ['format' => 'csv', 'course_id' => (string) $this->courseId],
                [],
                'the-client-retried-this',
            ));
        }

        $first = $responses[0]->data;
        self::assertIsArray($first);

        foreach ($responses as $response) {
            self::assertSame(202, $response->status);
            self::assertIsArray($response->data);
            self::assertSame($first['id'], $response->data['id']);
        }

        self::assertSame(1, $this->runCount(), 'a retry opened a second run');

        // A different key is a different request and still opens its own run,
        // so the guard is replay protection rather than a cache.
        $other = $this->api->handle(new ApiRequest(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->manager(),
            ['format' => 'csv', 'course_id' => (string) $this->courseId],
            [],
            'a-different-request',
        ));

        self::assertSame(202, $other->status);
        self::assertSame(2, $this->runCount());
    }

    public function testAScalarReportIsRefusedForExportRatherThanWrittenEmpty(): void
    {
        $response = $this->call(
            'POST',
            '/manage/reports/' . CourseOverviewReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'csv', 'course_id' => (string) $this->courseId],
        );

        self::assertSame(422, $response->status);
        self::assertSame(0, $this->runCount(), 'a refused export writes no run row');
    }

    public function testAFormatNobodyRegisteredIsRefusedWithTheFormatsThatExist(): void
    {
        $response = $this->call(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'xlsx', 'course_id' => (string) $this->courseId],
        );

        self::assertSame(422, $response->status);
    }

    public function testAnUnknownReportKeyCannotStartAnExport(): void
    {
        $response = $this->call(
            'POST',
            '/manage/reports/nothing-registers-this/exports',
            $this->manager(),
            query: ['format' => 'csv'],
        );

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->runCount());
    }

    /**
     * parameterSchema() had no caller anywhere and no route published a
     * catalogue, so a manage screen could not discover a registered report,
     * could not render its filter form, and a third-party definition was made
     * to implement a method nothing read.
     */
    public function testTheCatalogueListsEveryReportTheCallerMayRunWithItsFilterSchema(): void
    {
        $response = $this->call('GET', '/manage/reports', $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);

        $keys = array_map(
            static fn (array $report): mixed => $report['key'],
            $response->data['reports'],
        );

        self::assertContains(EnrolmentRosterReport::KEY, $keys);
        self::assertContains(CourseOverviewReport::KEY, $keys);
        self::assertContains(SiteLearningReport::KEY, $keys);
        // An organisation-only report is listed on the organisation surface,
        // not here, because the two routes answer about different scopes.
        self::assertNotContains(OrgAccessLogReport::KEY, $keys);

        foreach ($response->data['reports'] as $report) {
            if ($report['key'] !== EnrolmentRosterReport::KEY) {
                continue;
            }

            self::assertTrue($report['tabular']);
            self::assertTrue($report['exportable']);
            self::assertTrue($report['honours_visibility']);
            self::assertIsArray($report['parameter_schema']);
            self::assertSame(['course_id'], $report['parameter_schema']['required']);
            self::assertArrayHasKey('status', $report['parameter_schema']['properties']);

            return;
        }

        self::fail('the roster is not in the catalogue');
    }

    public function testTheCatalogueIsNotADirectoryOfWhatTheCallerCannotRun(): void
    {
        self::assertSame(403, $this->call('GET', '/manage/reports', $this->learner())->status);
    }

    /**
     * A tabular report used to answer 200 with a row count in its facts, an
     * empty `items` and a total of zero, so the only way to see its rows was
     * to export them. The published `page` and `per_page` parameters were also
     * inert, because a query string is always strings and the reader was
     * strict about integers.
     */
    public function testATabularReportRendersItsRowsAndPagesThem(): void
    {
        $first = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->manager(),
            query: ['course_id' => (string) $this->courseId, 'per_page' => '1', 'page' => '1'],
        );

        self::assertSame(200, $first->status);
        self::assertIsArray($first->data);
        self::assertTrue($first->data['computed']);
        self::assertCount(1, $first->data['items'], 'a tabular report rendered no rows');
        self::assertSame(2, $first->data['total']);
        self::assertSame(1, $first->data['per_page'], 'per_page from the query string was ignored');
        self::assertIsArray($first->data['columns']);

        $second = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->manager(),
            query: ['course_id' => (string) $this->courseId, 'per_page' => '1', 'page' => '2'],
        );

        self::assertIsArray($second->data);
        self::assertCount(1, $second->data['items']);
        self::assertNotSame($first->data['items'], $second->data['items'], 'page 2 served page 1');
    }

    /**
     * "A condition is not a filter": a parameter the definition never declared
     * must not reach a provider that happens to read it. The schema had no
     * caller, so the bag reached ReportContext exactly as the query string
     * spelled it.
     */
    public function testAFilterOutsideTheDeclaredSchemaIsRefused(): void
    {
        $refused = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->manager(),
            query: ['course_id' => (string) $this->courseId, 'status' => 'not-a-status'],
        );

        self::assertSame(422, $refused->status);

        // And a declared one still works, typed from the string it arrived as.
        $accepted = $this->call(
            'GET',
            '/manage/reports/' . EnrolmentRosterReport::KEY,
            $this->manager(),
            query: ['course_id' => (string) $this->courseId, 'status' => 'active'],
        );

        self::assertSame(200, $accepted->status);
    }

    // ── The free evidence route ──────────────────────────────────────────

    public function testTheAccessEvidenceRouteAnswersABundleAndNamesWhatIsMissing(): void
    {
        $response = $this->call(
            'GET',
            '/manage/learners/' . self::LEARNER . '/courses/' . $this->courseId . '/access-evidence',
            $this->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(self::LEARNER, $response->data['user_id']);
        self::assertIsArray($response->data['entitlements']);
        self::assertIsArray($response->data['warnings']);
        self::assertContains('no_entitlement_on_record', $response->data['warnings']);
    }

    public function testTheEvidenceRouteRefusesSomebodyWithNoClaimOnTheCourse(): void
    {
        self::assertSame(403, $this->call(
            'GET',
            '/manage/learners/' . self::LEARNER . '/courses/' . $this->courseId . '/access-evidence',
            $this->learner(),
        )->status);
    }

    // ── Zero core edits ──────────────────────────────────────────────────

    /**
     * A report registered from a throwaway listener is served by both report
     * routes with no core file edited, which is the literal test ADR 0014
     * sets and the whole claim this wave rests on.
     */
    public function testAReportRegisteredFromAKernelReadyListenerIsServed(): void
    {
        $ready = new KernelReady($this->kernel);
        (static function (KernelReady $event): void {
            $event->registries()->reports->register(new ThrowawayReport());
        })($ready);

        $read = $this->call('GET', '/manage/reports/' . ThrowawayReport::KEY, $this->manager());

        self::assertSame(200, $read->status);
        self::assertIsArray($read->data);
        self::assertTrue($read->data['computed']);
        self::assertSame(ThrowawayReport::KEY, $read->data['computed_by']);

        $exported = $this->call(
            'POST',
            '/manage/reports/' . ThrowawayReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'csv'],
        );

        self::assertSame(202, $exported->status);
        self::assertIsArray($exported->data);
        self::assertSame(3, $exported->data['row_count']);
    }

    // ── The retention sweep ──────────────────────────────────────────────

    public function testTheSweepDeletesThePastDueArtefactAndKeepsTheManifest(): void
    {
        $accepted = $this->call(
            'POST',
            '/manage/reports/' . EnrolmentRosterReport::KEY . '/exports',
            $this->manager(),
            query: ['format' => 'csv', 'course_id' => (string) $this->courseId],
        );

        self::assertIsArray($accepted->data);
        $runId = $accepted->data['id'];
        $run = $this->kernel->reportRuns->find($runId);

        self::assertNotNull($run);
        self::assertNotNull($run->storagePath);
        self::assertTrue($this->env->storage->exists($run->storagePath));

        // Past its retention date, which is the only thing the sweep looks at.
        $this->env->database->update(
            'report_runs',
            ['expires_at' => '2000-01-01 00:00:00'],
            ['id' => $runId],
        );

        $line = $this->kernel->runScheduledTask(
            'reports.sweep',
            1,
            [],
            $this->manager(),
        );

        self::assertIsString($line);
        self::assertStringContainsString('removed=1', $line);
        self::assertStringContainsString('without_expiry=0', $line);
        self::assertFalse($this->env->storage->exists($run->storagePath));

        $swept = $this->kernel->reportRuns->find($runId);

        self::assertNotNull($swept);
        self::assertSame(ReportRun::STATE_EXPIRED, $swept->state, 'the manifest survives the artefact');
        self::assertNull($swept->storagePath);
    }

    /** The background task authorises before it calls a provider. */
    public function testTheGenerateTaskRefusesAnActorWithoutTheCapability(): void
    {
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);

        $this->kernel->registries->scheduledTasks->get('vidyalms.report.generate')->run(new ScheduledTaskRun(
            7,
            ['report' => CourseOverviewReport::KEY, 'course_id' => $this->courseId],
            $this->learner(),
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function manager(): Principal
    {
        return Principal::user(self::MANAGER);
    }

    private function teacher(): Principal
    {
        return Principal::user(self::TEACHER);
    }

    private function learner(): Principal
    {
        return Principal::user(self::LEARNER);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     */
    private function call(string $method, string $path, Principal $principal, array $body = [], array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query, $body));
    }

    private function runCount(): int
    {
        return $this->env->database->count(Query::from('report_runs'));
    }

    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->courseId = $this->env->database->insert('courses', [
            'title' => 'Reporting course',
            'slug' => 'reporting-course',
            'state' => 'published',
            'created_by' => self::MANAGER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([self::LEARNER, self::TEACHER] as $userId) {
            $this->env->database->insert('enrolments', [
                'user_id' => $userId,
                'course_id' => $this->courseId,
                'entitlement_id' => 0,
                'status' => 'active',
                'enrolled_at' => $now,
                'progress_percent' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
