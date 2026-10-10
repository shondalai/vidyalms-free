<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Roster;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Roster\CourseAssignmentRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\PersonRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterConformance;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterCsv;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterManifest;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterSource;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterRow;

/**
 * The OneRoster 1.2 CSV binding, read as a wire contract rather than as a
 * column list.
 *
 * The file set is `academicSessions.csv`, `classes.csv`, `courses.csv`,
 * `enrollments.csv`, `orgs.csv`, `roles.csv`, `users.csv` and a mandatory
 * `manifest.csv`, exchanged as a flat zip with no enclosing directory.
 *
 * ## `manifest.csv` decides how each file is read
 *
 * It is a property and value file, so `file.users = bulk` and
 * `file.enrollments = delta` in the same package is legitimate and **the runner
 * honours it per file rather than per package**. The two modes are different
 * contracts over the same columns:
 *
 * - **bulk**: every present row is authoritative, and `status` and
 *   `dateLastModified` must not carry values. A `tobedeleted` in a bulk file is
 *   a contradiction (the file says every row present is current), so it is a
 *   row problem and never a deletion.
 * - **delta**: both must be populated and `status` is `active` or
 *   `tobedeleted`. An empty status in a delta file is a row that says nothing,
 *   which is a row problem rather than an assumed `active`.
 *
 * `dateLastModified` in a delta is where the first of the two clocks comes
 * from: a row modified last Tuesday **occurred** last Tuesday and is
 * **recorded** today, and an event stream that cannot tell those apart cannot
 * answer an audit.
 *
 * ## The subset, stated rather than discovered
 *
 * Reads `orgs`, `users`, `roles` and `enrollments`. Ignores demographics,
 * resources, the gradebook, and `courses.csv` beyond title matching.
 * `classes.csv` and `academicSessions.csv` are read only far enough to resolve
 * an enrolment's class, and an enrolment is reported and not written, because
 * whether a OneRoster class is a course, a cohort or a course inside a cohort
 * is a decision this wave defers. **Conformance is a stated non-goal**, and
 * saying which subset is read is what stops an implementation half-implementing
 * the rest of the standard on the way past.
 *
 * ## What an `orgs.csv` row becomes
 *
 * A unit inside the organisation the run context names, and never an
 * organisation. The organisation comes from the console the load was started
 * in; a roster load that could create organisations would be a roster load that
 * could create tenants.
 */
final class OneRosterCsvMapper implements RosterMapperInterface
{
    public const FORMAT = 'oneroster-csv-1.2';

    public const FILE_MANIFEST = RosterManifest::FILE_NAME;
    public const FILE_ORGS = 'orgs.csv';
    public const FILE_USERS = 'users.csv';
    public const FILE_ROLES = 'roles.csv';
    public const FILE_ENROLMENTS = 'enrollments.csv';
    public const FILE_CLASSES = 'classes.csv';
    public const FILE_COURSES = 'courses.csv';
    public const FILE_SESSIONS = 'academicSessions.csv';

    /**
     * The whole file set the binding names, so a package carrying something
     * else can be told which of its files were read.
     *
     * @var list<string>
     */
    public const FILES = [
        self::FILE_MANIFEST,
        self::FILE_ORGS,
        self::FILE_USERS,
        self::FILE_ROLES,
        self::FILE_ENROLMENTS,
        self::FILE_CLASSES,
        self::FILE_COURSES,
        self::FILE_SESSIONS,
    ];

    /**
     * Which stage each file feeds, for the files this mapper reads.
     *
     * @var array<string, string>
     */
    private const STAGES = [
        self::FILE_ORGS => ImportLoadOrder::STAGE_UNITS,
        self::FILE_USERS => ImportLoadOrder::STAGE_PEOPLE,
        self::FILE_ROLES => ImportLoadOrder::STAGE_MEMBERSHIPS,
        self::FILE_ENROLMENTS => ImportLoadOrder::STAGE_RELEASES,
    ];

    public function key(): string
    {
        return RosterMapperRegistry::keyFor(self::FORMAT, RosterMapperRegistry::DIRECTION_IN);
    }

    public function label(): string
    {
        return 'vidyalms.roster.oneroster_csv_1_2';
    }

    public function conformance(): RosterConformance
    {
        return RosterConformance::of(
            'OneRoster 1.2 CSV, a stated subset and not a certified consumer',
            ['orgs', 'users', 'roles', 'enrollments (read and reported, never written)'],
            ['demographics', 'resources', 'gradebook', 'courses beyond title matching', 'academicSessions'],
            'Certification is a separate decision. A class is not yet mapped onto a course or a cohort, so'
                . ' enrolments are counted and reported rather than written.',
        );
    }

    public function supports(string $sourcePath): bool
    {
        return str_ends_with(strtolower($sourcePath), '.zip');
    }

    public function manifest(RosterSource $source): RosterManifest
    {
        return RosterManifest::fromRows(RosterCsv::rows($source->bytes(self::FILE_MANIFEST)));
    }

