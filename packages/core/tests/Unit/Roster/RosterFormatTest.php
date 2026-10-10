<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Roster;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\PersonRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterCsv;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterFormatException;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterManifest;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterSource;
use Shondalai\VidyaLMS\Core\Provider\Roster\OneRosterCsvMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OrgMembersCsvMapper;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterRow;

/**
 * The two roster mappers, read as wire contracts.
 *
 * Every fixture here is a string literal, which is the point of the mapper
 * holding no port: a OneRoster file set out of the specification's own examples
 * is a fixture and not a zip on disk, and the whole of a format's parsing is
 * testable without a database, a storage adapter or an organisation.
 */
final class RosterFormatTest extends TestCase
{
    public function testTheTwoFreeFormatsRegisterUnderTheFormatAndDirectionKey(): void
    {
        self::assertSame('org-members-csv:in', (new OrgMembersCsvMapper())->key());
        self::assertSame('oneroster-csv-1.2:in', (new OneRosterCsvMapper())->key());
        self::assertSame(
            'oneroster-csv-1.2:out',
            RosterMapperRegistry::keyFor(OneRosterCsvMapper::FORMAT, RosterMapperRegistry::DIRECTION_OUT),
            'The Pro writer of the same format is a second key and never a duplicate.',
        );
    }

    /**
     * The conformance subset is a value the product can show, not a sentence in
     * a document nobody reads before uploading.
     */
    public function testEachMapperStatesItsConformanceSubsetInCode(): void
    {
        $oneRoster = (new OneRosterCsvMapper())->conformance();

        self::assertStringContainsString('not a certified consumer', $oneRoster->standard);
        self::assertContains('demographics', $oneRoster->ignores);
        self::assertContains('gradebook', $oneRoster->ignores);
        self::assertStringContainsString('orgs', implode(' ', $oneRoster->reads));
        self::assertStringContainsString('Reads:', $oneRoster->line());
        self::assertStringContainsString('Ignores:', $oneRoster->line());
    }

    /**
     * manifest.csv is a property and value file. An implementation that reads
     * it as a header and rows gets one row of nonsense and then guesses the
     * processing mode, which is the failure this shape exists to prevent.
     */
    public function testTheManifestIsAPropertyAndValueFileWithAModePerFile(): void
    {
        $manifest = RosterManifest::fromRows(RosterCsv::rows(self::manifest([
            'orgs' => 'bulk',
            'users' => 'bulk',
            'roles' => 'bulk',
            'enrollments' => 'delta',
            'academicSessions' => 'absent',
        ])));

        self::assertSame([], $manifest->problems);
        self::assertTrue($manifest->isBulk('users'));
        self::assertTrue($manifest->isDelta('enrollments'));
        self::assertSame(RosterManifest::MODE_ABSENT, $manifest->modeOf('academicSessions'));
        self::assertSame('Example SIS', $manifest->systemName());
        self::assertSame(['orgs', 'users', 'roles', 'enrollments'], $manifest->declaredFiles());
    }

    /**
     * A file the manifest never mentions is absent rather than bulk. Treating
     * it as authoritative would let a stray CSV in a zip rewrite a roster.
     */
    public function testAFileTheManifestDoesNotMentionDefaultsToAbsent(): void
    {
        $manifest = RosterManifest::fromRows(RosterCsv::rows(self::manifest(['users' => 'bulk'])));

        self::assertSame(RosterManifest::MODE_ABSENT, $manifest->modeOf('enrollments'));
    }

    public function testTheManifestReportsItsOwnVersionProblems(): void
    {
        $manifest = RosterManifest::fromRows(RosterCsv::rows(
            "propertyName,value\r\nmanifest.version,1.0\r\noneroster.version,1.1\r\nfile.users,sometimes\r\n"
        ));

        self::assertCount(2, $manifest->problems);
        self::assertStringContainsString('absent, bulk or delta', $manifest->problems[0]);
        self::assertStringContainsString('oneroster.version', $manifest->problems[1]);
    }

    /**
     * A BOM on the first header name is why an importer reports "there is no
     * column called sourcedId" about a file whose first column is called
     * sourcedId.
     */
    public function testALeadingByteOrderMarkIsToleratedAndIgnored(): void
    {
        $header = RosterCsv::header(RosterCsv::rows(RosterCsv::BOM . "sourcedId,name\r\n1,A\r\n")[0]);

        self::assertSame(['sourcedId' => 0, 'name' => 1], $header);
    }

