<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Grades\CourseGradeTotals;
use Shondalai\VidyaLMS\Core\Application\Grades\QuizGradeSync;
use Shondalai\VidyaLMS\Core\Application\Grades\RecordGrade;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\NaturalGradeAggregation;
use Shondalai\VidyaLMS\Core\Provider\Report\AtRiskLearnersReport;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The reporting half of commerce Wave C, driven through the seams a package
 * outside the core would use.
 *
 * Four claims, one per section: every free aggregation folds its fixtures the
 * way its name says; every regrade lands in the append-only log with its reason
 * and its actor; a schedule renders per recipient on its own clock and refuses
 * both crossings the organisations design names; and the suppression stage
 * hides the same cells on screen and in the file.
 */
final class GradebookAndSchedulesTest extends TestCase
{
    private const MANAGER = 1;
    private const MARKER = 2;
    private const LEARNER = 50;
    private const OTHER_LEARNER = 51;

    private TestPorts $env;
    private CoreKernel $kernel;
    private int $courseId = 0;

    /** A published course nobody is enrolled on, which is what an empty run needs. */
    private int $emptyCourseId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::MARKER, Capability::GRADE);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::MARKER, 'Mia Marker', 'mia@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');
        $this->env->users->add(self::OTHER_LEARNER, 'Otto Other', 'otto@example.test');

        $this->kernel = new CoreKernel($this->env->ports);
        $this->seed();
    }

    // -- The aggregation registry is the only arithmetic ------------------

    /**
     * `natural` is a points sum whose maximum is the sum of the item maxima.
     *
     * Three items out of 10, 20 and 30, scored 5, 20 and 15, is 40 out of 60
     * and not the mean of three percentages. That is the whole difference
     * between the two free strategies and it is why both ship.
     */
    public function testNaturalSumsThePointsAndTheMaxima(): void
    {
        $total = $this->kernel->registries->gradeAggregations->total(
            NaturalGradeAggregation::KEY,
            [
                GradeCell::of('a', 5.0, 10.0),
                GradeCell::of('b', 20.0, 20.0),
                GradeCell::of('c', 15.0, 30.0),
            ],
            GradeAggregationContext::create('c1'),
        );

        self::assertNotNull($total);
        self::assertEqualsWithDelta(40.0, $total->value, 0.0001);
        self::assertEqualsWithDelta(60.0, $total->maximum, 0.0001);
        self::assertSame(
            ['a' => GradeCell::STATUS_USED, 'b' => GradeCell::STATUS_USED, 'c' => GradeCell::STATUS_USED],
            $total->cellStatus,
        );
    }

    /** The same three cells under `mean` answer the mean of their fractions, not their sum. */
    public function testMeanAveragesTheFractionsOverTheSameFixture(): void
    {
        $total = $this->kernel->registries->gradeAggregations->total(
            MeanGradeAggregation::KEY,
            [
                GradeCell::of('a', 5.0, 10.0),
                GradeCell::of('b', 20.0, 20.0),
                GradeCell::of('c', 15.0, 30.0),
            ],
            GradeAggregationContext::create('c1'),
        );

        self::assertNotNull($total);
        // (0.5 + 1.0 + 0.5) / 3 = 0.666..., mapped into a range of 60.
        self::assertEqualsWithDelta(40.0, $total->value, 0.0001);
        self::assertEqualsWithDelta(66.6667, (float) $total->percentage(), 0.001);
    }

    /**
     * The per-category reading of an empty grade is a parameter and never a
     * constant, which is the one Moodle decision this design copies exactly.
     */
    public function testAnEmptyGradeIsExcludedOrCountedByTheCategorysOwnSetting(): void
    {
        $cells = [GradeCell::of('a', 10.0, 10.0), GradeCell::of('b', null, 10.0)];

        $excluding = $this->kernel->registries->gradeAggregations->total(
            NaturalGradeAggregation::KEY,
            $cells,
            GradeAggregationContext::create('c1', false),
        );
        $asZero = $this->kernel->registries->gradeAggregations->total(
            NaturalGradeAggregation::KEY,
            $cells,
            GradeAggregationContext::create('c1', true),
        );

        self::assertNotNull($excluding);
        self::assertNotNull($asZero);
        self::assertSame(GradeCell::STATUS_NOVALUE, $excluding->cellStatus['b']);
        self::assertSame(GradeCell::STATUS_USED, $asZero->cellStatus['b']);
        self::assertEqualsWithDelta(10.0, $excluding->value, 0.0001);
        self::assertEqualsWithDelta(10.0, $asZero->value, 0.0001);
        // The maximum is what moved: excluded, the empty cell is not part of
        // the range; counted as zero, it is.
        self::assertEqualsWithDelta(100.0, (float) $excluding->percentage(), 0.001);
        self::assertEqualsWithDelta(50.0, (float) $asZero->percentage(), 0.001);
    }

    /** An unresolved key answers nothing and marks every cell unknown; it never falls back. */
    public function testAnUnregisteredAggregationAnswersNothingRatherThanGuessing(): void
    {
        $cells = [GradeCell::of('a', 10.0, 10.0)];

        self::assertNull($this->kernel->registries->gradeAggregations->total(
            'weighted-mean-nobody-installed',
            $cells,
            GradeAggregationContext::create('c1'),
        ));
    }

    // -- The change log ---------------------------------------------------

    /**
     * Every regrade lands in the log with its reason, and a person's regrade
     * names the person.
     */
    public function testEveryRegradeIsRecordedWithItsReasonAndItsActor(): void
    {
        $item = GradeItem::forSource($this->courseId, GradeItem::SOURCE_ASSIGNMENT, 7, 'Essay', 20.0);

        $first = $this->kernel->recordGrade->bySystem(
            $item,
            self::LEARNER,
            12.0,
            20.0,
            GradeProgress::humanGraded(),
            GradeChange::REASON_FIRST_GRADE,
        );
        self::assertEqualsWithDelta(12.0, (float) $first->rawGrade, 0.0001);

        $second = $this->kernel->recordGrade->byPerson(
            $item,
            self::LEARNER,
            self::MARKER,
            16.0,
            20.0,
            GradeProgress::humanGraded(),
            'Marked down a criterion in error on the first pass.',
        );
        self::assertEqualsWithDelta(16.0, (float) $second->rawGrade, 0.0001);

        $log = $this->kernel->gradeBook->changesFor((int) $second->id);

        self::assertCount(2, $log);
        self::assertNull($log[0]->actorId, 'a machine regrade names no person, and that is a fact');
        self::assertSame(GradeChange::REASON_FIRST_GRADE, $log[0]->reason);
        self::assertSame(self::MARKER, $log[1]->actorId);
        self::assertSame('Marked down a criterion in error on the first pass.', $log[1]->reason);
        self::assertEqualsWithDelta(12.0, (float) $log[1]->gradeBefore, 0.0001);
        self::assertEqualsWithDelta(16.0, (float) $log[1]->gradeAfter, 0.0001);
        self::assertTrue($log[1]->wasMadeByAPerson());
    }

    /** A person who does not say why does not get to change a grade. */
    public function testAPersonChangingAGradeMustSayWhy(): void
    {
        $item = GradeItem::forSource($this->courseId, GradeItem::SOURCE_ASSIGNMENT, 7, 'Essay', 20.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->kernel->recordGrade->byPerson(
            $item,
            self::LEARNER,
            self::MARKER,
            16.0,
            20.0,
            GradeProgress::humanGraded(),
            '   ',
        );
    }

    /** A recomputation that changes nothing writes no log row. */
    public function testARecomputationThatChangesNothingWritesNoLogRow(): void
    {
        $item = GradeItem::forSource($this->courseId, GradeItem::SOURCE_QUIZ, 3, 'Quiz 3', 100.0);

        $cell = $this->kernel->recordGrade->bySystem(
            $item,
            self::LEARNER,
            80.0,
            100.0,
            GradeProgress::autoGraded(),
            GradeChange::REASON_FIRST_GRADE,
        );
        self::assertEqualsWithDelta(80.0, (float) $cell->rawGrade, 0.0001);

        $this->kernel->recordGrade->bySystem(
            $item,
            self::LEARNER,
            80.0,
            100.0,
            GradeProgress::autoGraded(),
            GradeChange::REASON_REGRADE,
        );

        self::assertCount(1, $this->kernel->gradeBook->changesFor((int) $cell->id));
    }

    /**
     * A score older than the last accepted one for the same user and item is
     * rejected, which is LTI AGS's own out-of-order rule.
     */
    public function testAnOlderScoreDoesNotOverwriteANewerOne(): void
    {
        $item = GradeItem::forSource($this->courseId, GradeItem::SOURCE_QUIZ, 4, 'Quiz 4', 100.0);
        $now = $this->env->clock->now();

        $this->kernel->recordGrade->bySystem(
            $item,
            self::LEARNER,
            90.0,
            100.0,
            GradeProgress::autoGraded(),
            GradeChange::REASON_FIRST_GRADE,
            null,
            [],
            $now,
        );
        $replayed = $this->kernel->recordGrade->bySystem(
            $item,
            self::LEARNER,
            10.0,
            100.0,
            GradeProgress::autoGraded(),
            GradeChange::REASON_REGRADE,
            null,
            [],
            $now->modify('-1 hour'),
        );

        self::assertEqualsWithDelta(90.0, (float) $replayed->rawGrade, 0.0001);
    }

    /**
     * The cached course average is folded out of stored grade cells by the
     * registry, and the cells carry the snapshot the item's own maximum cannot
     * be trusted to keep.
     */
    public function testTheCachedAverageIsFoldedOutOfStoredCells(): void
    {
        $sync = new QuizGradeSync(
            $this->kernel->recordGrade,
            $this->kernel->gradeBook,
            $this->kernel->registries->gradeAggregations,
        );

        $average = $sync->syncAndAverage($this->courseId, self::LEARNER, [11 => 80.0, 12 => 40.0]);

        self::assertNotNull($average);
        self::assertEqualsWithDelta(60.0, $average, 0.0001);

        $cells = $this->kernel->gradeBook->cellsFor($this->courseId, self::LEARNER);
        self::assertCount(2, $cells);

        foreach ($cells as $cell) {
            self::assertEqualsWithDelta(100.0, $cell->rawGradeMax, 0.0001);
            self::assertSame(GradeProgress::GRADING_FULLY_GRADED, $cell->progress->grading);
        }

        $totals = new CourseGradeTotals($this->kernel->gradeBook, $this->kernel->registries->gradeAggregations);
        $total = $totals->courseTotal($this->courseId, self::LEARNER, false, GradeItem::SOURCE_QUIZ);

        self::assertNotNull($total);
        self::assertEqualsWithDelta(120.0, $total->value, 0.0001);
    }

    // -- The schedule ------------------------------------------------------

    /** The default is the recipient, in the factory as well as in the column. */
    public function testAScheduleRendersPerRecipientUnlessItsAuthorSaysOtherwise(): void
    {
        $schedule = ReportSchedule::create(
            'Weekly roster',
            'enrolment-roster',
            'site_managers',
            $this->env->clock->now(),
            self::MANAGER,
        );

        self::assertSame(ReportSchedule::RENDER_AS_RECIPIENT, $schedule->renderAs);
        self::assertTrue($schedule->rendersPerRecipient());
    }

    /**
     * The stored definition is wrapped in the same StoredShape envelope the
     * cohort and notification schedules use, with the version mirrored into its
     * own column.
     */
    public function testTheStoredDefinitionCarriesTheEnvelopeAndTheMirroredVersion(): void
    {
        $saved = $this->kernel->reportSchedules->save(ReportSchedule::create(
            'Weekly roster',
            'enrolment-roster',
            'site_managers',
            $this->env->clock->now(),
            self::MANAGER,
            'csv',
            'notification-link',
            ['course_id' => $this->courseId],
        ));

        self::assertNotNull($saved->id);
        self::assertSame(['course_id' => $this->courseId], $saved->definition, 'the body reaches the domain alone');

        $row = $this->env->database->selectOne(
            Query::from('report_schedules')->where('id', '=', (int) $saved->id),
        );

        self::assertNotNull($row);
        self::assertSame(ReportSchedule::DEFINITION_SHAPE, (int) $row['definition_version']);

        $stored = json_decode((string) $row['definition'], true);
        self::assertIsArray($stored);
        self::assertSame(ReportSchedule::DEFINITION_SHAPE, StoredShape::versionOf($stored));
        self::assertSame(ReportSchedule::RENDER_AS_RECIPIENT, (string) $row['render_as']);
    }

    /**
     * The next occurrence is computed in the schedule's own timezone and not in
     * the site's, which is the whole reason a cron string was refused.
     */
    public function testTheNextRunIsComputedInTheSchedulesOwnZone(): void
    {
        $schedule = ReportSchedule::create(
            'Nightly',
            'enrolment-roster',
            'site_managers',
            new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC')),
            self::MANAGER,
            'csv',
            'notification-link',
            [],
            [],
            ReportSchedule::RECURRENCE_DAILY,
            1,
            // 09:00 in Asia/Kolkata, which is 03:30 UTC.
            9 * 60,
            'Asia/Kolkata',
        );

        $next = $schedule->nextDueAfter(new \DateTimeImmutable('2026-03-02 00:00:00', new \DateTimeZone('UTC')));

        self::assertSame('2026-03-02 03:30:00', $next->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-02', $schedule->periodBucket($next));
    }

    /** One schedule serves two leaders as two runs, each under its own recipient's scope. */
    public function testAScheduleRendersOneRunPerRecipient(): void
    {
        // Two site managers, which is the case the design's own example is
        // about: one schedule serving two people, each with a run of their own.
        $this->env->authorization->grant(self::MARKER, Capability::VIEW_REPORTS);
        $this->env->authorization->grant(self::MARKER, Capability::MANAGE);
        $this->kernel->registries->audiences->register(new TwoNamedRecipients([self::MANAGER, self::MARKER]));

        $this->kernel->reportSchedules->save(ReportSchedule::create(
            'Weekly roster',
            'enrolment-roster',
            TwoNamedRecipients::KEY,
            $this->env->clock->now()->modify('-1 day'),
            self::MANAGER,
            'csv',
            'notification-link',
            ['course_id' => $this->courseId],
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
        ));

        $summary = $this->task()->run(new ScheduledTaskRun(1, [], Principal::user(self::MANAGER)));

        self::assertStringContainsString('served=1', $summary);
        self::assertStringContainsString('runs=2', $summary);

        $runs = $this->env->database->selectAll(Query::from('report_runs'));
        self::assertCount(2, $runs);

        foreach ($runs as $run) {
            self::assertSame('enrolment-roster', (string) $run['report_key']);
            self::assertNotNull($run['schedule_id']);
        }

        // Two recipients, two runs, each recorded against the person it was
        // rendered for. That column is the difference between one schedule
        // serving twenty leaders and one file mailed to twenty people.
        self::assertSame(
            [self::MANAGER, self::MARKER],
            array_map('intval', array_column($runs, 'rendered_as')),
        );
    }

    /** A run that found nothing and was told to stay quiet delivers nothing. */
    public function testTheEmptyReportPolicyDecidesWhetherAnEmptyRunIsDelivered(): void
    {
        $report = $this->atRisk();
        $context = ReportContext::create(
            ['course_id' => $this->emptyCourseId],
            Principal::user(self::MANAGER),
            VisibilityScope::forCourse($this->emptyCourseId),
        );

        $skipped = $this->kernel->reportExports->execute(
            $report,
            $context,
            'csv',
            'notification-link',
            ReportScopeDigest::of(VisibilityScope::forCourse($this->emptyCourseId)),
            null,
            [self::MANAGER],
            null,
            DeliveryContext::RENDER_AS_RECIPIENT,
            null,
            ReportSchedule::EMPTY_SKIP,
        );

        self::assertSame(ReportRun::STATE_EMPTY, $skipped->state);
        self::assertSame(0, $skipped->rowCount);
        // Its own state, and not the one an unregistered delivery provider
        // produces. Both used to read "waiting", so an operator could not tell
        // a schedule that is deliberately quiet from one whose delivery key
        // nobody registers, which is the third of the manifest's three stated
        // reasons for existing.
        self::assertSame(ReportRun::DELIVERY_WITHHELD, $skipped->deliveryState);
        self::assertNull($skipped->storagePath, 'a skipped empty run writes no artefact');

        // The same zero-row run with no delivery asked for at all, which is
        // the state the withheld one used to be indistinguishable from.
        $undelivered = $this->kernel->reportExports->execute(
            $report,
            $context,
            'csv',
            null,
            ReportScopeDigest::of(VisibilityScope::forCourse($this->emptyCourseId)),
            null,
            [self::MANAGER],
            null,
            DeliveryContext::RENDER_AS_RECIPIENT,
            null,
            ReportSchedule::EMPTY_SEND_WITHOUT_FILE,
        );

        self::assertSame(ReportRun::DELIVERY_WAITING, $undelivered->deliveryState);
        self::assertNotSame($skipped->deliveryState, $undelivered->deliveryState);
    }

    /** With "send", the empty report is a real file with a real header. */
    public function testSendingTheEmptyReportWritesAHeaderOnlyFile(): void
    {
        $sent = $this->kernel->reportExports->execute(
            $this->atRisk(),
            ReportContext::create(
                ['course_id' => $this->emptyCourseId],
                Principal::user(self::MANAGER),
                VisibilityScope::forCourse($this->emptyCourseId),
            ),
            'csv',
            'notification-link',
            ReportScopeDigest::of(VisibilityScope::forCourse($this->emptyCourseId)),
            null,
            [self::MANAGER],
            null,
            DeliveryContext::RENDER_AS_RECIPIENT,
            null,
            ReportSchedule::EMPTY_SEND,
        );

        self::assertSame(ReportRun::STATE_EMPTY, $sent->state);
        self::assertNotNull($sent->storagePath);
        self::assertStringContainsString('reason', $this->env->storage->readPrivate($sent->storagePath));
        self::assertSame(ReportRun::DELIVERY_DELIVERED, $sent->deliveryState);
    }

    /**
     * The first R5 refusal: an author render may not cross an organisation
     * boundary.
     */
    public function testAnAuthorRenderIsRefusedAcrossAnOrganisationBoundary(): void
    {
        try {
            $this->kernel->reportExports->execute(
                $this->atRisk(),
                ReportContext::create(
                    ['course_id' => $this->courseId],
                    Principal::user(self::MANAGER),
                    VisibilityScope::forCourse($this->courseId),
                ),
                'csv',
                'notification-link',
                ReportScopeDigest::of(VisibilityScope::forCourse($this->courseId)),
                7,
                [self::MANAGER, self::MARKER],
                null,
                DeliveryContext::RENDER_AS_AUTHOR,
            );

            self::fail('An author render across two organisations must be refused.');
        } catch (ValidationException $refused) {
            self::assertArrayHasKey('render_as', $refused->errors);
            self::assertStringContainsString('one organisation only', (string) $refused->errors['render_as']);
        }

        // The refusal fires before the run row opens, so no artefact of
        // personal data exists that nobody was allowed to receive.
        self::assertSame(0, $this->env->database->count(Query::from('report_runs')));
    }

    /**
     * The second R5 refusal: a row-per-person report may not be attached across
     * an organisation boundary. The at-risk report is one row per person and
     * declares it.
     */
    public function testARowPerPersonReportIsNotAttachedAcrossAnOrganisationBoundary(): void
    {
        $this->kernel->registries->reportDeliveries->register(new AttachingDelivery());

        try {
            $this->kernel->reportExports->execute(
                $this->atRisk(),
                ReportContext::create(
                    ['course_id' => $this->courseId],
                    Principal::user(self::MANAGER),
                    VisibilityScope::forCourse($this->courseId),
                ),
                'csv',
                AttachingDelivery::KEY,
                ReportScopeDigest::of(VisibilityScope::forCourse($this->courseId)),
                7,
                [self::MANAGER, self::MARKER],
                null,
                DeliveryContext::RENDER_AS_RECIPIENT,
            );

            self::fail('A row-per-person report may not be attached across an organisation boundary.');
        } catch (ValidationException $refused) {
            self::assertArrayHasKey('delivery', $refused->errors);
            self::assertStringContainsString('email attachment', (string) $refused->errors['delivery']);
        }

        self::assertSame(0, $this->env->database->count(Query::from('report_runs')));
    }

    // -- The suppression and disclosure stage ------------------------------

    /**
     * The stage hides the same cells on screen and in the file, because an
     * aggregate safe on screen and unsafe in a CSV is the same disclosure.
     */
    public function testSuppressionHidesTheSameCellsOnScreenAndInTheExport(): void
    {
        $this->kernel->settings->set(ReportExportPipeline::SUPPRESSION_SETTING, 5);

        $report = new SmallCellReport();
        $context = ReportContext::create([], Principal::user(self::MANAGER), VisibilityScope::site());
        $policy = $this->kernel->reportExports->suppressionFor($report, $context);

        $onScreen = [];

        foreach ($report->rows($context) as $row) {
            [$suppressed] = $policy->applyToRow($row, $report->columns($context));
            $onScreen[] = $suppressed;
        }

        $run = $this->kernel->reportExports->execute(
            $report,
            $context,
            'json',
            null,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        self::assertNotNull($run->storagePath);
        $decoded = json_decode($this->env->storage->readPrivate($run->storagePath), true);

        self::assertIsArray($decoded);
        self::assertSame($onScreen, $decoded['items'], 'the screen and the file suppress identically');
        // The small cell went, and so did the next smallest, or the number
        // would be recoverable by subtraction.
        self::assertNotSame(2, $decoded['items'][0]['learners']);
        self::assertNotSame(6, $decoded['items'][0]['completions']);
    }

    /**
     * The structural claim: no registered report provider applies suppression
     * or disclosure itself.
     *
     * Suppression implemented per report is suppression forgotten in the
     * eleventh one, so it is one stage of the pipeline and this is what holds
     * it there. A provider that genuinely has to answer for its own cells opts
     * out through the published SupportsSuppression capability, which is a
     * declaration a reader can find rather than a policy buried in a query.
     */
    public function testNoReportProviderAppliesSuppressionOrDisclosureItself(): void
    {
        $offenders = [];

        foreach ($this->kernel->registries->reports->keys() as $key) {
            $provider = $this->kernel->registries->reports->get($key);
            $file = (new \ReflectionClass($provider))->getFileName();

            if ($file === false) {
                continue;
            }

            $source = (string) file_get_contents($file);

            foreach (['CellSuppressionPolicy', 'FieldDisclosureRegistry', 'DisclosurePipeline'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $key . ' names ' . $forbidden;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    // -- Helpers -----------------------------------------------------------

    private function task(): ScheduleReportsTask
    {
        $task = $this->kernel->registries->scheduledTasks->get(ScheduleReportsTask::KEY);

        self::assertInstanceOf(ScheduleReportsTask::class, $task);

        return $task;
    }

    private function atRisk(): AtRiskLearnersReport
    {
        $report = $this->kernel->registries->reports->get(AtRiskLearnersReport::KEY);

        self::assertInstanceOf(AtRiskLearnersReport::class, $report);

        return $report;
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
        $this->emptyCourseId = $this->env->database->insert('courses', [
            'title' => 'Nobody is on this one',
            'slug' => 'empty-course',
            'state' => 'published',
            'created_by' => self::MANAGER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->env->database->insert('enrolments', [
            'user_id' => self::LEARNER,
            'course_id' => $this->courseId,
            'entitlement_id' => 0,
            'status' => 'active',
            'enrolled_at' => $now,
            'progress_percent' => 25,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->kernel->settings->set(ReportExportPipeline::RETENTION_SETTING, 30);
    }
}
