<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComplianceAssignments;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationExistence;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Compliance\AssignmentExceptionKind;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\RetentionPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\DeliveryBoundaryPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\LedgerRedactionStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\FieldDisclosure\OrgMinimalDisclosure;
use Shondalai\VidyaLMS\Core\Provider\Privacy\CoreLearningPrivacy;
use Shondalai\VidyaLMS\Core\Provider\Privacy\CoreOrganisationsPrivacy;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgOverviewReport;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgRosterReport;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AccessLogRetentionTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ComplianceWindowTask;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EnumeratesOrganisationUsers;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesWithOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * R5's governance half: the three free reports, the disclosure and suppression
 * stages, compliance as schema with no rule, per-organisation retention, the two
 * delivery refusals, and the erasure receipt.
 */
final class OrganisationGovernanceTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $orgA;
    private int $orgB;
    private int $memberA;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->orgA = $this->organisation('north');
        $this->orgB = $this->organisation('south');
        $this->memberA = $this->member($this->orgA, 50);
    }

    // ── The three free organisation reports ───────────────────────────────

    public function testTheFreeCatalogueIsThreeOrganisationReportsAndEachHonoursTheScope(): void
    {
        $keys = $this->fixture->kernel->registries->reports->keys();

        foreach ([OrgOverviewReport::KEY, OrgRosterReport::KEY, 'org-access-log'] as $key) {
            self::assertContains($key, $keys, $key . ' is a registered free report');
            $provider = $this->fixture->kernel->registries->reports->get($key);
            self::assertInstanceOf(
                \Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope::class,
                $provider,
                $key . ' declares HonoursOrgScope',
            );
            // Every registered organisation report resolves to a host action
            // both adapters can grant, which is the test matrix's own line.
            self::assertContains(
                $provider->requiredCapability(),
                \Shondalai\VidyaLMS\Core\Application\Shared\Capability::all(),
            );
        }
    }

    public function testTheSummaryAliasAndTheRegisteredOverviewAnswerTheSameNumbers(): void
    {
        $summary = self::data($this->fixture->call(
            'GET',
            '/manage/organisations/' . $this->orgA . '/summary',
            $this->fixture->manager(),
        ));
        $response = $this->report($this->orgA, OrgOverviewReport::KEY);
        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        $report = self::data($response);

        self::assertTrue($report['computed']);
        self::assertSame(OrgOverviewReport::KEY, $report['computed_by']);

        foreach (['organisation_id', 'released_courses', 'seat_pools'] as $key) {
            self::assertSame($summary[$key], $report[$key], $key . ' agrees across the alias and the report');
        }
    }

    public function testTheRosterIsBuiltOnMembershipAndNamesThePeopleWithNoEnrolment(): void
    {
        $report = self::data($this->report($this->orgA, OrgRosterReport::KEY));

        self::assertTrue($report['computed']);
        // Two: the learner added above, and the manager the organisation's own
        // creation seated as its owner. A roster built on enrolment would list
        // neither, because neither is enrolled in anything.
        // Both scalars are counts of people, so both pass through the same
        // small-cell policy the overview obeys: a two-member organisation is
        // below the minimum cell and the head-count is withheld on this report
        // exactly as it is on that one. The rows themselves are the roster and
        // are still listed, which is the recorded decision for a row-per-person
        // report read under the organisation events right.
        self::assertNull($report['total']);
        self::assertNull($report['members_without_enrolment']);
        self::assertSame(2, $report['suppressed_cells']);
        self::assertCount(2, $report['items']);
        self::assertSame(
            [1, 50],
            array_values(array_unique(array_column($report['items'], 'user_id'))),
        );
        self::assertSame(0, $report['items'][0]['enrolment_count']);
        self::assertGreaterThan(0, $this->memberA);
        // No rule is registered, so the compliance line reads assigned rather
        // than compliant. The registry's miss direction, on a screen.
        self::assertSame(ComplianceStatus::ASSIGNED, $report['items'][0]['compliance_summary']);
    }

    // ── Field disclosure, on screen and in the export ─────────────────────

    public function testTheFreeDenyListOmitsTheAddressOnScreenAndInTheExportAlike(): void
    {
        $screen = self::data($this->report($this->orgA, OrgRosterReport::KEY));

        self::assertContains('email', $screen['omitted_fields']);
        self::assertNotContains('email', array_column($screen['columns'], 'key'));
        self::assertArrayNotHasKey('email', $screen['items'][0]);
        // display_name is not on the deny list and survives, so the stage is
        // omitting a field rather than emptying the report.
        self::assertArrayHasKey('display_name', $screen['items'][0]);

        $export = $this->fixture->call(
            'POST',
            '/manage/organisations/' . $this->orgA . '/reports/' . OrgRosterReport::KEY . '/exports',
            $this->fixture->manager(),
            ['format' => 'csv'],
            idempotencyKey: 'roster-export',
        );
        self::assertContains($export->status, [200, 202], json_encode($export->data, JSON_THROW_ON_ERROR));

        $run = $this->fixture->env->database->selectOne(
            Query::from('report_runs')->where('report_key', '=', OrgRosterReport::KEY)->orderBy('id', 'DESC'),
        );
        self::assertIsArray($run);
        $bytes = $this->fixture->env->storage->readPrivate((string) $run['storage_path']);
        self::assertStringNotContainsString('email', $bytes);
        self::assertStringNotContainsString('lena@example.test', $bytes);
        self::assertStringContainsString('display_name', $bytes);
    }

    public function testTheDenyListNamesTheKindAndRecognisesTheColumnSpellings(): void
    {
        // The vocabulary word names a kind of data and a report names a column.
        // A policy that only recognised the word would disclose a column called
        // "ip" while claiming to hide IP addresses.
        foreach (['answer_text', 'submission_body', 'integrity_signal', 'ip_address', 'email'] as $denied) {
            self::assertTrue(OrgMinimalDisclosure::denies($denied), $denied);
        }

        foreach (['ip', 'ip_hash', 'remote_addr', 'a.email', 'contact_email'] as $alias) {
            self::assertTrue(OrgMinimalDisclosure::denies($alias), $alias);
        }

        foreach (['user_id', 'display_name', 'joined_at', 'enrolment_count'] as $allowed) {
            self::assertFalse(OrgMinimalDisclosure::denies($allowed), $allowed);
        }
    }

    public function testTheScalarOverviewGoesThroughSmallCellSuppressionToo(): void
    {
        // One active member is below the shipped minimum of five, so the count
        // of people is suppressed. A five-person organisation's summary is the
        // smallest cell in the product and a summary that escaped the rule every
        // table row obeys would be the disclosure the rule exists to prevent.
        $report = self::data($this->report($this->orgA, OrgOverviewReport::KEY));

        self::assertNull($report['active_members']);
        self::assertGreaterThan(0, $report['suppressed_cells']);
        // A count of released courses is a count of things, not of people, and
        // is untouched.
        self::assertIsInt($report['released_courses']);
    }

    // ── Compliance: schema, no rule, and an exception rather than silence ──

    public function testNoComplianceRuleIsRegisteredAndAMissIsNeverCompliant(): void
    {
        self::assertSame([], $this->fixture->kernel->registries->complianceRules->keys());

        $state = $this->fixture->kernel->registries->complianceRules->evaluateThrough(
            'recurring-from-completion',
            \Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject::of(
                $this->orgA,
                1,
                50,
                $this->fixture->env->clock->now(),
            ),
            \Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext::create($this->fixture->env->clock->now()),
        );

        self::assertFalse($state->isCompliant());
        self::assertSame(ComplianceStatus::ASSIGNED, $state->status);
        self::assertNotSame([], $state->notes);
    }

    public function testAnUnresolvedAnchorRaisesAnExceptionRatherThanAssigningSilently(): void
    {
        $requirement = $this->requirement($this->orgA, ['rule_params' => json_encode(['anchor' => 'no-such-anchor'])]);
        $result = $this->fixture->kernel->complianceAssignments->assign($this->scope($this->orgA), $requirement, 50);

        self::assertSame('exception_raised', $result['state']);
        self::assertSame(AssignmentExceptionKind::ANCHOR_UNRESOLVED, $result['exception']);
        self::assertNull($result['assignment_id']);
        // And never compliant, which is the one wrong answer this seam must not
        // give: an exception is the absence of an answer, not a pass.
        self::assertSame(ComplianceStatus::ASSIGNED, $result['compliance_status']);

        $rows = $this->fixture->env->database->selectAll(
            Query::from('assignment_exceptions')->where('organisation_id', '=', $this->orgA),
        );
        self::assertCount(1, $rows);
        self::assertSame(AssignmentExceptionKind::ANCHOR_UNRESOLVED, (string) $rows[0]['kind']);
    }

    public function testAPersonOutsideTheOrganisationRaisesItsOwnExceptionKind(): void
    {
        $requirement = $this->requirement($this->orgA);
        $result = $this->fixture->kernel->complianceAssignments->assign($this->scope($this->orgA), $requirement, 999);

        self::assertSame(AssignmentExceptionKind::USER_NOT_IN_ORG, $result['exception']);
        self::assertSame(
            0,
            $this->fixture->env->database->count(Query::from('compliance_assignments')),
            'no assignment row is written for somebody who is not a member',
        );
    }

    public function testTheComplianceWindowTaskIsRegisteredAndClaimsNobodyCompliant(): void
    {
        self::assertTrue($this->fixture->kernel->registries->scheduledTasks->has(ComplianceWindowTask::KEY));

        $requirement = $this->requirement($this->orgA);
        $this->fixture->env->database->insert('compliance_assignments', [
            'requirement_id' => $requirement, 'organisation_id' => $this->orgA, 'user_id' => 50, 'cycle' => 1,
            'assigned_at' => '2026-01-01 00:00:00', 'compliance_status' => ComplianceStatus::ASSIGNED,
            'renewal_status' => 'not_due', 'rule_state_version' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $task = $this->fixture->kernel->registries->scheduledTasks->get(ComplianceWindowTask::KEY);
        $line = $task->run(new ScheduledTaskRun(1, [], Principal::user(1)));

        self::assertStringContainsString('checked=1', $line);
        self::assertStringContainsString('untracked=1', $line);
        self::assertStringContainsString('compliant_claimed=0', $line);
    }

    // ── Retention: the column, the route and the one refusal ──────────────

    public function testAnOrganisationMayLengthenTheAccessLogFloorAndNeverShortenIt(): void
    {
        $floor = $this->fixture->kernel->organisationPrivacy->accessLogFloorDays();
        self::assertSame(AccessLogRetentionTask::DEFAULT_RETENTION_DAYS, $floor);

        $longer = $this->fixture->call('PUT', $this->retentionPath($this->orgA), $this->fixture->manager(), [
            'expected_revision' => $this->revision($this->orgA),
            'retention_policy' => ['access_log_days' => $floor + 365, 'basis' => RetentionBasis::LEGAL_OBLIGATION],
        ]);
        self::assertSame(200, $longer->status, json_encode($longer->data, JSON_THROW_ON_ERROR));
        self::assertSame($floor + 365, self::data($longer)['retention_policy']['access_log_days']);

        $shorter = $this->fixture->call('PUT', $this->retentionPath($this->orgA), $this->fixture->manager(), [
            'expected_revision' => $this->revision($this->orgA),
            'retention_policy' => ['access_log_days' => 30],
        ]);
        self::assertSame(422, $shorter->status);
        self::assertStringContainsString('access_log_days', json_encode($shorter->data, JSON_THROW_ON_ERROR));

        // The refused attempt is itself recorded. An organisation owner trying
        // to shorten their own audit trail is exactly the event that trail
        // exists to hold.
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('org_membership_events')->where('organisation_id', '=', $this->orgA)
                ->where('event_key', '=', 'retention_policy_refused'),
        ));
        // And the stored policy is still the one that was accepted.
        $stored = $this->fixture->env->database->selectOne(
            Query::from('organisations')->where('id', '=', $this->orgA),
        );
        self::assertIsArray($stored);
        self::assertStringContainsString('access_log_days', (string) $stored['retention_policy']);
        self::assertStringNotContainsString('"access_log_days":30', (string) $stored['retention_policy']);
    }

    public function testTheSweepKeepsARowLongerForAnOrganisationThatAskedAndPrunesTheRest(): void
    {
        $settings = new Settings(new DatabaseSettingsRepository(
            $this->fixture->env->database,
            $this->fixture->env->clock,
        ));
        $settings->set(AccessLogRetentionTask::RETENTION_SETTING, 30);
        $this->fixture->env->database->update('organisations', [
            'retention_policy' => json_encode(['_v' => RetentionPolicy::SHAPE, 'access_log_days' => 3650]),
            'retention_policy_version' => RetentionPolicy::SHAPE,
        ], ['id' => $this->orgA]);

        $old = '2020-01-01 00:00:00';
        foreach ([[$this->orgA, $this->orgA], [null, null]] as [$actorOrg, $subjectOrg]) {
            $this->fixture->env->database->insert('admin_access_log', [
                'event_type' => 'org.members.read', 'occurred_at' => $old, 'actor_id' => 1,
                'actor_org_id' => $actorOrg, 'subject_org_id' => $subjectOrg, 'route' => '/x',
                'outcome' => 'allowed', 'record_count' => 1, 'hash' => str_repeat('a', 64),
            ]);
        }

        $task = $this->fixture->kernel->registries->scheduledTasks->get(AccessLogRetentionTask::KEY);
        $line = $task->run(new ScheduledTaskRun(1, [], Principal::user(1)));

        self::assertStringContainsString('lengthened_organisations=1', $line);
        // The site-anchored row goes on the floor; the organisation's own row is
        // kept because its policy lengthened it, which is the only direction a
        // policy is ever allowed to move a retention.
        $kept = $this->fixture->env->database->selectAll(Query::from('admin_access_log'));
        self::assertCount(1, $kept);
        self::assertSame($this->orgA, (int) $kept[0]['actor_org_id']);
    }

    // ── The two delivery refusals ─────────────────────────────────────────

    public function testAnAuthorRenderAcrossOrganisationsIsRefusedRatherThanWarned(): void
    {
        $report = $this->fixture->kernel->registries->reports->get(OrgRosterReport::KEY);
        self::assertInstanceOf(RowPerPerson::class, $report);
        self::assertInstanceOf(TabularReportInterface::class, $report);

        $this->expectException(ValidationException::class);
        $this->fixture->kernel->reportExports->execute(
            $report,
            $this->reportContext($this->orgA),
            'csv',
            null,
            'digest',
            $this->orgA,
            [50, 60],
            null,
            \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext::RENDER_AS_AUTHOR,
        );
    }

    public function testAttachmentDeliveryOfARowPerPersonReportAcrossABoundaryIsRefused(): void
    {
        $this->fixture->kernel->registries->reportDeliveries->register(new AttachingDeliveryDouble());
        $report = $this->fixture->kernel->registries->reports->get(OrgRosterReport::KEY);
        self::assertInstanceOf(TabularReportInterface::class, $report);

        try {
            $this->fixture->kernel->reportExports->execute(
                $report,
                $this->reportContext($this->orgA),
                'csv',
                AttachingDeliveryDouble::KEY,
                'digest',
                $this->orgA,
                [50, 60],
            );
            self::fail('an attaching delivery across a boundary should be refused');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('delivery', $refusal->errors);
            self::assertStringContainsString('attachment', $refusal->errors['delivery']);
        }

        self::assertSame(
            0,
            $this->fixture->env->database->count(Query::from('report_runs')),
            'the refusal runs before the run row opens, so no artefact is written',
        );
    }

    // ── Erasure: the receipt, the basis and the chain ─────────────────────

    public function testTheLedgerIsPseudonymisedUnderALegalBasisAndTheChainStillVerifies(): void
    {
        $pool = $this->seatPool($this->orgA);
        $assignment = $this->fixture->kernel->organisationSeats->assign(
            $this->fixture->manager(),
            $this->orgA,
            $pool,
            ['expected_revision' => $this->revision($this->orgA), 'user_id' => 50],
        );
        // Released first, because erasure refuses while the organisation still
        // confers access. The release writes its own ledger row, so the chain
        // this test verifies is a real one of several rows rather than one.
        $this->fixture->kernel->organisationSeats->release(
            $this->fixture->manager(),
            $this->orgA,
            (int) $assignment['item']['id'],
            $this->revision($this->orgA),
        );

        $before = $this->fixture->kernel->ledgerRedactions->verifySeatLedger($this->orgA);
        self::assertSame([], $before['breaks']);
        self::assertSame([], $before['redacted']);
        self::assertGreaterThan(0, $before['checked']);

        $receipt = $this->erase($this->orgA, 50);

        self::assertGreaterThan(0, $receipt['pseudonymised'][LedgerRedactionStore::CLASS_SEAT_EVENTS]);
        self::assertSame(
            RetentionBasis::LEGAL_OBLIGATION,
            $receipt['retention_bases'][LedgerRedactionStore::CLASS_SEAT_EVENTS]['basis'],
        );
        self::assertIsString($receipt['retention_bases'][LedgerRedactionStore::CLASS_SEAT_EVENTS]['statute_ref']);

        // Nobody is named any more.
        self::assertSame(0, $this->fixture->env->database->count(
            Query::from('seat_events')->where('organisation_id', '=', $this->orgA)->where('subject_user_id', '=', 50),
        ));

        // And the chain still verifies: no link is broken, and the rows whose
        // content no longer recomputes are accounted for as redactions rather
        // than as edits.
        $after = $this->fixture->kernel->ledgerRedactions->verifySeatLedger($this->orgA);
        self::assertSame([], $after['breaks'], 'no link break');
        self::assertTrue($after['complete']);
        self::assertNotSame([], $after['redacted']);
        self::assertSame($before['checked'], $after['checked'], 'no ledger row was deleted');
    }

    public function testTheReceiptCountsWhatItLeftAloneBecauseItBelongsToAnotherOrganisation(): void
    {
        $this->member($this->orgB, 50);
        // A's grant is already revoked, because erasure refuses to run while an
        // organisation still confers access: the access termination ceremony
        // comes first and that is a shipped guard rather than a detail of this
        // test. What matters here is that B's live grant survives A's erasure.
        $this->fixture->env->database->update(
            'entitlements',
            ['status' => 'revoked'],
            ['id' => $this->grant($this->orgA, 50)],
        );
        $this->grant($this->orgB, 50);

        $receipt = $this->erase($this->orgA, 50);

        // Two: the grant organisation B paid for, and the course this learner
        // bought themselves, which carries no organisation at all. Both are rows
        // organisation A was never entitled to erase, and the receipt shows the
        // bound holding rather than asserting that it did.
        self::assertSame(2, $receipt['untouched_other_organisation']['entitlements']);
        self::assertSame(1, $receipt['retained']['entitlements']);
        // The other organisation's grant is untouched, which is the bound shown
        // rather than asserted.
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('entitlements')->where('organisation_id', '=', $this->orgB)
                ->where('user_id', '=', 50)->where('status', '=', 'active'),
        ));
    }

    public function testEveryRetainedRecordClassNamesABasisAndNoneIsUnjustified(): void
    {
        $receipt = $this->erase($this->orgA, 50);

        foreach (array_keys($receipt['retained'] + $receipt['pseudonymised']) as $recordClass) {
            self::assertArrayHasKey($recordClass, $receipt['retention_bases'], $recordClass);
            self::assertNotSame(
                RetentionBasis::NONE,
                $receipt['retention_bases'][$recordClass]['basis'],
                $recordClass . ' survives with no basis behind it',
            );
        }

        // The old warning string claimed no legal retention policy was claimed
        // at all. It is gone, because a basis is now recorded per record class.
        self::assertStringNotContainsString(
            'No legal retention policy',
            json_encode($receipt['warnings'], JSON_THROW_ON_ERROR),
        );
    }

    // ── The privacy contributor seam ──────────────────────────────────────

    public function testTheCoreLearningContributorCarriesTheTablesBothHostArraysNamed(): void
    {
        $contributor = $this->fixture->kernel->registries->privacyContributors->get(CoreLearningPrivacy::KEY);

        foreach (
            ['entitlements', 'enrolments', 'lesson_progress', 'video_positions', 'attempts',
            'submissions', 'certificates', 'activity_log', 'email_log', 'course_instructors'] as $table
        ) {
            self::assertArrayHasKey($table, $contributor->describes(), $table);
        }

        self::assertInstanceOf(EnumeratesOrganisationUsers::class, $contributor);
        self::assertInstanceOf(DescribesForArticle30::class, $contributor);
        self::assertInstanceOf(ErasesWithOutcome::class, $contributor);
    }

    public function testAddingATableToTheContributorChangesTheExportWithNoAdapterEdit(): void
    {
        $contributor = $this->fixture->kernel->registries->privacyContributors->get(CoreLearningPrivacy::KEY);
        $groups = $contributor->subjectGroups(50, 'lena@example.test');

        // The per-user direction both host hooks read is derived from the one
        // array. Child records are explicit additional groups, scoped through
        // their parent instead of a user_id column.
        self::assertSame(
            [...array_map(
                static fn (string $key): string => 'vidyalms-' . str_replace('_', '-', $key),
                array_keys(CoreLearningPrivacy::RECORD_CLASSES),
            ), 'vidyalms-attempt-questions', 'vidyalms-attempt-answer-events',
                'vidyalms-integrity-events', 'vidyalms-certificate-credits', 'vidyalms-compliance-cycles'],
            array_map(static fn (object $group): string => $group->key, $groups),
        );

        // And the seam itself: a contributor registered from outside the core
        // adds a record class that both host hooks show, with no adapter edit
        // and no language-key edit. That is the whole claim the two const
        // arrays falsified, tested rather than asserted.
        $before = \count($this->fixture->kernel->registries->privacyContributors->all());
        $this->fixture->kernel->registries->privacyContributors->register(new ExtraTablePrivacyDouble());

        $catalogue = self::data($this->fixture->call(
            'GET',
            '/manage/organisations/' . $this->orgA . '/privacy',
            $this->fixture->manager(),
        ));
        $classes = array_column($catalogue['items'], 'record_classes', 'key');

        self::assertCount($before + 1, $this->fixture->kernel->registries->privacyContributors->all());
        self::assertArrayHasKey(ExtraTablePrivacyDouble::KEY, $classes);
        self::assertArrayHasKey('widgets', $classes[ExtraTablePrivacyDouble::KEY]);
        self::assertSame(
            ['vidyalms-widgets'],
            array_map(
                static fn (object $group): string => $group->key,
                (new ExtraTablePrivacyDouble())->subjectGroups(50, 'lena@example.test'),
            ),
        );
    }

    public function testALearnersOwnLearningIsNeverErasedByAnOrganisation(): void
    {
        $contributor = $this->fixture->kernel->registries->privacyContributors->get(CoreLearningPrivacy::KEY);
        self::assertInstanceOf(ErasesWithOutcome::class, $contributor);
        self::assertInstanceOf(EnumeratesOrganisationUsers::class, $contributor);
        $outcome = $contributor->eraseWithOutcome(
            \Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext::forSubject($this->scope($this->orgA), 50),
        );

        self::assertSame([], $outcome->erasedCounts());
        self::assertArrayHasKey('enrolments', $outcome->untouchedCounts());
        self::assertSame([], $outcome->unjustified());
        self::assertSame([], iterator_to_array($contributor->usersInOrganisation(
            \Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext::forOrganisation($this->scope($this->orgA)),
        )));
    }

    public function testTheCatalogueNowOffersRetentionAndTheArticle30Metadata(): void
    {
        $catalogue = self::data($this->fixture->call(
            'GET',
            '/manage/organisations/' . $this->orgA . '/privacy',
            $this->fixture->manager(),
        ));

        self::assertTrue($catalogue['retention_policy_available']);
        self::assertSame(AccessLogRetentionTask::DEFAULT_RETENTION_DAYS, $catalogue['access_log_floor_days']);
        self::assertNull($catalogue['retention_policy']['access_log_days']);

        $described = array_column($catalogue['items'], 'article30', 'key');
        self::assertIsArray($described['core-organisations']);
        self::assertArrayHasKey(
            DescribesForArticle30::PURPOSE,
            $described['core-organisations']['seat_events'],
        );
    }

    // ── The narrow read model Pro validates against ───────────────────────

    public function testTheOrganisationReadModelAnswersOneRefusalForMissingAndForForeign(): void
    {
        $model = new OrganisationExistence(
            new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess(
                $this->fixture->kernel->organisationAccess,
            ),
            $this->fixture->env->database,
        );

        self::assertTrue($model->isVisibleTo($this->fixture->manager(), $this->orgA));
        self::assertFalse($model->isVisibleTo($this->fixture->manager(), 9999));
        self::assertFalse($model->isVisibleTo($this->fixture->learner(), $this->orgA));

        $missing = null;
        $foreign = null;

        try {
            $model->requireVisible($this->fixture->learner(), 9999);
        } catch (\Throwable $failure) {
            $missing = $failure::class . '|' . $failure->getMessage();
        }

        try {
            $model->requireVisible($this->fixture->learner(), $this->orgA);
        } catch (\Throwable $failure) {
            $foreign = $failure::class . '|' . $failure->getMessage();
        }

        self::assertNotNull($missing);
        // The OWASP API1 rule: an id that names nothing and one that names
        // somebody else's organisation read identically, so a cohort form cannot
        // be used to discover which ids exist.
        self::assertSame($missing, $foreign);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function organisation(string $slug): int
    {
        return (int) $this->fixture->kernel->organisations->create(
            $this->fixture->manager(),
            ['name' => $slug, 'slug' => $slug],
        )['id'];
    }

    private function member(int $org, int $userId): int
    {
        return (int) $this->fixture->kernel->organisationMembers->add(
            $this->fixture->manager(),
            $org,
            ['expected_revision' => $this->revision($org), 'user_id' => $userId, 'role_key' => 'org_member'],
        )['item']['id'];
    }

    private function revision(int $org): int
    {
        return (int) $this->fixture->kernel->organisations->get($this->fixture->manager(), $org)['revision'];
    }

    private function grant(int $org, int $userId): int
    {
        return $this->fixture->env->database->insert('entitlements', [
            'organisation_id' => $org, 'course_id' => $this->fixture->courseId, 'user_id' => $userId,
            'source' => 'org', 'status' => 'active',
            'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00',
        ]);
    }

    private function seatPool(int $org): int
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');
        $this->fixture->env->database->insert('org_course_releases', [
            'organisation_id' => $org, 'course_id' => $this->fixture->courseId, 'released_by' => 1,
            'state' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->fixture->kernel->organisationSeats->savePool($this->fixture->manager(), $org, null, [
            'expected_revision' => $this->revision($org),
            'name' => 'Seats',
            'course_id' => $this->fixture->courseId,
            'seats_granted' => 10,
            'state' => 'active',
        ])['item']['id'];
    }

    /** @param array<string, mixed> $extra */
    private function requirement(int $org, array $extra = []): int
    {
        return $this->fixture->env->database->insert('compliance_requirements', [
            'organisation_id' => $org, 'scope_type' => 'organisation', 'scope_id' => $org,
            'title' => 'Fire safety', 'requirement' => 'mandatory',
            // No rule, which is what every free requirement carries.
            'rule_key' => ComplianceRuleRegistry::NONE,
            'rule_params_version' => 1, 'state' => 'active', 'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ] + $extra);
    }

    /**
     * The organisation contributor's own erasure receipt, read from the concrete
     * provider so the two keys the shipped interface cannot declare are typed.
     *
     * @return array<string, mixed>
     */
    private function erase(int $org, int $userId): array
    {
        $contributor = $this->fixture->kernel->registries->privacyContributors->get('core-organisations');
        self::assertInstanceOf(CoreOrganisationsPrivacy::class, $contributor);

        return $contributor->eraseWithOutcome(
            \Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext::forSubject($this->scope($org), $userId),
        )->toArray();
    }

    private function scope(int $org): \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope
    {
        return $this->fixture->kernel->organisationAccess->require($this->fixture->manager(), $org, 'settings');
    }

    private function reportContext(int $org): \Shondalai\VidyaLMS\Core\Registry\Context\ReportContext
    {
        return \Shondalai\VidyaLMS\Core\Registry\Context\ReportContext::create(
            [],
            $this->fixture->manager(),
            null,
            null,
            null,
            null,
            null,
            'en-GB',
            $this->scope($org),
        );
    }

    private function retentionPath(int $org): string
    {
        return '/manage/organisations/' . $org . '/retention-policy';
    }

    private function report(int $org, string $key): ApiResponse
    {
        return $this->fixture->call(
            'GET',
            '/manage/organisations/' . $org . '/reports/' . $key,
            $this->fixture->manager(),
        );
    }

    /** @return array<string, mixed> */
    private static function data(ApiResponse $response): array
    {
        self::assertIsArray($response->data);

        return $response->data;
    }
}