    public function stages(RosterSource $source): array
    {
        $manifest = $this->manifest($source);
        $stages = [];

        foreach (self::STAGES as $file => $stage) {
            // The manifest and the package have to agree. A file present in the
            // zip but declared absent is not read, because the manifest is what
            // the exporter signed and a stray CSV in a zip must not rewrite a
            // roster.
            if ($source->has($file) && $manifest->modeOf($this->shortName($file)) !== RosterManifest::MODE_ABSENT) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    public function read(RosterSource $source, string $stage): iterable
    {
        $file = array_search($stage, self::STAGES, true);

        if (!\is_string($file) || !$source->has($file)) {
            return;
        }

        $mode = $this->manifest($source)->modeOf($this->shortName($file));
        $rows = RosterCsv::rows($source->bytes($file));
        $header = RosterCsv::header($rows[0] ?? []);
        $seen = [];
        $data = 0;

        foreach ($rows as $index => $cells) {
            $line = $index + 1;

            if ($line === 1 || RosterCsv::isBlank($cells)) {
                continue;
            }

            $data++;

            $sourcedId = RosterCsv::value($header, $cells, 'sourcedId');

            if ($sourcedId === '') {
                yield RosterRow::rejected($file, $line, RowProblem::inFile(
                    $file,
                    $line,
                    'sourcedId',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'Every OneRoster object carries a sourcedId, permanently allocated and never'
                        . ' reallocated. A row without one cannot be matched on a second run.',
                ), $mode);

                continue;
            }

            if (isset($seen[$sourcedId])) {
                yield RosterRow::rejected($file, $line, RowProblem::inFile(
                    $file,
                    $line,
                    'sourcedId',
                    RowProblem::CODE_DUPLICATE_KEY,
                    sprintf('This sourcedId already appeared on line %d of the same file.', $seen[$sourcedId]),
                    $sourcedId,
                    RowProblem::OUTCOME_SKIPPED,
                ), $mode);

                continue;
            }

            $seen[$sourcedId] = $line;
            $status = strtolower(RosterCsv::value($header, $cells, 'status'));
            $modified = RosterCsv::value($header, $cells, 'dateLastModified');
            $refusal = $this->statusProblem($file, $line, $mode, $status, $modified);

            if ($refusal !== null) {
                yield RosterRow::rejected($file, $line, $refusal, $mode);

                continue;
            }

            $status = $mode === RosterManifest::MODE_BULK ? OrgMembershipRecord::STATUS_ACTIVE : $status;

            yield RosterRow::of($file, $line, match ($file) {
                self::FILE_ORGS => $this->org($header, $cells, $sourcedId),
                self::FILE_USERS => $this->user($header, $cells, $sourcedId),
                self::FILE_ROLES => $this->role($header, $cells, $sourcedId, $status, $modified),
                default => $this->enrolment($header, $cells, $sourcedId, $status),
            }, $mode);
        }

        if ($data === 0) {
            // "A file with no data rows is not permitted: it is declared absent
            // in the manifest and omitted." A header-only file used to load
            // clean and contribute nothing, and the operator was left reading
            // the referential misses it caused in the files that referenced it.
            yield RosterRow::rejected($file, RowProblem::FIRST_DATA_ROW, RowProblem::inFile(
                $file,
                RowProblem::FIRST_DATA_ROW,
                null,
                RowProblem::CODE_MISSING_REQUIRED,
                'This file is in the package and declares a processing mode, and it carries no data rows. A'
                    . ' file with none is declared absent in the manifest and left out of the package.',
                $file,
                RowProblem::OUTCOME_SKIPPED,
            ), $mode);
        }
    }

    /**
     * The bulk-versus-delta contract, applied per file because the manifest
     * declares it per file.
     */
    private function statusProblem(
        string $file,
        int $line,
        string $mode,
        string $status,
        string $modified,
    ): ?RowProblem {
        if ($mode === RosterManifest::MODE_DELTA) {
            if ($status === '') {
                return RowProblem::inFile(
                    $file,
                    $line,
                    'status',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'This file is declared delta in the manifest, so every row states whether it is active'
                        . ' or tobedeleted. A row with an empty status says nothing, and guessing active'
                        . ' would turn a delta into a bulk load.',
                );
            }

            if (!OrgMembershipRecord::isKnownStatus($status)) {
                return RowProblem::inFile(
                    $file,
                    $line,
                    'status',
                    RowProblem::CODE_MISSING_REQUIRED,
                    sprintf(
                        'A delta row is active or tobedeleted, and this build also accepts inactive and'
                            . ' completed. "%s" is none of them.',
                        $status,
                    ),
                    $status,
                );
            }

            if ($modified === '') {
                return RowProblem::inFile(
                    $file,
                    $line,
                    'dateLastModified',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'A delta row carries a dateLastModified. It is what says when the change happened,'
                        . ' which is not the same as when this import ran.',
                );
            }

            if (!self::isUtcInstant($modified)) {
                // It becomes org_membership_events.occurred_at, which is the
                // first of the two clocks and the column an audit reads. A
                // value the importer cannot parse used to be dropped in
                // silence, leaving occurred_at equal to the run time on a row
                // whose whole point was that it happened earlier.
                return RowProblem::inFile(
                    $file,
                    $line,
                    'dateLastModified',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'dateLastModified is written in the W3C ISO 8601 UTC profile, as 2026-03-03T09:00:00.000Z.'
                        . ' This value is not, and it is what the membership event records as the moment the'
                        . ' change happened.',
                    $modified,
                );
            }

            return null;
        }

        if ($status === OrgMembershipRecord::STATUS_TO_BE_DELETED) {
            return RowProblem::inFile(
                $file,
                $line,
                'status',
                RowProblem::CODE_MISSING_REQUIRED,
                'This file is declared bulk in the manifest, which means every row present in it is'
                    . ' authoritative and current. A tombstone contradicts that, so it was not honoured:'
                    . ' declare the file delta if it carries removals.',
                $status,
                RowProblem::OUTCOME_SKIPPED,
            );
        }

        // The other half of the same wire contract, which used to be coerced
        // rather than reported. In a bulk file both columns must be empty, so a
        // populated one means the exporter wrote a delta row into a file it
        // declared bulk. Coercing the status to active and carrying the date
        // through made a forbidden value the audit clock of a row this build
        // had decided to read as current.
        foreach (['status' => $status, 'dateLastModified' => $modified] as $column => $value) {
            if ($value === '') {
                continue;
            }

            return RowProblem::inFile(
                $file,
                $line,
                $column,
                RowProblem::CODE_MISSING_REQUIRED,
                sprintf(
                    'This file is declared bulk in the manifest, and a bulk row carries neither a status nor a'
                        . ' dateLastModified: every row present in it is authoritative and current. "%s" was'
                        . ' not read, because reading half of a delta row out of a bulk file is how a'
                        . ' forbidden value becomes the clock an audit answers from.',
                    $column,
                ),
                $value,
                RowProblem::OUTCOME_SKIPPED,
            );
        }

        return null;
    }

    /**
     * The W3C ISO 8601 UTC profile, which is what the binding fixes
     * dateLastModified to: a date, a T, a time, an optional fraction and a Z.
     */
    private static function isUtcInstant(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/', $value) === 1;
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function org(array $header, array $cells, string $sourcedId): OrgRecord
    {
        return OrgRecord::of(
            $sourcedId,
            RosterCsv::value($header, $cells, 'name'),
            RosterCsv::value($header, $cells, 'type') ?: null,
            RosterCsv::value($header, $cells, 'parentSourcedId') ?: null,
            RosterCsv::value($header, $cells, 'identifier') ?: null,
        );
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function user(array $header, array $cells, string $sourcedId): PersonRecord
    {
        // enabledUser is OneRoster's own word for an account that may sign in.
        // It is carried rather than acted on: an account nobody may sign into
        // is still a person on a roster, and disabling a host account is the
        // host's business and never this product's.
        $enabled = RosterCsv::value($header, $cells, 'enabledUser');

        return PersonRecord::of(
            $sourcedId,
            RosterCsv::value($header, $cells, 'email') ?: null,
            RosterCsv::value($header, $cells, 'givenName') ?: null,
            RosterCsv::value($header, $cells, 'familyName') ?: null,
            $enabled === '' || RosterCsv::truth($enabled),
            [
                'employee_number' => RosterCsv::value($header, $cells, 'identifier') ?: null,
            ],
        );
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function role(
        array $header,
        array $cells,
        string $sourcedId,
        string $status,
        string $modified,
    ): OrgMembershipRecord {
        return OrgMembershipRecord::of(
            $sourcedId,
            RosterCsv::value($header, $cells, 'userSourcedId'),
            RosterCsv::value($header, $cells, 'orgSourcedId') ?: null,
            RosterCsv::value($header, $cells, 'role') ?: null,
            $status,
            strtolower(RosterCsv::value($header, $cells, 'roleType')) === 'primary',
            RosterCsv::value($header, $cells, 'beginDate') ?: null,
            RosterCsv::value($header, $cells, 'endDate') ?: null,
            $modified ?: null,
        );
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function enrolment(
        array $header,
        array $cells,
        string $sourcedId,
        string $status,
    ): CourseAssignmentRecord {
        return CourseAssignmentRecord::of(
            $sourcedId,
            RosterCsv::value($header, $cells, 'userSourcedId'),
            RosterCsv::value($header, $cells, 'classSourcedId'),
            RosterCsv::value($header, $cells, 'schoolSourcedId') ?: null,
            RosterCsv::value($header, $cells, 'role') ?: null,
            $status,
            RosterCsv::truth(RosterCsv::value($header, $cells, 'primary')),
            RosterCsv::value($header, $cells, 'beginDate') ?: null,
            RosterCsv::value($header, $cells, 'endDate') ?: null,
        );
    }

    /** The manifest names files without their extension: `file.orgs`, not `file.orgs.csv`. */
    private function shortName(string $file): string
    {
        return substr($file, 0, -4);
    }
}
