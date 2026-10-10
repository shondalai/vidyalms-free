<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\Roster\RosterImporter;
use Shondalai\VidyaLMS\Core\Provider\Roster\OneRosterCsvMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OrgMembersCsvMapper;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;
use Shondalai\VidyaLMS\Core\Registry\Importer\PreflightsBeforeWriting;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;
use Shondalai\VidyaLMS\Core\Registry\Importer\StagedImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The two free roster importers, run through the kernel that ships them.
 *
 * Nothing here builds an importer by hand. Every run goes through the real
 * registered provider, the real organisation import surface, the real queue and
 * the real runner, which is the only way to prove the sentence this wave owes:
 * that the free build adds people by CSV and by OneRoster, with row-level
 * problems, through routes an organisation administrator can actually reach.
 */
final class RosterImportTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private int $organisation;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports, [
            'organisation_invitation_secret' => 'roster-import-test-secret-value-01',
            'organisation_invitation_url' => 'https://example.invalid/membership',
        ]);

        $this->env->users->add(1, 'Site Manager', 'manager@example.test');
        $this->env->users->add(2, 'Sam Ling', 'sam@example.test');
        $this->env->users->add(3, 'Ana Rao', 'ana@example.test');
        $this->env->users->add(4, 'Lee Wu', 'lee@example.test');
        $this->env->authorization->grant(1, Capability::MANAGE);

        $this->organisation = $this->kernel->organisationStore->create([
            'name' => 'North District',
            'slug' => 'north-district',
            'created_by' => 1,
        ]);
    }

    /**
     * The three declarations R4 shipped with no declarer. Both roster importers
     * are their first, which is what turns an automatic dry pass and an
     * enforced load order from a property of the seam into a property of a run.
     */
    public function testBothRosterImportersAreTheFirstShippedDeclarersOfTheThreeCapabilities(): void
    {
        foreach ([OrgMembersCsvMapper::FORMAT . ':in', OneRosterCsvMapper::FORMAT . ':in'] as $key) {
            $importer = $this->kernel->registries->importers->get($key);

            self::assertInstanceOf(RosterImporter::class, $importer, $key);
            self::assertInstanceOf(RunsForAnOrganisation::class, $importer, $key . ' may run under a tenant');
            self::assertInstanceOf(PreflightsBeforeWriting::class, $importer, $key . ' gets an automatic dry pass');
            self::assertInstanceOf(StagedImporterInterface::class, $importer, $key . ' loads in a fixed order');
        }

        self::assertSame(
            [OrgMembersCsvMapper::FORMAT . ':in', OneRosterCsvMapper::FORMAT . ':in'],
            $this->kernel->registries->rosterMappers->keys(),
            'The mappers are registered on their own seam, under the same keys.',
        );
    }

    public function testAMemberCsvAddsPeopleThroughTheOrganisationImportSurface(): void
    {
        $job = $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member',
            'ana@example.test,Ana,Rao,emp-2,,org_reporter',
        ]));

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_ORG_MEMBERS] ?? 0);
        self::assertSame([2, 3], $this->memberUserIds());
        self::assertSame(
            ['org_member', 'org_reporter'],
            $this->roleKeys(),
            'A role key this site registers is taken as itself and not run through a foreign vocabulary.',
        );
    }

    /**
     * The acceptance test the whole import contract is measured by: N, 0, 0
     * created with 0, N, N unchanged or updated.
     */
    public function testASecondRunOfTheSameFileUpdatesRatherThanDuplicating(): void
    {
        $file = self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']);

        $first = $this->loadRoster($file);
        self::assertSame(1, $first->totals[ImportResult::TOTAL_CREATED] ?? 0);

        $second = $this->loadRoster($file, [], 'imports/1/members-again.csv');
        self::assertSame(0, $second->totals[ImportResult::TOTAL_CREATED] ?? 0);
        self::assertSame(1, $this->memberCount(), 'One person, one membership, after two runs.');
    }

    /**
     * The rule the free build's tenancy rests on: an organisation column is
     * refused by name, with the operator's own language, before any row is read.
     */
    public function testAMemberCsvNamingAnOrganisationFailsTheJobWithATranslatedError(): void
    {
        $job = $this->loadRoster(
            "email,given_name,organisation_id\r\nsam@example.test,Sam,99\r\n"
        );

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('vidyalms.roster.error.organisation_column', (string) $job->log);
        self::assertSame(0, $this->memberCount(), 'Nothing was written.');

        // The operator reads the column name, not a key. The stub translator
        // in this harness answers with the key, so the substitution itself is
        // asserted where it lives: the shipped catalogue string names the
        // column and the file it was found in.
        $catalogue = require __DIR__ . '/../../../i18n/catalogue.php';
        self::assertStringContainsString('{column}', $catalogue['vidyalms.roster.error.organisation_column']);
        self::assertStringContainsString('{file}', $catalogue['vidyalms.roster.error.organisation_column']);
    }

    /**
     * Rule two of the role mapper, reached from a real file: a district export
     * asserting an administrator of its own system does not become an
     * administrator here.
     */
    public function testARosterAssertingSystemAdministratorIsRefusedAndTheRowIsReported(): void
    {
        $job = $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,systemAdministrator',
            'ana@example.test,Ana,Rao,emp-2,,teacher',
        ]));

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame([3], $this->memberUserIds(), 'Only the teacher arrived.');

        $problems = $this->problems($job->id);
        self::assertSame(RowProblem::CODE_FORBIDDEN_SCOPE, $problems[0]['code']);
        self::assertStringContainsString('site administration', (string) $problems[0]['message']);
    }

    public function testAForeignRoleNobodyTranslatesIsAWarnedSkipAndNeverADefault(): void
    {
        $job = $this->loadRoster(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,house-captain']));

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame(0, $this->memberCount(), 'No default role was substituted.');
        self::assertSame(RowProblem::CODE_UNMAPPED_ROLE, $this->problems($job->id)[0]['code']);
    }

    /**
     * The manager check R1 left to this wave, reached through the roster path.
     *
     * A manager who is not an active member of this organisation is refused on
     * the row rather than dropped, because the free line_manager audience
     * resolves recipients straight from the column.
     */
    public function testAManagerOutsideThisOrganisationIsRefusedOnTheRow(): void
    {
        $job = $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member,,lee@example.test',
        ], 'email,given_name,family_name,external_id,unit_path,role_key,job_title,manager_email'));

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame(0, $this->memberCount());
        self::assertStringContainsString(
            'active membership in this organisation',
            (string) $this->problems($job->id)[0]['message'],
        );
    }

    public function testAManagerWhoIsAnActiveMemberIsWrittenOntoTheMembership(): void
    {
        $this->loadRoster(self::memberCsv(['lee@example.test,Lee,Wu,emp-9,,org_member']));

        $job = $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member,,lee@example.test',
        ], 'email,given_name,family_name,external_id,unit_path,role_key,job_title,manager_email'), [], 'imports/1/two.csv');

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);

        $row = $this->env->database->selectOne(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisation)
                ->where('user_id', '=', 2)
        );

        self::assertSame(4, (int) ($row['manager_user_id'] ?? 0));
        self::assertSame(RosterImporter::PROVENANCE, $row['provisioned_by'] ?? '');
        self::assertSame('emp-1', $row['source_ref'] ?? '');
    }

    /**
     * A OneRoster package, from the specification's own file set, through the
     * same surface. The manifest mixes a bulk file with a delta file, which the
     * runner honours per file.
     */
    public function testAOneRosterPackageLoadsOrgsUsersAndRoles(): void
    {
        $job = $this->loadRoster(self::package([
            'orgs' => 'bulk',
            'users' => 'bulk',
            'roles' => 'delta',
        ], self::rolesCsv([
            'role-1,active,2026-03-03T09:00:00.000Z,user-1,primary,teacher,,,school-01',
            'role-2,active,2026-03-03T09:00:00.000Z,user-2,primary,student,,,school-01',
        ])), [], 'imports/1/roster.zip', OneRosterCsvMapper::FORMAT . ':in');

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_ORGS] ?? 0, 'orgs.csv became one unit of this tree.');
        self::assertSame(2, $job->totals[ImportResult::TOTAL_ORG_MEMBERS] ?? 0);
        self::assertSame([2, 3], $this->memberUserIds());

        $unit = $this->env->database->selectOne(
            Query::from('org_units')->where('organisation_id', '=', $this->organisation)
        );
        self::assertSame('North High', $unit['name'] ?? '');

        self::assertStringContainsString(
            'not a certified consumer',
            (string) $job->log,
            'The conformance subset is stated in the first line of the run\'s own log.',
        );
    }

    /**
     * The tombstone, which is the only deletion instruction the free build
     * honours, and the negative case beside it.
     */
    public function testATombstoneClosesTheMembershipAndAnAbsentRowLeavesItAlone(): void
    {
        $this->loadRoster(self::package(
            ['orgs' => 'bulk', 'users' => 'bulk', 'roles' => 'delta'],
            self::rolesCsv([
                'role-1,active,2026-03-03T09:00:00.000Z,user-1,primary,teacher,,,school-01',
                'role-2,active,2026-03-03T09:00:00.000Z,user-2,primary,student,,,school-01',
            ])
        ), [], 'imports/1/first.zip', OneRosterCsvMapper::FORMAT . ':in');

        self::assertSame([2, 3], $this->memberUserIds());

        // The second package names one of the two, as a tombstone, and does not
        // mention the other at all.
        $job = $this->loadRoster(self::package(
            ['orgs' => 'absent', 'users' => 'bulk', 'roles' => 'delta'],
            self::rolesCsv(['role-1,tobedeleted,2026-04-01T09:00:00.000Z,user-1,primary,teacher,,,school-01']),
            self::usersCsv(['user-1,,,true,Sam,Ling,,E-1,sam@example.test'])
        ), [], 'imports/1/second.zip', OneRosterCsvMapper::FORMAT . ':in');

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame([3], $this->memberUserIds(), 'The tombstone closed one membership.');
        self::assertSame(
            MembershipState::ACTIVE,
            $this->stateOf(3),
            'A row simply missing from a later file causes nothing at all.',
        );
        self::assertSame(MembershipState::LEFT, $this->stateOf(2));
    }

    /**
     * Absence is not a deletion even when the operator asks for the sweep, if
     * the sweep would take more of the roster than the declared threshold.
     */
    public function testTheSweepIsOffByDefaultAndRefusesItselfAboveTheChangeThreshold(): void
    {
        $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member',
            'ana@example.test,Ana,Rao,emp-2,,org_member',
            'lee@example.test,Lee,Wu,emp-3,,org_member',
        ]));
        self::assertSame(3, $this->memberCount());

        // Off by default: a file naming one of the three closes neither of the
        // other two.
        $quiet = $this->loadRoster(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']), [], 'imports/1/b.csv');
        self::assertSame(ImportJob::STATUS_COMPLETED, $quiet->status);
        self::assertSame(3, $this->activeMemberCount());

        // Asked for, and refused: closing two of three is 66 percent, and the
        // threshold is ten.
        $swept = $this->loadRoster(
            self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']),
            [RosterImporter::OPTION_SWEEP => true],
            'imports/1/c.csv'
        );

        self::assertSame(3, $this->activeMemberCount(), 'The sweep refused itself rather than running partly.');
        self::assertStringContainsString('sweep refused itself', (string) $swept->log);
        self::assertStringContainsString('above the 10% threshold', (string) $swept->log);
    }

    public function testASweepWithinItsThresholdClosesOnlyTheMembershipsThisImporterWrote(): void
    {
        $this->loadRoster(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member',
            'ana@example.test,Ana,Rao,emp-2,,org_member',
        ]));

        $job = $this->loadRoster(
            self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']),
            [RosterImporter::OPTION_SWEEP => true, RosterImporter::OPTION_THRESHOLD => 60],
            'imports/1/d.csv'
        );

        self::assertStringContainsString('sweep closed 1 of 2', (string) $job->log);
        self::assertSame(MembershipState::LEFT, $this->stateOf(3));
        self::assertSame(MembershipState::ACTIVE, $this->stateOf(2));
    }

    /**
     * The upsert policy is a declared option and not an inference, and
     * update_only is the one an operator picks when a typo in a key must not
     * quietly create a person.
     */
    public function testUpdateOnlyCreatesNothing(): void
    {
        $job = $this->loadRoster(
            self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']),
            [UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(0, $this->memberCount());
        // Two rows, not one: the people stage read the person and found their
        // account, and the memberships stage read the membership and left it
        // alone. One line of a member CSV is two rows of two stages.
        self::assertSame(2, $job->totals[ImportResult::TOTAL_UNCHANGED] ?? 0);
    }

    /**
     * The roster path writes through the same use cases the console does, so
     * every row it loads earns the split event vocabulary and the two clocks.
     */
    public function testEveryMembershipTheRosterWritesCarriesItsProvenanceOnTheEventStream(): void
    {
        $this->loadRoster(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']));

        $event = $this->env->database->selectOne(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $this->organisation)
                ->where('event_key', '=', MembershipEvent::JOINED)
        );

        self::assertNotNull($event);
        self::assertSame(2, (int) $event['subject_id']);
        self::assertNotNull($event['occurred_at']);
        self::assertNotNull($event['recorded_at']);
    }

    /**
     * Every organisation-scoped key this importer records carries the tenant,
     * so two districts exporting the same sourcedId cannot collide.
     */
    public function testEveryRecordedKeyCarriesTheOrganisation(): void
    {
        $this->loadRoster(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']));

        $keys = $this->env->database->selectAll(
            Query::from('import_map')->where('importer', '=', OrgMembersCsvMapper::FORMAT . ':in')->orderBy('id')
        );

        self::assertNotSame([], $keys);

        foreach ($keys as $row) {
            self::assertSame(
                $this->organisation,
                ImportSourceKey::organisationOf((string) $row['source_key']),
                (string) $row['source_type'],
            );
        }
    }

    /**
     * orgs.csv is the file that defines the tree, so it is the deliberate
     * exception to "the organisation comes from the run context": it is
     * reachable only by a caller holding site scope or an assignment over the
     * whole organisation, checked before the first row is read.
     */
    public function testOnlyASiteOrWholeOrganisationCallerMayLoadTheFileThatDefinesTheTree(): void
    {
        $this->unitScopedAdministrator();
        $this->env->storage->putPrivate('imports/3/roster.zip', self::package([
            'orgs' => 'bulk',
            'users' => 'bulk',
            'roles' => 'bulk',
        ]));

        $queued = $this->kernel->organisationImports->start(
            Principal::user(3),
            $this->organisation,
            OneRosterCsvMapper::FORMAT . ':in',
            'imports/3/roster.zip',
            [],
        );
        $job = $this->kernel->runQueuedImport->execute($queued->id);

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('vidyalms.roster.error.orgs_scope', (string) $job->log);
        self::assertSame(0, $this->env->database->count(
            Query::from('org_units')->where('organisation_id', '=', $this->organisation)->where('name', '=', 'North High')
        ), 'The gate ran before the first row.');
    }

    /**
     * The same caller, the same file set without orgs.csv, loads. The gate is
     * about the one file and not about the person.
     */
    public function testTheSameUnitScopedCallerLoadsAPackageWithoutTheOrgsFile(): void
    {
        $this->unitScopedAdministrator();
        $this->env->storage->putPrivate('imports/3/people.zip', self::package([
            'orgs' => 'absent',
            'users' => 'bulk',
            'roles' => 'bulk',
        ]));

        $queued = $this->kernel->organisationImports->start(
            Principal::user(3),
            $this->organisation,
            OneRosterCsvMapper::FORMAT . ':in',
            'imports/3/people.zip',
            [],
        );
        $job = $this->kernel->runQueuedImport->execute($queued->id);

        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status, (string) $job->log);
    }

    /**
     * An address with no account here is reported and not mailed, unless the
     * operator asked for the invitation. Both halves are the option's meaning.
     */
    public function testAnUnknownAddressIsReportedAndOnlyInvitedWhenAskedFor(): void
    {
        $file = self::memberCsv(['newcomer@example.test,New,Comer,emp-7,,org_member']);

        $quiet = $this->loadRoster($file);
        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $quiet->status);
        self::assertSame(RowProblem::CODE_REFERENTIAL_MISS, $this->problems($quiet->id)[0]['code']);
        self::assertSame(0, $this->env->database->count(Query::from('invitations')));

        $inviting = $this->loadRoster($file, [RosterImporter::OPTION_INVITE => true], 'imports/1/invite.csv');
        self::assertSame(ImportJob::STATUS_COMPLETED, $inviting->status, (string) $inviting->log);
        self::assertSame(1, $inviting->totals[ImportResult::TOTAL_INVITATIONS] ?? 0);
        self::assertSame(1, $this->env->database->count(Query::from('invitations')));
    }
    /**
     * The catalogue route stops answering with an honest empty list.
     *
     * R4 shipped the organisation import surface with nothing eligible to run,
     * so a screen asking what an org administrator may start was told nothing.
     * These two are the answer, and the catalogue is where a host screen and a
     * headless client both read it from.
     */
    public function testTheImporterCatalogueNowReportsTwoOrganisationEligibleFormats(): void
    {
        $eligible = array_values(array_filter(
            $this->kernel->listImporters->execute(Principal::user(1)),
            static fn (array $row): bool => $row['organisation_eligible'] === true,
        ));

        self::assertSame(
            [OrgMembersCsvMapper::FORMAT . ':in', OneRosterCsvMapper::FORMAT . ':in'],
            array_column($eligible, 'key'),
        );

        // And each one declares the upsert policy and the sweep options an
        // operator has to be able to see before they start a run.
        $options = array_column($eligible[0]['options'], 'key');
        self::assertContains(UpsertPolicy::OPTION_KEY, $options);
        self::assertContains(RosterImporter::OPTION_SWEEP, $options);
        self::assertContains(RosterImporter::OPTION_THRESHOLD, $options);
    }
    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    /**
     * Starts a real run through the organisation import surface and drains it.
     *
     * A real run is queued rather than executed inline, because a forty
     * thousand row roster is not a request, so the drain is part of the path
     * this test is proving.
     *
     * @param array<string, mixed> $options
     */
    private function loadRoster(
        string $contents,
        array $options = [],
        string $path = 'imports/1/members.csv',
        string $importer = OrgMembersCsvMapper::FORMAT . ':in',
    ): ImportJob {
        $this->env->storage->putPrivate($path, $contents);

        $queued = $this->kernel->organisationImports->start(
            Principal::user(1),
            $this->organisation,
            $importer,
            $path,
            $options,
        );

        return $this->kernel->runQueuedImport->execute($queued->id);
    }

    /**
     * A caller whose only authority is one unit of the tree: an org_admin
     * assigned at scope_type unit, which the free build supports and which is
     * what makes the org_unit_managers audience reachable.
     */
    private function unitScopedAdministrator(): void
    {
        $this->env->authorization->grant(3, Capability::MANAGE_GROUPS);
        $unit = $this->kernel->organisationUnits->save(Principal::user(1), $this->organisation, null, [
            'expected_revision' => $this->revision(),
            'name' => 'South Wing',
        ]);
        $membership = $this->env->database->insert('org_memberships', [
            'organisation_id' => $this->organisation,
            'org_unit_id' => (int) $unit['item']['id'],
            'user_id' => 3,
            'state' => MembershipState::ACTIVE,
            'active_key' => $this->organisation . ':3',
            'joined_at' => '2026-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->env->database->insert('org_role_assignments', [
            'organisation_id' => $this->organisation,
            'membership_id' => $membership,
            'role_key' => 'org_admin',
            'scope_type' => 'unit',
            'scope_id' => (int) $unit['item']['id'],
            'assigned_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function testUnitAdministratorOnlySeesImportsAcceptedForTheirUnit(): void
    {
        $this->unitScopedAdministrator();
        $path = 'imports/3/people.zip';
        $this->env->storage->putPrivate($path, self::package(['orgs' => 'absent', 'users' => 'bulk', 'roles' => 'bulk']));
        $own = $this->kernel->organisationImports->start(
            Principal::user(3),
            $this->organisation,
            OneRosterCsvMapper::FORMAT . ':in',
            $path
        );
        self::assertNotNull($own->organisationUnitId);
        $jobs = new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository(
            $this->env->database,
            $this->env->clock,
        );
        $whole = $jobs->create('roster', 'imports/1/whole.csv', [], 1, $this->organisation);
        $other = $jobs->create('roster', 'imports/1/other.csv', [], 1, $this->organisation, 999);
        $page = $this->kernel->organisationImports->jobs(Principal::user(3), $this->organisation);
        self::assertSame(1, $page->total);
        self::assertSame($own->id, $page->items[0]->id);
        foreach ([$whole, $other] as $id) {
            try {
                $this->kernel->organisationImports->problems(Principal::user(3), $this->organisation, $id);
                self::fail('An import outside the unit disclosed its problems.');
            } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException $error) {
                self::assertNotEmpty($error->getMessage());
            }
        }
    }
    public function testAnOrganisationAdministratorCanUploadAndPreviewOnlyTheirOwnRoster(): void
    {
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $this->kernel->organisationMembers->add(Principal::user(1), $this->organisation, [
            'expected_revision' => $this->revision(), 'user_id' => 2, 'role_key' => 'org_admin',
        ]);
        $actor = Principal::user(2);
        self::assertFalse($this->env->authorization->can(2, Capability::TEACH));
        self::assertFalse($this->env->authorization->can(2, Capability::MANAGE));
        $this->kernel->authoriseUpload->execute($actor, 'org_import', $this->organisation);
        $policy = \Shondalai\VidyaLMS\Core\Upload\UploadPolicy::free();
        self::assertSame(['csv', 'zip'], $policy->purpose('org_import')?->extensions());
        $upload = (new \Shondalai\VidyaLMS\Core\Upload\UploadIntake($policy, $this->env->storage, $this->env->random))->store(
            self::memberCsv(['lee@example.test,Lee,Wu,emp-4,,org_member']),
            'members.csv',
            'org_import',
            2,
        );
        self::assertStringStartsWith('imports/2/', $upload->path);
        self::assertNull($upload->url);
        $before = $this->memberCount();
        $job = $this->kernel->organisationImports->start(
            $actor,
            $this->organisation,
            OrgMembersCsvMapper::FORMAT . ':in',
            $upload->path,
            ['dry_run' => true]
        );
        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status, (string) $job->log);
        self::assertSame($this->organisation, $job->organisationId);
        self::assertSame($before, $this->memberCount());

        $other = $this->kernel->organisationStore->create(['name' => 'Other', 'slug' => 'other', 'created_by' => 1]);
        foreach ([$other, 999999] as $foreign) {
            try {
                $this->kernel->authoriseUpload->execute($actor, 'org_import', $foreign);
                self::fail('A roster upload crossed organisation scope.');
            } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException $refusal) {
                self::assertSame('No organisation here.', $refusal->getMessage());
            }
        }
        try {
            $this->kernel->authoriseUpload->execute($actor, 'resource', $this->organisation);
            self::fail('Roster permission must not grant general authoring uploads.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException) {
            self::assertFalse($this->env->authorization->can(2, Capability::TEACH));
        }
    }

    /** @return list<int> */
    private function memberUserIds(): array
    {
        $rows = $this->env->database->selectAll(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisation)
                ->where('state', '=', MembershipState::ACTIVE)
                ->orderBy('user_id')
        );

        return array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
    }

    /** @return list<string> */
    private function roleKeys(): array
    {
        $rows = $this->env->database->selectAll(
            Query::from('org_role_assignments')
                ->where('organisation_id', '=', $this->organisation)
                ->orderBy('id')
        );
        $keys = array_map(static fn (array $row): string => (string) $row['role_key'], $rows);
        sort($keys);

        return $keys;
    }

    /** The organisation's current revision, which every write claims against. */
    private function revision(): int
    {
        $row = $this->env->database->selectOne(
            Query::from('organisations')->where('id', '=', $this->organisation)
        );

        return (int) ($row['revision'] ?? 1);
    }
    private function memberCount(): int
    {
        return $this->env->database->count(
            Query::from('org_memberships')->where('organisation_id', '=', $this->organisation)
        );
    }

    private function activeMemberCount(): int
    {
        return $this->env->database->count(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisation)
                ->where('state', '=', MembershipState::ACTIVE)
        );
    }

    private function stateOf(int $userId): string
    {
        $row = $this->env->database->selectOne(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisation)
                ->where('user_id', '=', $userId)
        );

        return (string) ($row['state'] ?? '');
    }

    /** @return list<array<string, mixed>> */
    private function problems(int $jobId): array
    {
        return $this->env->database->selectAll(
            Query::from('import_row_problems')->where('import_job_id', '=', $jobId)->orderBy('id')
        );
    }

    /** @param list<string> $rows */
    private static function memberCsv(array $rows, string $header = ''): string
    {
        $header = $header === '' ? 'email,given_name,family_name,external_id,unit_path,role_key' : $header;

        return $header . "\r\n" . implode("\r\n", $rows) . "\r\n";
    }

    /**
     * A flat zip of stored entries, which is what a conformant OneRoster
     * package is. It is built here rather than committed as a binary fixture so
     * a reader can see exactly which bytes the reader is being asked to read.
     *
     * @param array<string, string> $modes
     */
    private static function package(array $modes, string $roles = '', string $users = ''): string
    {
        $lines = ['propertyName,value', 'manifest.version,1.0', 'oneroster.version,1.2'];

        foreach ($modes as $file => $mode) {
            $lines[] = 'file.' . $file . ',' . $mode;
        }

        $files = ['manifest.csv' => implode("\r\n", $lines) . "\r\n"];

        if (($modes['orgs'] ?? 'absent') !== 'absent') {
            $files['orgs.csv'] = "sourcedId,status,dateLastModified,name,type,identifier,parentSourcedId\r\n"
                . "school-01,,,North High,school,NH,\r\n";
        }

        $files['users.csv'] = $users !== '' ? $users : self::usersCsv([
            'user-1,,,true,Sam,Ling,,E-1,sam@example.test',
            'user-2,,,true,Ana,Rao,,E-2,ana@example.test',
        ]);
        $files['roles.csv'] = $roles !== '' ? $roles : self::rolesCsv([
            'role-1,,,user-1,primary,teacher,,,school-01',
        ]);

        return self::zip($files);
    }

    /** @param list<string> $rows */
    private static function usersCsv(array $rows): string
    {
        return "sourcedId,status,dateLastModified,enabledUser,givenName,familyName,middleName,identifier,email\r\n"
            . implode("\r\n", $rows) . "\r\n";
    }

    /** @param list<string> $rows */
    private static function rolesCsv(array $rows): string
    {
        return "sourcedId,status,dateLastModified,userSourcedId,roleType,role,beginDate,endDate,orgSourcedId\r\n"
            . implode("\r\n", $rows) . "\r\n";
    }

    /** @param array<string, string> $files */
    private static function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($files as $name => $contents) {
            $crc = crc32($contents);
            $size = \strlen($contents);
            $header = "\x50\x4b\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $size, $size, \strlen($name), 0)
                . $name;
            $local .= $header . $contents;
            $central .= "\x50\x4b\x01\x02"
                . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, $size, $size, \strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
            $offset += \strlen($header) + $size;
        }

        return $local . $central . "\x50\x4b\x05\x06"
            . pack('vvvvVVv', 0, 0, \count($files), \count($files), \strlen($central), $offset, 0);
    }
}