    /**
     * RFC 4180 quoting, including a field that spans lines. A line-based split
     * would be shorter and would break the first time an export carried a
     * newline inside quotes.
     */
    public function testQuotedFieldsMayCarryCommasQuotesAndLineBreaks(): void
    {
        $rows = RosterCsv::rows("a,b\r\n\"one, two\",\"he said \"\"hi\"\"\"\r\n\"line\nbreak\",z\r\n");

        self::assertCount(3, $rows);
        self::assertSame(['one, two', 'he said "hi"'], $rows[1]);
        self::assertSame("line\nbreak", $rows[2][0]);
    }

    /**
     * The rule the whole free build's tenancy rests on, refused by column name
     * so the operator learns why instead of wondering where their data went.
     */
    public function testTheThreeOrganisationColumnsAreRefusedByNameBeforeAnyRowIsRead(): void
    {
        foreach (OrgMembersCsvMapper::REJECTED_COLUMNS as $column) {
            $source = RosterSource::ofFile(
                'members.csv',
                'email,given_name,' . $column . "\r\nsomebody@example.test,Sam,7\r\n"
            );

            try {
                iterator_to_array((new OrgMembersCsvMapper())->read($source, ImportLoadOrder::STAGE_PEOPLE));
                self::fail('Expected ' . $column . ' to be refused.');
            } catch (RosterFormatException $refused) {
                self::assertSame('vidyalms.roster.error.organisation_column', $refused->translationKey);
                self::assertSame($column, $refused->parameters['column']);
                self::assertSame('members.csv', $refused->parameters['file']);
            }
        }
    }

    public function testTheMemberCsvProducesAPersonAndAMembershipFromOneRow(): void
    {
        $source = RosterSource::ofFile('members.csv', implode("\r\n", [
            'email,given_name,family_name,external_id,unit_path,role_key,job_title,manager_email',
            'Sam@Example.Test,Sam,Ling,emp-001,North/Support,org_member,Analyst,lead@example.test',
            '',
        ]));
        $mapper = new OrgMembersCsvMapper();

        $people = iterator_to_array($mapper->read($source, ImportLoadOrder::STAGE_PEOPLE));
        $person = $people[0]->person();
        self::assertInstanceOf(PersonRecord::class, $person);
        self::assertSame('emp-001', $person->externalId);
        self::assertSame('sam@example.test', $person->email, 'Addresses are compared lower-cased or not at all.');
        self::assertSame('Sam Ling', $person->displayName());
        self::assertSame('Analyst', $person->attributes['job_title']);
        self::assertSame(2, $people[0]->line, 'Row 1 is the header, so the first data row is row 2.');

        $memberships = iterator_to_array($mapper->read($source, ImportLoadOrder::STAGE_MEMBERSHIPS));
        $membership = $memberships[0]->membership();
        self::assertInstanceOf(OrgMembershipRecord::class, $membership);
        self::assertSame('emp-001', $membership->personExternalId);
        self::assertSame('org_member', $membership->roleToken);
        self::assertSame('North/Support', $membership->unitPath);
        self::assertSame('lead@example.test', $membership->managerEmail);
    }

    /**
     * A source with no stable key derives one and says so, which is what
     * JsonCourseImporter already does with its content digest. The prefix is
     * what keeps a derived key from ever colliding with a real external id.
     */
    public function testAMemberRowWithNoExternalIdKeysOnTheAddressUnderADistinctPrefix(): void
    {
        $rows = iterator_to_array((new OrgMembersCsvMapper())->read(
            RosterSource::ofFile('members.csv', "email,given_name\r\nSam@Example.Test,Sam\r\n"),
            ImportLoadOrder::STAGE_PEOPLE,
        ));

        self::assertSame('email:sam@example.test', $rows[0]->person()?->externalId);
        self::assertTrue(OrgMembersCsvMapper::isDerived('email:sam@example.test'));
        self::assertFalse(OrgMembersCsvMapper::isDerived('emp-001'));
    }

    public function testAMemberRowWithNeitherAddressNorExternalIdIsARowProblemAndNotAFailedFile(): void
    {
        $rows = iterator_to_array((new OrgMembersCsvMapper())->read(
            RosterSource::ofFile('members.csv', "email,external_id,given_name\r\n,,Nobody\r\nsam@example.test,,Sam\r\n"),
            ImportLoadOrder::STAGE_PEOPLE,
        ));

        self::assertTrue($rows[0]->isRejected());
        self::assertSame(RowProblem::CODE_MISSING_REQUIRED, $rows[0]->problem?->code);
        self::assertFalse($rows[1]->isRejected(), 'One bad row does not stop the file.');
    }

