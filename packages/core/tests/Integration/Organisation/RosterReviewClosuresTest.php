<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\Roster\RosterImporter;
use Shondalai\VidyaLMS\Core\Provider\Roster\OneRosterCsvMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OrgMembersCsvMapper;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The roster half of the R5 review, each finding as the run that reproduces it.
 *
 * Every case here is a real run through the registered importer, the real
 * organisation import surface and the real runner, for the reason the wave's
 * own roster test states: a defect proved against a hand-built importer is a
 * defect proved against something no operator can reach.
 */
final class RosterReviewClosuresTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private int $organisation;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports, [
            'organisation_invitation_secret' => 'roster-review-test-secret-value-001',
            'organisation_invitation_url' => 'https://example.invalid/membership',
        ]);

        $this->env->users->add(1, 'Site Manager', 'manager@example.test');
        $this->env->users->add(2, 'Sam Ling', 'sam@example.test');
        $this->env->users->add(3, 'Ana Rao', 'ana@example.test');
        $this->env->authorization->grant(1, Capability::MANAGE);

        $this->organisation = $this->kernel->organisationStore->create([
            'name' => 'North District',
            'slug' => 'north-district',
            'created_by' => 1,
        ]);
    }

    // ── The sweep ────────────────────────────────────────────────────────

    /**
     * The critical one. The sweep compared the person's own sourcedId against
     * `org_memberships.source_ref`, which holds the membership's, so for
     * OneRoster the two never matched: every row the file named looked absent,
     * the share was always 100%, and a sweep at threshold 100 closed the whole
     * roster it had just written.
     */
    public function testTheOneRosterSweepLeavesEveryMembershipTheFileNames(): void
    {
        $package = self::package(
            ['orgs' => 'bulk', 'users' => 'bulk', 'roles' => 'bulk'],
            self::rolesCsv([
                'role-1,,,user-1,primary,teacher,,,school-01',
                'role-2,,,user-2,primary,student,,,school-01',
            ])
        );

        $first = $this->loadPackage($package, [], 'imports/1/first.zip');
        self::assertSame(ImportJob::STATUS_COMPLETED, $first->status, (string) $first->log);
        self::assertSame([2, 3], $this->memberUserIds());

        $swept = $this->loadPackage($package, [
            RosterImporter::OPTION_SWEEP => true,
            RosterImporter::OPTION_THRESHOLD => 100,
        ], 'imports/1/second.zip');

        self::assertSame([2, 3], $this->memberUserIds(), 'Both were in the file, so the sweep closed neither.');
        self::assertStringNotContainsString('The sweep closed', (string) $swept->log);
    }

    /**
     * Both roster importers stamp the same provenance word, so a member-CSV
     * sweep used to consider the rows a OneRoster package wrote. The import map
     * is what tells them apart.
     */
    public function testAMemberCsvSweepNeverClosesARowTheOtherRosterImporterWrote(): void
    {
        $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'bulk'], self::rolesCsv([
                'role-1,,,user-2,primary,student,,,',
            ])),
            [],
            'imports/1/oneroster.zip'
        );
        self::assertSame([3], $this->memberUserIds(), 'Ana arrived through the OneRoster package.');

        // A member CSV naming only Sam, swept at a threshold that would close
        // anything it considered absent.
        $this->loadCsv(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']));
        $this->loadCsv(
            self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']),
            [RosterImporter::OPTION_SWEEP => true, RosterImporter::OPTION_THRESHOLD => 100],
            'imports/1/sweep.csv'
        );

        self::assertSame(
            MembershipState::ACTIVE,
            $this->stateOf(3),
            'A file this importer never wrote is not evidence about the other one\'s rows.',
        );
    }

    public function testSweepLeavesDirectoryManagedMembershipsAlone(): void
    {
        $this->loadCsv(self::memberCsv([
            'sam@example.test,Sam,Ling,emp-1,,org_member',
            'ana@example.test,Ana,Rao,emp-2,,org_member',
        ]));
        $this->env->database->update('org_memberships', ['directory_managed' => 1],
            ['organisation_id' => $this->organisation, 'user_id' => 3]);
        $job = $this->loadCsv(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_member']),
            [RosterImporter::OPTION_SWEEP => true, RosterImporter::OPTION_THRESHOLD => 100], 'imports/1/sweep-directory.csv');
        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame(MembershipState::ACTIVE, $this->stateOf(3));
    }

    // ── The three organisation columns ───────────────────────────────────

    /**
     * The rejection used to be case sensitive while the header was not, so a
     * file carrying `Organisation_ID` loaded successfully into the console's
     * organisation, which is verbatim the harm the class docblock names.
     *
     * @dataProvider organisationColumnCases
     */
    public function testAnOrganisationColumnIsRefusedWhateverItsCase(string $column): void
    {
        $job = $this->loadCsv(
            'email,given_name,' . $column . "\r\nsam@example.test,Sam,99\r\n",
            [],
            'imports/1/org-column.csv'
        );

        self::assertSame(ImportJob::STATUS_FAILED, $job->status, (string) $job->log);
        self::assertStringContainsString('vidyalms.roster.error.organisation_column', (string) $job->log);
        self::assertSame(0, $this->memberCount(), 'Nothing was written.');
    }

    /**
     * @return list<array{string}>
     */
    public static function organisationColumnCases(): array
    {
        return [['organisation_id'], ['Organisation_ID'], ['Organisation'], ['ORG_SLUG']];
    }

    /**
     * The same root cause on a documented column: a header spelled `Role_Key`
     * silently discarded the role, so a row asking for org_reporter landed as
     * an ordinary member.
     */
    public function testADocumentedColumnIsReadWhateverItsCase(): void
    {
        $job = $this->loadCsv(
            "Email,Given_Name,Family_Name,External_ID,Unit_Path,Role_Key\r\n"
                . "ana@example.test,Ana,Rao,emp-2,,org_reporter\r\n"
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame(['org_reporter'], $this->roleKeys());
    }

    // ── The manifest ─────────────────────────────────────────────────────

    /**
     * A mode word nobody reads used to fall back to absent, which dropped the
     * file: the run finished green with no memberships, no row problems and no
     * line in the log naming the file it had skipped.
     */
    public function testAManifestModeNobodyReadsIsReportedRatherThanDroppingTheFileInSilence(): void
    {
        $job = $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'full'], self::rolesCsv([
                'role-1,,,user-1,primary,teacher,,,',
            ])),
            [],
            'imports/1/bad-mode.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertSame(0, $this->memberCount(), 'The file was still not read.');

        $problems = $this->problems($job->id);
        self::assertSame('manifest.csv', $problems[0]['file'] ?? '');
        self::assertStringContainsString('file.roles', (string) ($problems[0]['message'] ?? ''));
    }

    /** A version this importer does not read is the same silence one step earlier. */
    public function testAManifestDeclaringAnotherVersionIsReported(): void
    {
        $job = $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'bulk'], '', '', '1.1'),
            [],
            'imports/1/bad-version.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertStringContainsString(
            'oneroster.version',
            implode(' ', array_map(
                static fn (array $row): string => (string) ($row['message'] ?? ''),
                $this->problems($job->id),
            )),
        );
    }

    // ── OneRoster placement ──────────────────────────────────────────────

    /**
     * `orgSourcedId` was parsed and never read, so both units were created and
     * nobody was in either: a unit-scoped org_admin saw none of the roster the
     * run had just loaded.
     */
    public function testOneRosterPlacesEachPersonInTheUnitOrgsCsvDefines(): void
    {
        $files = [
            'manifest.csv' => "propertyName,value\r\nmanifest.version,1.0\r\noneroster.version,1.2\r\n"
                . "file.orgs,bulk\r\nfile.users,bulk\r\nfile.roles,bulk\r\n",
            'orgs.csv' => "sourcedId,status,dateLastModified,name,type,identifier,parentSourcedId\r\n"
                . "school-01,,,North High,school,NH,\r\nschool-02,,,South High,school,SH,\r\n",
            'users.csv' => self::usersCsv([
                'user-1,,,true,Sam,Ling,,E-1,sam@example.test',
                'user-2,,,true,Ana,Rao,,E-2,ana@example.test',
            ]),
            'roles.csv' => self::rolesCsv([
                'role-1,,,user-1,primary,teacher,,,school-01',
                'role-2,,,user-2,primary,student,,,school-02',
            ]),
        ];

        $job = $this->loadPackage(self::zip($files), [], 'imports/1/two-schools.zip');
        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status, (string) $job->log);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_ORGS] ?? 0);

        $units = [];

        foreach (
            $this->env->database->selectAll(
                Query::from('org_units')->where('organisation_id', '=', $this->organisation)->orderBy('id')
            ) as $unit
        ) {
            $units[(int) $unit['id']] = (string) $unit['name'];
        }

        $placed = [];

        foreach (
            $this->env->database->selectAll(
                Query::from('org_memberships')->where('organisation_id', '=', $this->organisation)->orderBy('user_id')
            ) as $member
        ) {
            $placed[(int) $member['user_id']] = $units[(int) ($member['org_unit_id'] ?? 0)] ?? null;
        }

        self::assertSame([2 => 'North High', 3 => 'South High'], $placed);
    }

    /** A package that names a unit it did not define says so rather than placing nobody. */
    public function testAMembershipNamingAnOrgTheFileSetNeverDefinedIsAReferentialMiss(): void
    {
        $job = $this->loadPackage(
            self::package(['orgs' => 'bulk', 'users' => 'bulk', 'roles' => 'bulk'], self::rolesCsv([
                'role-1,,,user-1,primary,teacher,,,school-99',
            ])),
            [],
            'imports/1/missing-org.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertSame(0, $this->memberCount());
        self::assertSame(
            RowProblem::CODE_REFERENTIAL_MISS,
            $this->problems($job->id)[0]['code'] ?? '',
        );
    }

    // ── The bulk and delta wire contract ─────────────────────────────────

    /**
     * A bulk row carries neither column. A populated status used to be coerced
     * to active and a populated dateLastModified used to become the audit
     * clock of a row this build had decided to read as current.
     *
     * @dataProvider forbiddenBulkCases
     */
    public function testABulkRowCarryingADeltaColumnIsReportedRatherThanCoerced(string $row): void
    {
        $job = $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'bulk'], self::rolesCsv([$row])),
            [],
            'imports/1/bulk-delta.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertSame(0, $this->memberCount(), 'The row was not written.');
    }

    /**
     * @return list<array{string}>
     */
    public static function forbiddenBulkCases(): array
    {
        return [
            ['role-1,inactive,,user-1,primary,teacher,,,'],
            ['role-1,,2026-01-02T03:04:05.000Z,user-1,primary,teacher,,,'],
        ];
    }

    /** A delta clock nobody can parse is reported, never silently set to the run time. */
    public function testADeltaDateThatIsNotAUtcInstantIsReported(): void
    {
        $job = $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'delta'], self::rolesCsv([
                'role-1,active,not-a-date-at-all,user-1,primary,teacher,,,',
            ])),
            [],
            'imports/1/bad-date.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertSame(0, $this->memberCount());
        self::assertStringContainsString(
            'W3C',
            (string) ($this->problems($job->id)[0]['message'] ?? ''),
        );
    }

    /** A file the manifest declares present and that carries no data rows. */
    public function testAHeaderOnlyFileIsReportedRatherThanCountingAsLoaded(): void
    {
        $job = $this->loadPackage(
            self::package(
                ['users' => 'bulk', 'roles' => 'bulk'],
                "sourcedId,status,dateLastModified,userSourcedId,roleType,role,beginDate,endDate,orgSourcedId\r\n"
            ),
            [],
            'imports/1/header-only.zip'
        );

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertStringContainsString(
            'no data rows',
            implode(' ', array_map(
                static fn (array $row): string => (string) ($row['message'] ?? ''),
                $this->problems($job->id),
            )),
        );
    }

    /** A file the manifest declares present and the package does not carry. */
    public function testAFileDeclaredPresentAndAbsentFromThePackageIsReported(): void
    {
        $job = $this->loadPackage(
            self::package(['users' => 'bulk', 'roles' => 'bulk', 'enrollments' => 'bulk']),
            [],
            'imports/1/missing-file.zip'
        );

        self::assertStringContainsString(
            'file.enrollments',
            implode(' ', array_map(
                static fn (array $row): string => (string) ($row['message'] ?? ''),
                $this->problems($job->id),
            )),
        );
    }

    // ── Keys and roles ───────────────────────────────────────────────────

    /**
     * A row with no external_id is keyed on its address, which is reassignable.
     * The design says the importer derives one and says so in a warning; the
     * warning had no writer.
     */
    public function testARowKeyedOnAnAddressCarriesTheWarningTheDesignPromises(): void
    {
        $job = $this->loadCsv(self::memberCsv(['sam@example.test,Sam,Ling,,,org_member']));

        self::assertSame(1, $this->memberCount(), 'The row is still written.');

        $problems = $this->problems($job->id);
        self::assertCount(1, $problems, 'One warning for one row, and not one per stage.');
        self::assertSame(OrgMembersCsvMapper::COLUMN_EXTERNAL_ID, $problems[0]['column_name'] ?? '');
        self::assertSame(RowProblem::OUTCOME_UPDATED, $problems[0]['outcome'] ?? '');
        self::assertStringContainsString('reassignable', (string) $problems[0]['message']);
    }

    /**
     * The raw role-key shortcut returned before any rule ran, so a member CSV
     * naming org_owner wrote an ownership grant no mapping rule may name.
     */
    public function testAMemberCsvNamingARoleNoRuleMayGrantIsRefused(): void
    {
        $job = $this->loadCsv(self::memberCsv(['sam@example.test,Sam,Ling,emp-1,,org_owner']));

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status, (string) $job->log);
        self::assertSame([], $this->roleKeys(), 'No ownership was granted by a spreadsheet.');
        self::assertSame(RowProblem::CODE_FORBIDDEN_SCOPE, $this->problems($job->id)[0]['code'] ?? '');
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $options */
    private function loadCsv(
        string $contents,
        array $options = [],
        string $path = 'imports/1/members.csv',
    ): ImportJob {
        return $this->drive($contents, $options, $path, OrgMembersCsvMapper::FORMAT . ':in');
    }

    /** @param array<string, mixed> $options */
    private function loadPackage(
        string $contents,
        array $options = [],
        string $path = 'imports/1/roster.zip',
    ): ImportJob {
        return $this->drive($contents, $options, $path, OneRosterCsvMapper::FORMAT . ':in');
    }

    /** @param array<string, mixed> $options */
    private function drive(string $contents, array $options, string $path, string $importer): ImportJob
    {
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

    private function memberCount(): int
    {
        return $this->env->database->count(
            Query::from('org_memberships')->where('organisation_id', '=', $this->organisation)
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
    private static function memberCsv(array $rows): string
    {
        return "email,given_name,family_name,external_id,unit_path,role_key\r\n"
            . implode("\r\n", $rows) . "\r\n";
    }

    /** @param array<string, string> $modes */
    private static function package(
        array $modes,
        string $roles = '',
        string $users = '',
        string $version = '1.2',
    ): string {
        $lines = ['propertyName,value', 'manifest.version,1.0', 'oneroster.version,' . $version];

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
                . pack(
                    'vvvvvvVVVvvvvvVV',
                    20,
                    20,
                    0,
                    0,
                    0,
                    0,
                    $crc,
                    $size,
                    $size,
                    \strlen($name),
                    0,
                    0,
                    0,
                    0,
                    0,
                    $offset
                )
                . $name;
            $offset += \strlen($header) + $size;
        }

        return $local . $central . "\x50\x4b\x05\x06"
            . pack('vvvvVVv', 0, 0, \count($files), \count($files), \strlen($central), $offset, 0);
    }
}
