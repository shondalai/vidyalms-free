<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Grades\QuizGradeSync;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSuppressionPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\NaturalGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgRosterReport;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The R9b review's own closures, each driven through the seam the finding
 * named rather than through the class it was found in.
 *
 * Five sections, and the order is the order the findings were ranked in:
 * a schedule may not become the way past a refusal both site routes make; a
 * human's grade decision survives the machine; small-cell suppression is not
 * undone by the arithmetic beside it; the two read paths run one stage; and
 * every route that answers about a tenant bounds what it will answer.
 */
final class R9bReviewFindingsTest extends TestCase
{
    private const MANAGER = 1;
    private const MARKER = 2;
    private const LEARNER = 50;

    /** Holds the reports capability and NOT manage, which is the narrowed reader. */
    private const NARROWED = 900;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private int $courseId = 0;
    private int $organisationId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE_GROUPS);
        $this->env->authorization->grant(self::MARKER, Capability::GRADE);
        $this->env->authorization->grant(self::NARROWED, Capability::VIEW_REPORTS);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::MARKER, 'Mia Marker', 'mia@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');
        $this->env->users->add(self::NARROWED, 'Nina Narrowed', 'nina@example.test');

        $this->kernel = new CoreKernel($this->env->ports);
        $this->api = $this->kernel->apiKernel();
        $this->seed();
    }

    // ── One: a schedule is not the way past a refusal ─────────────────────

    /**
     * A narrowed recipient is not mailed a site-wide file the same report's own
     * route refuses them.
     *
     * `GET /manage/reports/{key}` answers `scope_unsupported` and the export
     * route answers 422 for a provider that does not declare
     * `FiltersByVisibility` under a narrowed scope. The schedule's per-recipient
     * render resolved the same narrowed scope and never made the probe, so the
     * one path that mails a file out of the site was the one path that did not
     * check whether the provider could narrow it.
     */
    public function testAScheduleDoesNotMailANarrowedRecipientASiteWideReport(): void
    {
        $this->kernel->registries->reports->register(new SiteWideOnlyReport());
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::NARROWED]));

        // The site route's own answer for exactly this pair, asserted first so
        // the schedule is compared against a refusal that is already published.
        $refused = $this->call('GET', '/manage/reports/' . SiteWideOnlyReport::KEY, $this->narrowed());

        self::assertSame(200, $refused->status);
        self::assertIsArray($refused->data);
        self::assertFalse($refused->data['computed']);

        $this->kernel->reportSchedules->save($this->schedule(SiteWideOnlyReport::KEY));
        $summary = $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::MANAGER)));

        self::assertStringContainsString('runs=0', $summary);
        self::assertSame(0, $this->env->database->count(Query::from('report_runs')));

        // And the skip is on the schedule row rather than in a NullLogger.
        $row = $this->env->database->selectOne(Query::from('report_schedules'));
        self::assertNotNull($row);
        self::assertNotNull($row['last_error']);
        self::assertStringContainsString('visibility scope', (string) $row['last_error']);
    }

    /** And the same report cannot be saved onto a schedule at all. */
    public function testASiteWideOnlyReportIsRefusedAtSaveTimeAndNotAtThreeInTheMorning(): void
    {
        $this->kernel->registries->reports->register(new SiteWideOnlyReport());
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::NARROWED]));

        $this->expectException(ValidationException::class);
        $this->kernel->manageReportSchedules->create(Principal::user(self::MANAGER), [
            'title' => 'Nightly',
            'report_key' => SiteWideOnlyReport::KEY,
            'audience_key' => TwoNamedRecipients::KEY,
        ]);
    }

    /**
     * An organisation-scoped report is refused on the site schedule surface,
     * which resolves no tenant to compute it inside.
     *
     * Every run of one used to fail inside the provider with "Validation
     * failed." recorded on `report_runs` and `last_error` left null, so the
     * schedule row read exactly like a schedule that had worked.
     */
    public function testAnOrganisationReportCannotBeSavedOntoASiteSchedule(): void
    {
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::MANAGER]));

        try {
            $this->kernel->manageReportSchedules->create(Principal::user(self::MANAGER), [
                'title' => 'Nightly roster',
                'report_key' => OrgRosterReport::KEY,
                'audience_key' => TwoNamedRecipients::KEY,
            ]);

            self::fail('An organisation-scoped report is not a site schedule.');
        } catch (ValidationException $refused) {
            self::assertArrayHasKey('report_key', $refused->errors);
            self::assertStringContainsString('organisation-scoped', (string) $refused->errors['report_key']);
        }
    }

    /**
     * A schedule anchored to an organisation runs under a witnessed tenant, so
     * the provider narrows itself and the disclosure stage has a scope to omit
     * against.
     *
     * The task passed null for the scope, so `AbstractOrgReport::scope()` threw
     * and, had it not, the field disclosure stage would have returned every
     * column unchanged: `org_minimal` would not have withheld an address.
     */
    public function testAnOrganisationAnchoredScheduleRunsUnderItsOwnTenant(): void
    {
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::MANAGER]));
        $this->env->database->insert('report_schedules', [
            'title' => 'Nightly roster',
            'report_key' => OrgRosterReport::KEY,
            'format_key' => 'csv',
            'delivery_key' => 'notification-link',
            'definition' => null,
            'definition_version' => 1,
            'audience_key' => TwoNamedRecipients::KEY,
            'audience_config' => null,
            'audience_config_version' => 1,
            'recurrence' => ReportSchedule::RECURRENCE_DAILY,
            'recurrence_day' => 1,
            'run_at_minute' => 0,
            'timezone' => 'UTC',
            'starts_at' => $this->env->clock->now()->modify('-1 day')->format('Y-m-d H:i:s'),
            'next_run_at' => $this->env->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s'),
            'render_as' => ReportSchedule::RENDER_AS_RECIPIENT,
            'empty_policy' => ReportSchedule::EMPTY_SEND,
            'organisation_id' => $this->organisationId,
            'state' => ReportSchedule::STATE_ENABLED,
            'created_by' => self::MANAGER,
            'run_count' => 0,
            'created_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $summary = $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::MANAGER)));

        self::assertStringContainsString('runs=1', $summary);

        $run = $this->env->database->selectOne(Query::from('report_runs'));

        self::assertNotNull($run);
        self::assertSame('done', (string) $run['state']);
        self::assertSame($this->organisationId, (int) $run['organisation_id']);
        self::assertNotSame('', (string) $run['scope_digest']);
        self::assertGreaterThan(0, (int) $run['row_count']);

        $schedule = $this->env->database->selectOne(Query::from('report_schedules'));
        self::assertNotNull($schedule);
        self::assertNull($schedule['last_error']);

        // And the disclosure stage really ran: org_minimal withholds the
        // address, which is the second-order half of the finding. With a null
        // scope it returned every column unchanged.
        self::assertNotNull($run['storage_path']);
        self::assertStringNotContainsString(
            'lena@example.test',
            $this->env->storage->readPrivate((string) $run['storage_path']),
        );
    }

    // ── Two: a person's grade decision survives the machine ───────────────

    /**
     * A recalculation over unchanged attempts does not write the machine's
     * number back over a human override.
     *
     * `overridden_at` was written by the regrade path and read by no branch
     * anywhere, and the contract published the opposite rule in as many words.
     */
    public function testARecomputationDoesNotUndoAHumanOverride(): void
    {
        $sync = $this->quizSync();
        $sync->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);

        $cell = $this->cellFor(7);
        self::assertNotNull($cell->id);

        $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'raw_grade' => 10,
            'reason' => 'Question 3 was marked against the wrong key.',
        ]);

        self::assertEqualsWithDelta(10.0, (float) $this->cellFor(7)->rawGrade, 0.0001);

        // The same attempts, recomputed. Nothing about them changed.
        $sync->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);

        $after = $this->cellFor(7);
        self::assertEqualsWithDelta(10.0, (float) $after->rawGrade, 0.0001);
        self::assertNotNull($after->overriddenAt, 'the stamp stays until it is cleared');

        // And the log carries no third row reversing the human's decision.
        $changes = $this->kernel->gradeBook->changesFor((int) $after->id);
        self::assertCount(2, $changes);
    }

    /** `clear_override` is the one path that releases it, and it is logged. */
    public function testClearingAnOverrideReleasesItAndIsRecorded(): void
    {
        $sync = $this->quizSync();
        $sync->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);
        $cell = $this->cellFor(7);

        $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'raw_grade' => 10,
            'reason' => 'Marked against the wrong key.',
        ]);
        $released = $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'clear_override' => true,
            'reason' => 'The key was right after all; let the machine own it again.',
        ]);

        self::assertNull($this->cellFor(7)->overriddenAt);
        self::assertIsArray($released['change']);
        self::assertNotSame([], $released['change'], 'releasing an override is a logged decision');

        // And the machine can now write over it again.
        $sync->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);
        self::assertEqualsWithDelta(90.0, (float) $this->cellFor(7)->rawGrade, 0.0001);
    }

    /**
     * The regrade route's own three published keys reach the stored row.
     *
     * `touchesAnything()` listed `excluded`, `hidden` and `clear_override`, so
     * a body naming only one of them passed the "a regrade changes something"
     * refusal, answered 200 with both flags false, changed no stored column and
     * wrote no log row.
     */
    public function testExcludedAndHiddenReachTheStoredCellRatherThanBeingDropped(): void
    {
        $this->quizSync()->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);
        $cell = $this->cellFor(7);
        $before = \count($this->kernel->gradeBook->changesFor((int) $cell->id));

        $answer = $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'excluded' => true,
            'hidden' => true,
            'reason' => 'Withdrawn from the cohort mid-term.',
        ]);

        self::assertTrue($answer['cell']['excluded']);
        self::assertTrue($answer['cell']['hidden']);

        $stored = $this->cellFor(7);
        self::assertTrue($stored->excluded);
        self::assertTrue($stored->hidden);
        self::assertCount($before + 1, $this->kernel->gradeBook->changesFor((int) $stored->id));
    }

    /**
     * A regrade never answers an earlier system row as the caller's own
     * decision, and a repeat that moves nothing answers no change at all.
     *
     * The response used to be the newest row on the cell whatever happened, so
     * a body whose three flags were all silently dropped answered 200 with a
     * "change" whose actor was null and whose reason was `regrade`: last
     * night's recomputation, handed back as the thing the caller had just done.
     */
    public function testARegradeAnswersItsOwnChangeAndNeverAnEarlierSystemRow(): void
    {
        $this->quizSync()->syncAndAverage($this->courseId, self::LEARNER, [7 => 90.0]);
        $cell = $this->cellFor(7);

        $first = $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'excluded' => false,
            'reason' => 'Checked; this mark is mine now.',
        ]);

        self::assertIsArray($first['change']);
        self::assertSame(self::MARKER, $first['change']['actor_id']);
        self::assertSame('Checked; this mark is mine now.', $first['change']['reason']);

        // The same body again. Nothing about the cell moves, so nothing is
        // logged and the caller is told so rather than handed the row above.
        $again = $this->kernel->regradeGradeCell->execute(Principal::user(self::MARKER), (int) $cell->id, [
            'excluded' => false,
            'reason' => 'Checked again.',
        ]);

        self::assertNull($again['change'], 'the earlier row is not this caller\'s decision');
    }

    /**
     * The two totals a learner reads are reproducible by adding up the marks in
     * front of them, and the current one never exceeds the final one.
     */
    public function testTheCurrentTotalNeverExceedsTheFinalOneOnTheSameMarks(): void
    {
        $this->quizSync()->syncAndAverage($this->courseId, self::LEARNER, [7 => 80.0, 8 => 40.0]);

        // A 50-mark essay nobody has marked yet, which is what makes the two
        // totals differ at all.
        $this->kernel->recordGrade->bySystem(
            GradeItem::forSource($this->courseId, GradeItem::SOURCE_ASSIGNMENT, 31, 'Essay', 50.0),
            self::LEARNER,
            null,
            50.0,
            GradeProgress::of(
                GradeProgress::ACTIVITY_SUBMITTED,
                GradeProgress::GRADING_PENDING_MANUAL,
                GradeProgress::SCORE_SUBMITTED,
            ),
            'submitted',
        );

        $totals = $this->kernel->readGradebook->totalsFor($this->courseId, self::LEARNER);

        self::assertNotNull($totals['current']);
        self::assertNotNull($totals['final']);
        self::assertLessThanOrEqual((float) $totals['final'], (float) $totals['current']);
        self::assertEqualsWithDelta(120.0, (float) $totals['current'], 0.0001);
        // Graded work alone is 200 marks; everything outstanding makes 250.
        self::assertEqualsWithDelta(200.0, (float) $totals['grade_max'], 0.0001);
        self::assertEqualsWithDelta(250.0, (float) $totals['final_grade_max'], 0.0001);
    }

    /** The two free strategies agree about the maximum of one set of cells. */
    public function testNaturalAndMeanAgreeAboutTheMaximumOfTheSameCells(): void
    {
        $cells = [GradeCell::of('a', 80.0, 100.0), GradeCell::of('b', null, 100.0)];
        $context = GradeAggregationContext::create('c1');

        $natural = $this->kernel->registries->gradeAggregations
            ->total(NaturalGradeAggregation::KEY, $cells, $context);
        $mean = $this->kernel->registries->gradeAggregations
            ->total(MeanGradeAggregation::KEY, $cells, $context);

        self::assertNotNull($natural);
        self::assertNotNull($mean);
        self::assertEqualsWithDelta($natural->maximum, $mean->maximum, 0.0001);
        self::assertEqualsWithDelta(100.0, $mean->maximum, 0.0001);
        self::assertEqualsWithDelta(80.0, $mean->value, 0.0001);
    }

    // ── Three: suppression is not undone by the arithmetic beside it ──────

    /**
     * A rate computed from a suppressed count goes with it.
     *
     * `isSuppressible()` is true only for a count, so a decimal derived from
     * two counts was published verbatim beside them: a started count of 40 and
     * a conversion rate of 0.075 name the three conversions the policy blanked,
     * exactly, by one multiplication.
     */
    public function testARatioDerivedFromASuppressedCountIsSuppressedWithIt(): void
    {
        $policy = CellSuppressionPolicy::ofMinimum(5);
        $columns = [
            ColumnDefinition::count('started', 'Started'),
            ColumnDefinition::count('converted', 'Converted'),
            ColumnDefinition::count('expired', 'Expired'),
            ColumnDefinition::derived('conversion_rate', 'Conversion rate', ['converted', 'started']),
        ];

        [$row, $hidden] = $policy->applyToRow(
            ['started' => 40, 'converted' => 3, 'expired' => 25, 'conversion_rate' => 0.075],
            $columns,
        );

        self::assertNull($row['converted']);
        self::assertNull($row['conversion_rate'], '0.075 of 40 is the three it hid');
        self::assertSame(3, $hidden, 'the small cell, its complement and the derivation');
    }

    /**
     * The complement is chosen from counts that were themselves hiding
     * something.
     *
     * A zero sorts first, so a row carrying one always paid its complement out
     * of a cell that was public information: the reader lost a real figure and
     * the small count stayed recoverable from what was still on the page.
     */
    public function testTheComplementIsNeverAZeroThatHidesNothing(): void
    {
        $policy = CellSuppressionPolicy::ofMinimum(5);
        $columns = [
            ColumnDefinition::count('applied', 'Applied'),
            ColumnDefinition::count('reserved', 'Reserved'),
            ColumnDefinition::count('released', 'Released'),
            ColumnDefinition::count('issued', 'Issued'),
        ];

        [$row] = $policy->applyToRow(
            ['applied' => 3, 'reserved' => 0, 'released' => 0, 'issued' => 40],
            $columns,
        );

        self::assertNull($row['applied']);
        self::assertSame(0, $row['reserved'], 'a zero is public information and blanking it buys nothing');
        self::assertSame(0, $row['released']);
        self::assertNull($row['issued'], 'the complement is a count that was actually hiding something');
    }

    /**
     * Every registered provider that names a person in a column declares
     * {@see RowPerPerson}.
     *
     * The cross-organisation attachment refusal returns early on the marker, so
     * omitting it is a way past a refusal rather than a missing label, and
     * nothing asserted that a report emitting a person-identifying column
     * carries it.
     */
    public function testEveryReportNamingAPersonDeclaresRowPerPerson(): void
    {
        $context = ReportContext::create([], Principal::user(self::MANAGER), VisibilityScope::site());
        $offenders = [];

        foreach ($this->kernel->registries->reports->keys() as $key) {
            $provider = $this->kernel->registries->reports->get($key);

            if (
                !$provider instanceof \Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface
                || $provider instanceof RowPerPerson
            ) {
                continue;
            }

            foreach ($provider->columns($context) as $column) {
                if (self::namesAPerson($column->key)) {
                    $offenders[] = $key . ' emits ' . $column->key;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    // ── Four: the two read paths run one stage ────────────────────────────

    /**
     * A site-wide scalar report whose facts are counts of people is suppressed
     * on screen.
     *
     * The site read applied suppression to rows alone: it never called the
     * disclosure stage, never probed `AggregatesPeople` and never suppressed
     * the scalar half, so the counts were served in the summary block.
     */
    public function testTheSiteReadSuppressesTheScalarHalfAsWellAsTheRows(): void
    {
        $this->kernel->settings->set(ReportExportPipeline::SUPPRESSION_SETTING, 5);
        $this->kernel->registries->reports->register(new SmallCohortFacts());

        $response = $this->call('GET', '/manage/reports/' . SmallCohortFacts::KEY, $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertNull($response->data['completed'], 'three completions is a cell that names people');
        self::assertGreaterThan(0, $response->data['suppressed_cells']);
    }

    /**
     * The screen and the file suppress identically, proved by driving the
     * route rather than by applying the policy in the test.
     *
     * The wave's own case applied `CellSuppressionPolicy` by hand and never
     * called `GetSiteReport`, so the claim that one stage runs on both paths
     * was asserted about the policy and untested for the site read, which was
     * exactly where the stage was half-run.
     */
    public function testTheSiteRouteAndTheExportSuppressTheSameCells(): void
    {
        $this->kernel->settings->set(ReportExportPipeline::SUPPRESSION_SETTING, 5);
        $this->kernel->registries->reports->register(new SmallCellReport());

        $onScreen = $this->call('GET', '/manage/reports/' . SmallCellReport::KEY, $this->manager());

        self::assertSame(200, $onScreen->status);
        self::assertIsArray($onScreen->data);

        $run = $this->kernel->startReportExport->execute(
            $this->manager(),
            SmallCellReport::KEY,
            'json',
        );

        self::assertNotNull($run->storagePath);
        $decoded = json_decode($this->env->storage->readPrivate($run->storagePath), true);

        self::assertIsArray($decoded);
        self::assertSame($onScreen->data['items'], $decoded['items'], 'the route and the file agree');
        self::assertNull($decoded['items'][0]['learners']);
        self::assertNull($decoded['items'][0]['completions']);
    }

    /**
     * A report whose schema is closed is reachable through its own route.
     *
     * The handler writes `page` and `per_page` into every bag, and the
     * validator refused them for a definition that declared
     * `additionalProperties: false`, so a report publishing a strict schema
     * answered 422 for a request nobody had made.
     */
    public function testAClosedParameterSchemaDoesNotRefuseTheFrameworksOwnPagingKeys(): void
    {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['course_id' => ['type' => 'integer']],
        ];

        $typed = ReportParameters::validate($schema, ['course_id' => '7', 'page' => 2, 'per_page' => 10]);

        self::assertSame(7, $typed['course_id']);
        self::assertSame(2, $typed['page']);

        // And a filter the definition really does not declare is still refused.
        $this->expectException(ValidationException::class);
        ReportParameters::validate($schema, ['cohort_id' => 3]);
    }

    // ── Five: a route that answers about a tenant bounds what it answers ──

    /** The organisation read caps `per_page` exactly as the site read does. */
    public function testTheOrganisationReadCapsThePageSizeTheWayTheSiteReadDoes(): void
    {
        $response = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/reports/' . OrgRosterReport::KEY,
            $this->manager(),
            ['per_page' => '1000000'],
        );

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(100, $response->data['per_page']);
    }

    /**
     * The organisation catalogue is a route, so a picker can discover the
     * reports that only that route serves.
     */
    public function testTheOrganisationReportCatalogueIsServedByARoute(): void
    {
        $response = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/reports',
            $this->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);

        $keys = array_column($response->data['reports'], 'key');

        self::assertContains(OrgRosterReport::KEY, $keys);

        // And the site catalogue still does not list it, which is why the
        // second route had to exist.
        $site = $this->call('GET', '/manage/reports', $this->manager());

        self::assertIsArray($site->data);
        self::assertNotContains(OrgRosterReport::KEY, array_column($site->data['reports'], 'key'));
    }

    /** Somebody outside the organisation cannot read its catalogue either. */
    public function testTheOrganisationCatalogueRefusesSomebodyOutsideTheOrganisation(): void
    {
        $response = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/reports',
            $this->narrowed(),
        );

        self::assertNotSame(200, $response->status);
    }

    /**
     * The timeline authorises the subject it actually reads.
     *
     * It checked the capability against `$userId` and then read whichever id
     * the scope carried, so a caller could ask for their own timeline and be
     * handed somebody else's with no capability at all.
     */
    public function testTheTimelineAuthorisesTheSubjectItReadsAndNotTheOneItWasAsked(): void
    {
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);
        $this->kernel->getLearnerTimeline->execute(
            Principal::user(self::LEARNER),
            self::LEARNER,
            VisibilityScope::forUser(self::MARKER),
        );
    }

    /**
     * The catalogue says whether the host can actually write each format.
     *
     * A format registers whether or not the runtime behind it was built, so the
     * picker offered one every run of which would fail at 03:00.
     */
    public function testTheCatalogueSaysWhetherEachFormatCanBeWritten(): void
    {
        $response = $this->call('GET', '/manage/reports', $this->manager());

        self::assertIsArray($response->data);

        foreach ($response->data['formats'] as $format) {
            self::assertArrayHasKey('available', $format);
            self::assertTrue($format['available'], 'csv and json have no runtime to be missing');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function namesAPerson(string $key): bool
    {
        foreach (['user_id', 'email', 'learner_name', 'buyer_name', 'person_name', 'full_name'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $query */
    private function call(string $method, string $path, Principal $principal, array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query));
    }

    private function manager(): Principal
    {
        return Principal::user(self::MANAGER);
    }

    private function narrowed(): Principal
    {
        return Principal::user(self::NARROWED);
    }

    private function task(): ScheduleReportsTask
    {
        $task = $this->kernel->registries->scheduledTasks->get(ScheduleReportsTask::KEY);

        self::assertInstanceOf(ScheduleReportsTask::class, $task);

        return $task;
    }

    private function quizSync(): QuizGradeSync
    {
        return new QuizGradeSync(
            $this->kernel->recordGrade,
            $this->kernel->gradeBook,
            $this->kernel->registries->gradeAggregations,
        );
    }

    private function cellFor(int $sourceId): \Shondalai\VidyaLMS\Core\Domain\Grades\StoredGradeCell
    {
        foreach ($this->kernel->gradeBook->itemsForCourse($this->courseId) as $item) {
            if ($item->sourceId !== $sourceId) {
                continue;
            }

            $cell = $this->kernel->gradeBook->findCell((int) $item->id, self::LEARNER);

            if ($cell !== null) {
                return $cell;
            }
        }

        self::fail('No stored cell for source ' . $sourceId);
    }

    private function schedule(string $reportKey): ReportSchedule
    {
        return ReportSchedule::create(
            'Nightly',
            $reportKey,
            TwoNamedRecipients::KEY,
            $this->env->clock->now()->modify('-1 day'),
            self::MANAGER,
            'csv',
            'notification-link',
            [],
            [],
            ReportSchedule::RECURRENCE_DAILY,
            1,
            0,
            'UTC',
            ReportSchedule::RENDER_AS_RECIPIENT,
            ReportSchedule::EMPTY_SEND,
            null,
            null,
            null,
            ReportSchedule::STATE_ENABLED,
        );
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
        $this->kernel->settings->set(ReportExportPipeline::RETENTION_SETTING, 30);
        $this->env->database->insert('course_instructors', [
            'course_id' => $this->courseId,
            'user_id' => self::MARKER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->organisationId = $this->kernel->organisationStore->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'created_by' => self::MANAGER,
        ]);

        $scope = $this->kernel->organisationAccess->require(
            Principal::user(self::MANAGER),
            $this->organisationId,
            'people',
        );
        $this->kernel->organisationStore->addMember($scope, self::LEARNER, 'org_learner', 'manual', null);
    }
}
