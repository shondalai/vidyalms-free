<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Provider\ReportDelivery\NotificationLinkDelivery;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The four seams this wave seats, driven through their free providers.
 *
 * Every one of them is reached here through the same public method a package
 * outside the core would use, so the free providers are proof the seam works
 * rather than a privileged path beside it.
 */
final class ReportingSeamsTest extends TestCase
{
    private const MANAGER = 1;
    private const LEARNER = 50;

    private TestPorts $env;
    private CoreKernel $kernel;
    private int $courseId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        // A learner name carrying every trigger character, which is the
        // fixture the CSV rule exists for.
        $this->env->users->add(self::LEARNER, '=cmd|calc', 'lena@example.test');

        $this->kernel = new CoreKernel($this->env->ports);
        $this->seed();
    }

    // ── ExportFormat ─────────────────────────────────────────────────────

    public function testTheCsvWriterNeutralisesAFormulaInALearnerName(): void
    {
        $csv = $this->export('csv');

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Excel cannot detect UTF-8 in a .csv without it');
        self::assertStringContainsString('"' . CellSafetyPolicy::PREFIX . '=cmd|calc"', $csv);
        self::assertStringNotContainsString('"=cmd|calc"', $csv, 'quoting alone does not stop a formula');
        self::assertStringContainsString("\r\n", $csv, 'RFC 4180 terminators');
    }

    public function testTheJsonWriterLeavesTheValueExactlyAsStored(): void
    {
        $json = $this->export('json');
        $decoded = json_decode($json, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('meta', $decoded);
        self::assertArrayHasKey('items', $decoded);
        self::assertIsArray($decoded['items']);
        self::assertCount(1, $decoded['items']);
        self::assertSame('=cmd|calc', $decoded['items'][0]['learner'], 'a pipeline asked for the bytes it stored');
        self::assertSame('machine', $decoded['meta']['value_profile']);
    }

    public function testTheDeclaredColumnsTravelWithTheJsonExport(): void
    {
        $decoded = json_decode($this->export('json'), true);

        self::assertIsArray($decoded);
        self::assertSame(
            ['enrolment_id', 'user_id', 'learner', 'status', 'enrolled_at', 'completed_at', 'progress_percent'],
            array_column($decoded['meta']['columns'], 'key'),
        );
    }

    /** Content negotiation, not an error: a format nobody registered is a format nobody asked for. */
    public function testTheRegistryOffersItsFormatsInAStableOrder(): void
    {
        self::assertSame(
            ['csv', 'json'],
            array_map(
                static fn (object $format): string => $format->key(),
                $this->kernel->registries->exportFormats->offered(),
            ),
        );
    }

    // ── ReportDelivery ───────────────────────────────────────────────────

    /**
     * The design wrote this provider's free behaviour as a documented degraded
     * mode because nothing in the core referenced notification_queue when it
     * was written. The notification engine has since landed, so it runs at
     * full function: a real queue row, addressed to the requester.
     */
    public function testTheNotificationLinkDeliveryEnqueuesARealQueueRow(): void
    {
        $before = $this->env->database->count(Query::from('notification_queue'));

        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            NotificationLinkDelivery::KEY,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        self::assertSame(1, $this->env->database->count(Query::from('notification_queue')) - $before);

        $row = $this->env->database->selectOne(
            Query::from('notification_queue')->orderBy('id', Query::SORT_DESC),
        );

        self::assertNotNull($row);
        self::assertSame(NotificationLinkDelivery::TEMPLATE_KEY, $row['template_key']);
        self::assertSame(self::MANAGER, (int) $row['recipient_user_id']);
        self::assertSame('report_run', $row['subject_type']);
        self::assertSame($run->id, (int) $row['subject_id']);

        $parameters = json_decode((string) $row['parameters'], true);

        self::assertIsArray($parameters);
        self::assertArrayHasKey('report.url', $parameters);
        self::assertIsString($parameters['report.url']);
        self::assertNotSame('', $parameters['report.url'], 'a link, never an attachment');
        self::assertSame($run->expiresAt->format(DATE_ATOM), $parameters['report.url_expires_at']);
        self::assertStringEndsWith('?expires=' . ($run->expiresAt->getTimestamp() - $this->env->clock->now()->getTimestamp()), $parameters['report.url']);
        self::assertNotEmpty(array_filter($this->env->scheduler->tasks,
            static fn ($task): bool => ($task->payload['queueId'] ?? null) === (int) $row['id']));
    }

    /** A redelivery of one run to one recipient is one message. */
    public function testASecondDeliveryOfTheSameRunEnqueuesNothingNew(): void
    {
        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            NotificationLinkDelivery::KEY,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        $after = $this->env->database->count(Query::from('notification_queue'));

        $this->kernel->registries->reportDeliveries->deliverThrough(
            NotificationLinkDelivery::KEY,
            $run,
            \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext::create(
                $run,
                [self::MANAGER],
                'https://example.test/signed',
                $this->env->clock->now(),
            ),
        );

        self::assertSame($after, $this->env->database->count(Query::from('notification_queue')));
    }

    /**
     * The miss direction: the run completes, the artefact waits, and the
     * missing provider is on the record rather than thrown out of a finished
     * run.
     */
    public function testAMissingDeliveryProviderNeverDiscardsAFinishedRun(): void
    {
        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            null,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        $result = $this->kernel->registries->reportDeliveries->deliverThrough(
            'sftp-nobody-registered',
            $run,
            \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext::create($run),
        );

        self::assertFalse($result->delivered);
        self::assertSame('delivery_provider_missing:sftp-nobody-registered', $result->reason);
        self::assertNotNull($run->storagePath, 'the expensive half survived the delivery failure');
    }

    /**
     * The third clause of the same miss direction, which had no implementation.
     *
     * "The schedule records a delivery failure the admin can see" is published
     * in two documents. The pipeline called deliverThrough() as a statement and
     * discarded its DeliveryResult, so an export through a delivery nobody
     * registered ended state "done" with error NULL, no log line and no column
     * anywhere saying the delivery had failed. Asserting only that the run
     * survives is asserting half the sentence.
     */
    public function testAFailedDeliveryIsRecordedOnTheRunTheAdminPolls(): void
    {
        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            'sftp-nobody-registered',
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        // The run itself is untouched: rows written, artefact stored, state
        // done. A mail problem does not discard the expensive half.
        self::assertSame(ReportRun::STATE_DONE, $run->state);
        self::assertNotNull($run->storagePath);
        self::assertNull($run->error, 'a delivery failure is not a run failure');

        // And the failure is on the record, where the poll and the operations
        // screen both read it.
        self::assertSame(ReportRun::DELIVERY_FAILED, $run->deliveryState);
        self::assertSame('delivery_provider_missing:sftp-nobody-registered', $run->deliveryError);

        // A successful delivery says so too, so "failed" is distinguishable
        // from "never got there".
        $delivered = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            null,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        self::assertSame(ReportRun::DELIVERY_WAITING, $delivered->deliveryState);
        self::assertNull($delivered->deliveryError);
    }

    /**
     * Which of the two recipes wrote the digest is stored beside it, so a shape
     * bump is a filterable migration rather than a silent orphaning of every
     * artefact manifest.
     */
    public function testTheDigestRecipeIsRecordedBesideTheDigest(): void
    {
        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            'csv',
            null,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        self::assertSame(ReportScopeDigest::SHAPE, $run->scopeDigestShape);
        self::assertSame(ReportScopeDigest::SHAPE, ReportScopeDigest::shapeOf(null));
        self::assertSame(ReportScopeDigest::SHAPE, ReportScopeDigest::shapeOf(VisibilityScope::site()));

        // The two markers are distinguishable, which is the whole point: one
        // column is written by two recipes, each carrying its own private
        // version inside the hash, and R1 records that adding the actor id
        // moves the organisation one to v2.
        self::assertNotSame(ReportScopeDigest::SHAPE, ReportScopeDigest::SHAPE_ORGANISATION);
    }

    // ── GradeAggregation ─────────────────────────────────────────────────

    public function testNaturalIsThePointsSumOverTheCountedMaxima(): void
    {
        $total = $this->kernel->registries->gradeAggregations->total('natural', [
            GradeCell::of('essay', 15.0, 20.0),
            GradeCell::of('quiz', 4.0, 5.0),
        ], GradeAggregationContext::create('assignments'));

        self::assertNotNull($total);
        self::assertSame(19.0, $total->value);
        self::assertSame(25.0, $total->maximum);
        self::assertSame(['essay' => 'used', 'quiz' => 'used'], $total->cellStatus);
    }

    public function testMeanNormalisesEachCellToItsOwnMaximumFirst(): void
    {
        $total = $this->kernel->registries->gradeAggregations->total('mean', [
            GradeCell::of('essay', 15.0, 20.0),
            GradeCell::of('quiz', 4.0, 5.0),
        ], GradeAggregationContext::create('assignments'));

        self::assertNotNull($total);
        // (0.75 + 0.8) / 2 of a 25 point range.
        self::assertEqualsWithDelta(19.375, $total->value, 0.0001);
    }

    /**
     * An empty grade is genuinely ambiguous, so the reading is per category
     * and never global: a gradebook that hard-codes one is wrong for half its
     * users.
     */
    public function testAnEmptyGradeIsReadPerCategoryAndNotGlobally(): void
    {
        $cells = [GradeCell::of('essay', 10.0, 20.0), GradeCell::of('quiz', null, 10.0)];

        $excluded = $this->kernel->registries->gradeAggregations
            ->total('natural', $cells, GradeAggregationContext::create('c', emptyAsZero: false));
        $asZero = $this->kernel->registries->gradeAggregations
            ->total('natural', $cells, GradeAggregationContext::create('c', emptyAsZero: true));

        self::assertNotNull($excluded);
        self::assertNotNull($asZero);
        self::assertSame(20.0, $excluded->maximum, 'the ungraded item leaves both halves of the fraction');
        self::assertSame(30.0, $asZero->maximum);
        self::assertSame('novalue', $excluded->cellStatus['quiz']);
    }

    /** Closed and visible: no total, every cell unknown, and the screen names the missing provider. */
    public function testAnUnresolvedAggregationAnswersNoTotalRatherThanAGuessedOne(): void
    {
        $cells = [GradeCell::of('essay', 10.0, 20.0)];

        // A key no build registers. `weighted-mean` was the stand-in while it
        // was a strategy the design named and nobody had written; the free
        // build ships it now, so the miss direction has to be asserted against
        // a key that is genuinely nobody's.
        self::assertNull($this->kernel->registries->gradeAggregations->total(
            'x-acme-uninstalled-strategy',
            $cells,
            GradeAggregationContext::create('c'),
        ));
        self::assertSame(
            ['essay' => 'unknown'],
            \Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationRegistry::unknownStatuses($cells),
        );
    }

    public function testACategoryWithNothingCountedSaysSoRatherThanReportingZero(): void
    {
        $total = $this->kernel->registries->gradeAggregations->total(
            'natural',
            [GradeCell::of('essay', null, 20.0)],
            GradeAggregationContext::create('c'),
        );

        self::assertNotNull($total);
        self::assertTrue($total->empty);
        self::assertNull($total->percentage(), 'no marks yet is not zero per cent');
    }

    // ── StatementForwarder ───────────────────────────────────────────────

    public function testTheFreeForwarderIsRegisteredAndTakesEveryEventType(): void
    {
        $forwarder = $this->kernel->registries->statementForwarders->get('log');

        self::assertSame([], $forwarder->eventNames(), 'an empty list means every type');
    }

    /**
     * The seam has a free code path and not only a free provider. A registry
     * whose only free provider nothing calls is the failure the rule exists to
     * prevent, one step further in.
     */
    public function testABuiltStatementReachesEveryRegisteredForwarder(): void
    {
        $seen = new RecordingStatementForwarder();
        $this->kernel->registries->statementForwarders->register($seen);

        $this->kernel->events->dispatch(new \Shondalai\VidyaLMS\Core\Domain\Events\XapiStatementBuilt(
            $this->env->clock->now(),
            11,
            22,
            $this->courseId,
            self::LEARNER,
            new \Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement(
                'urn:uuid:forwarded',
                'completed',
                null,
                $this->env->clock->now(),
                ['id' => 'urn:uuid:forwarded'],
            ),
        ));

        self::assertSame(['urn:uuid:forwarded'], $seen->ids);
        self::assertSame($this->courseId, $seen->courseIds[0]);
    }

    /** A forwarder that throws never takes the caller down with it. */
    public function testAFailingForwarderIsReportedRatherThanThrown(): void
    {
        $registry = $this->kernel->registries->statementForwarders;
        $registry->register(new FailingStatementForwarder());

        $failed = $registry->fanOut(
            new \Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement(
                'urn:uuid:1',
                'answered',
                null,
                $this->env->clock->now(),
                ['id' => 'urn:uuid:1'],
            ),
            \Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext::create(
                'vidyalms.xapi.statement_built',
                1,
                2,
                $this->courseId,
                self::LEARNER,
                $this->env->clock->now(),
            ),
        );

        self::assertSame(['unreachable-lrs'], $failed);
    }

    /**
     * A forwarder that wants one kind of fact gets that kind of fact.
     *
     * The regression: exactly one event is published into this fan-out,
     * `vidyalms.xapi.statement_built`, so a filter on the event name alone had
     * one value it could ever be given. A forwarder wanting completions
     * received everything or nothing, and the interface's own promise, that a
     * warehouse can take completions while an LRS takes everything, could not
     * be kept.
     */
    public function testAForwarderFiltersOnTheStatementVerbAndNotOnlyOnTheEventName(): void
    {
        $registry = $this->kernel->registries->statementForwarders;
        $completions = new VerbFilteredForwarder('http://adlnet.gov/expapi/verbs/completed');
        $answers = new VerbFilteredForwarder('http://adlnet.gov/expapi/verbs/answered', 'answers-only');
        $registry->register($completions);
        $registry->register($answers);

        $registry->fanOut(
            new \Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement(
                'urn:uuid:2',
                'http://adlnet.gov/expapi/verbs/completed',
                null,
                $this->env->clock->now(),
                ['id' => 'urn:uuid:2'],
            ),
            \Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext::create(
                'vidyalms.xapi.statement_built',
                1,
                2,
                $this->courseId,
                self::LEARNER,
                $this->env->clock->now(),
            ),
        );

        self::assertSame(['urn:uuid:2'], $completions->seen, 'the completion never reached the warehouse');
        self::assertSame([], $answers->seen, 'a forwarder took traffic it did not ask for');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function export(string $format): string
    {
        $run = $this->kernel->reportExports->execute(
            $this->roster(),
            $this->context(),
            $format,
            null,
            ReportScopeDigest::of(VisibilityScope::site()),
        );

        self::assertNotNull($run->storagePath);

        return $this->env->storage->readPrivate($run->storagePath);
    }

    private function roster(): EnrolmentRosterReport
    {
        $report = $this->kernel->registries->reports->get(EnrolmentRosterReport::KEY);

        self::assertInstanceOf(EnrolmentRosterReport::class, $report);

        return $report;
    }

    private function context(): ReportContext
    {
        return ReportContext::create(
            ['course_id' => $this->courseId],
            Principal::user(self::MANAGER),
            VisibilityScope::forCourse($this->courseId),
        );
    }

    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->courseId = $this->env->database->insert('courses', [
            'title' => 'Seams course',
            'slug' => 'seams-course',
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
        // The pipeline reads its settings, so the defaults are what they are
        // rather than what a previous test left behind.
        $this->kernel->settings->set(ReportExportPipeline::RETENTION_SETTING, 30);
    }
}