    public function testTheSameKeyTwiceInOneFileIsADuplicateKeyProblem(): void
    {
        $rows = iterator_to_array((new OrgMembersCsvMapper())->read(
            RosterSource::ofFile(
                'members.csv',
                "email,external_id\r\na@example.test,emp-1\r\nb@example.test,emp-1\r\n"
            ),
            ImportLoadOrder::STAGE_PEOPLE,
        ));

        self::assertFalse($rows[0]->isRejected());
        $problem = $rows[1]->problem;
        self::assertNotNull($problem);
        self::assertSame(RowProblem::CODE_DUPLICATE_KEY, $problem->code);
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $problem->outcome);
        self::assertStringContainsString('line 2', $problem->message);
    }

    /**
     * The stages a package carries come from its manifest, and the order they
     * load in comes from ImportLoadOrder and never from the declaration.
     */
    public function testTheStagesAPackageCarriesComeFromItsManifest(): void
    {
        $mapper = new OneRosterCsvMapper();

        self::assertSame(
            [ImportLoadOrder::STAGE_UNITS, ImportLoadOrder::STAGE_PEOPLE, ImportLoadOrder::STAGE_MEMBERSHIPS],
            $mapper->stages(self::package()),
        );

        // A package whose manifest declares orgs absent has no organisations
        // stage even when the file is in the zip, because the manifest is what
        // the exporter signed.
        $withoutOrgs = RosterSource::ofPackage([
            'manifest.csv' => self::manifest(['orgs' => 'absent', 'users' => 'bulk', 'roles' => 'bulk']),
            'orgs.csv' => "sourcedId,status,dateLastModified,name,type,identifier,parentSourcedId\r\nx,,,X,school,,\r\n",
            'users.csv' => self::users(),
            'roles.csv' => self::roles(),
        ]);

        self::assertSame(
            [ImportLoadOrder::STAGE_PEOPLE, ImportLoadOrder::STAGE_MEMBERSHIPS],
            $mapper->stages($withoutOrgs),
        );
    }

    public function testOneRosterOrgsUsersAndRolesMapOntoTheCanonicalShapes(): void
    {
        $mapper = new OneRosterCsvMapper();
        $source = self::package();

        $org = self::records($mapper->read($source, ImportLoadOrder::STAGE_UNITS))[0];
        self::assertInstanceOf(OrgRecord::class, $org);
        self::assertSame('school-01', $org->externalId);
        self::assertSame('North High', $org->name);
        self::assertSame('school', $org->type);
        self::assertSame('district-01', $org->parentExternalId);

        $person = self::records($mapper->read($source, ImportLoadOrder::STAGE_PEOPLE))[0];
        self::assertInstanceOf(PersonRecord::class, $person);
        self::assertSame('user-01', $person->externalId);
        self::assertSame('sam@example.test', $person->email);

        $membership = self::records($mapper->read($source, ImportLoadOrder::STAGE_MEMBERSHIPS))[0];
        self::assertInstanceOf(OrgMembershipRecord::class, $membership);
        self::assertSame('user-01', $membership->personExternalId);
        self::assertSame('school-01', $membership->orgExternalId);
        self::assertSame('teacher', $membership->roleToken, 'The foreign token stays foreign until a mapper runs.');
        self::assertTrue($membership->primary);
    }

    /**
     * A bulk file says every row present is current, so a tombstone in one is a
     * contradiction rather than a deletion. This is the one place a mode read
     * per package rather than per file would silently delete somebody.
     */
    public function testATombstoneInABulkFileIsRefusedAndInADeltaFileIsHonoured(): void
    {
        $mapper = new OneRosterCsvMapper();
        $roles = "sourcedId,status,dateLastModified,userSourcedId,roleType,role,beginDate,endDate,orgSourcedId\r\n"
            . "role-01,tobedeleted,2026-03-03T00:00:00.000Z,user-01,primary,teacher,,,school-01\r\n";

        $bulk = iterator_to_array($mapper->read(RosterSource::ofPackage([
            'manifest.csv' => self::manifest(['users' => 'bulk', 'roles' => 'bulk']),
            'users.csv' => self::users(),
            'roles.csv' => $roles,
        ]), ImportLoadOrder::STAGE_MEMBERSHIPS));

        self::assertTrue($bulk[0]->isRejected());
        self::assertStringContainsString('declared bulk', (string) $bulk[0]->problem?->message);
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $bulk[0]->problem?->outcome);

        $delta = self::records($mapper->read(RosterSource::ofPackage([
            'manifest.csv' => self::manifest(['users' => 'bulk', 'roles' => 'delta']),
            'users.csv' => self::users(),
            'roles.csv' => $roles,
        ]), ImportLoadOrder::STAGE_MEMBERSHIPS));

        self::assertInstanceOf(OrgMembershipRecord::class, $delta[0]);
        self::assertTrue($delta[0]->isTombstone());
        self::assertSame('left', $delta[0]->membershipState());
        self::assertSame('2026-03-03T00:00:00.000Z', $delta[0]->modifiedAt, 'The first of the two clocks.');
    }

    /**
     * A delta row with no status says nothing, and guessing active would turn a
     * delta into a bulk load.
     */
    public function testADeltaRowWithoutAStatusOrAModifiedDateIsARowProblem(): void
    {
        $rows = iterator_to_array((new OneRosterCsvMapper())->read(RosterSource::ofPackage([
            'manifest.csv' => self::manifest(['users' => 'bulk', 'roles' => 'delta']),
            'users.csv' => self::users(),
            'roles.csv' => "sourcedId,status,dateLastModified,userSourcedId,roleType,role,orgSourcedId\r\n"
                . "role-01,,,user-01,primary,teacher,school-01\r\n"
                . "role-02,active,,user-01,primary,teacher,school-01\r\n",
        ]), ImportLoadOrder::STAGE_MEMBERSHIPS));

        self::assertSame('status', $rows[0]->problem?->column);
        self::assertSame('dateLastModified', $rows[1]->problem?->column);
    }

    public function testCanvasInactiveAndCompletedJoinTheStatusVocabulary(): void
    {
        self::assertTrue(OrgMembershipRecord::isKnownStatus('inactive'));
        self::assertTrue(OrgMembershipRecord::isKnownStatus('completed'));
        self::assertFalse(OrgMembershipRecord::isKnownStatus('archived'));

        self::assertSame('inactive', OrgMembershipRecord::of('r', 'p', null, 'teacher', 'inactive')->membershipState());
        self::assertSame('completed', OrgMembershipRecord::of('r', 'p', null, 'teacher', 'completed')->membershipState());
        self::assertFalse(OrgMembershipRecord::of('r', 'p', null, 'teacher', 'inactive')->isTombstone());
    }

    /**
     * A conformant package is a flat zip with no enclosing directory, and a
     * package that carries one is a package a real export tool produced anyway.
     */
    public function testAnEnclosingDirectoryIsStrippedRatherThanRefused(): void
    {
        $source = RosterSource::ofPackage(['export/2026/users.csv' => self::users()]);

        self::assertTrue($source->has('users.csv'));
        self::assertSame(['users.csv'], $source->names());
    }

    /**
     * @param iterable<RosterRow> $rows
     *
     * @return list<object>
     */
    private static function records(iterable $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
            if (!$row->isRejected()) {
                $records[] = $row->requireRecord();
            }
        }

        return $records;
    }

    /** @param array<string, string> $modes */
    private static function manifest(array $modes): string
    {
        $lines = ['propertyName,value', 'manifest.version,1.0', 'oneroster.version,1.2', 'source.systemName,Example SIS'];

        foreach ($modes as $file => $mode) {
            $lines[] = 'file.' . $file . ',' . $mode;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function package(): RosterSource
    {
        return RosterSource::ofPackage([
            'manifest.csv' => self::manifest(['orgs' => 'bulk', 'users' => 'bulk', 'roles' => 'bulk']),
            'orgs.csv' => "sourcedId,status,dateLastModified,name,type,identifier,parentSourcedId\r\n"
                . "school-01,,,North High,school,NH,district-01\r\n",
            'users.csv' => self::users(),
            'roles.csv' => self::roles(),
        ]);
    }

    private static function users(): string
    {
        return "sourcedId,status,dateLastModified,enabledUser,givenName,familyName,middleName,identifier,email\r\n"
            . "user-01,,,true,Sam,Ling,,E-1,sam@example.test\r\n";
    }

    private static function roles(): string
    {
        return "sourcedId,status,dateLastModified,userSourcedId,roleType,role,beginDate,endDate,orgSourcedId\r\n"
            . "role-01,,,user-01,primary,teacher,,,school-01\r\n";
    }
}
