<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Roster;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\PersonRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterConformance;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterCsv;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterFormatException;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterManifest;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterSource;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterRow;

/**
 * The small documented column set: one CSV, one row per person, one membership.
 *
 * It exists beside the OneRoster reader because most customers do not have a
 * student information system, they have a spreadsheet. Twelve columns, all but
 * one of them optional, and every one of them a thing an operator can see in
 * their own file.
 *
 *     email,given_name,family_name,external_id,unit_path,role_key,
 *     job_title,department_label,division,cost_centre,employee_number,manager_email
 *
 * ## The three organisation columns are rejected by name
 *
 * `organisation`, `organisation_id` and `org_slug` are refused before the first
 * data row is read, with a translated error naming the column. Ignoring them
 * would be worse than refusing: the operator would watch a file with an
 * organisation column load successfully into the wrong organisation and never
 * find out. **The organisation comes from the run context and never from the
 * file**, and this is the one place a member CSV could have argued otherwise.
 *
 * ## The key, and the warning when there is not one
 *
 * `external_id` is the idempotency key. A file without one keys on the
 * lower-cased address, prefixed so it can never be confused with a real
 * external id, and the row carries a warning saying so: an address is
 * reassignable, so a roster keyed on one will eventually update the wrong
 * person's membership when an address is reused, and the operator has to be
 * told that is the trade they made.
 *
 * ## What it does not read
 *
 * Courses, enrolments, seats, invitations by name. This file adds people to an
 * organisation and gives them a role, which is the free build's own sentence,
 * and it does nothing else on purpose: a member CSV that also enrolled people
 * would be a second, undocumented enrolment path.
 */
final class OrgMembersCsvMapper implements RosterMapperInterface
{
    public const FORMAT = 'org-members-csv';

    public const FILE = 'members.csv';

    /** The one column a row cannot do without, in the sense that nothing identifies it otherwise. */
    public const COLUMN_EMAIL = 'email';

    public const COLUMN_EXTERNAL_ID = 'external_id';

    /**
     * The whole column set, in the documented order.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'email',
        'given_name',
        'family_name',
        'external_id',
        'unit_path',
        'role_key',
        'job_title',
        'department_label',
        'division',
        'cost_centre',
        'employee_number',
        'manager_email',
    ];

    /**
     * The three names that mean an organisation, refused by name.
     *
     * @var list<string>
     */
    public const REJECTED_COLUMNS = ['organisation', 'organisation_id', 'org_slug'];

    /**
     * The directory attributes this format carries, mapped onto the canonical
     * membership attribute names the use case already accepts.
     *
     * @var array<string, string>
     */
    private const ATTRIBUTES = [
        'job_title' => 'job_title',
        'department_label' => 'department_label',
        'division' => 'division',
        'cost_centre' => 'cost_centre',
        'employee_number' => 'employee_number',
    ];

    /** The prefix a derived key carries, so it can never collide with a real external id. */
    public const DERIVED_PREFIX = 'email:';

    public function key(): string
    {
        return RosterMapperRegistry::keyFor(self::FORMAT, RosterMapperRegistry::DIRECTION_IN);
    }

    public function label(): string
    {
        return 'vidyalms.roster.org_members_csv';
    }

    public function conformance(): RosterConformance
    {
        return RosterConformance::of(
            'Organisation members CSV, a VidyaLMS format',
            ['people', 'organisation memberships', 'directory attributes', 'unit placement'],
            ['courses', 'enrolments', 'seats', 'organisations'],
            'The organisation comes from the console the load was started in. A file naming one is refused'
                . ' by column name rather than ignored.',
        );
    }

    public function supports(string $sourcePath): bool
    {
        return str_ends_with(strtolower($sourcePath), '.csv');
    }

    public function manifest(RosterSource $source): RosterManifest
    {
        // One file, one mode, and the mode is bulk in the only sense that word
        // has here: every row present is authoritative for the person it names,
        // and a person not in the file is not mentioned by it. Absence still
        // causes nothing.
        return RosterManifest::none();
    }

    public function stages(RosterSource $source): array
    {
        return [ImportLoadOrder::STAGE_PEOPLE, ImportLoadOrder::STAGE_MEMBERSHIPS];
    }

    public function read(RosterSource $source, string $stage): iterable
    {
        $file = $this->fileName($source);
        $rows = RosterCsv::rows($source->bytes($file));
        $header = self::normalisedHeader(RosterCsv::header($rows[0] ?? []));
        $this->refuseOrganisationColumns($file, $header);

        $seen = [];

        foreach ($rows as $index => $cells) {
            $line = $index + 1;

            if ($line === 1 || RosterCsv::isBlank($cells)) {
                continue;
            }

            $email = mb_strtolower(RosterCsv::value($header, $cells, self::COLUMN_EMAIL));
            $external = RosterCsv::value($header, $cells, self::COLUMN_EXTERNAL_ID);

            if ($email === '' && $external === '') {
                yield RosterRow::rejected($file, $line, RowProblem::inFile(
                    $file,
                    $line,
                    self::COLUMN_EMAIL,
                    RowProblem::CODE_MISSING_REQUIRED,
                    'A row needs an email address or an external_id. With neither there is nothing to'
                        . ' identify the person by, and nothing a second run could match against.',
                ));

                continue;
            }

            if ($email !== '' && !$this->looksLikeAddress($email)) {
                yield RosterRow::rejected($file, $line, RowProblem::inFile(
                    $file,
                    $line,
                    self::COLUMN_EMAIL,
                    RowProblem::CODE_INVALID_EMAIL,
                    'That is not an email address.',
                    $email,
                ));

                continue;
            }

            $key = $external !== '' ? $external : self::DERIVED_PREFIX . $email;

            if (isset($seen[$key])) {
                yield RosterRow::rejected($file, $line, RowProblem::inFile(
                    $file,
                    $line,
                    $external !== '' ? self::COLUMN_EXTERNAL_ID : self::COLUMN_EMAIL,
                    RowProblem::CODE_DUPLICATE_KEY,
                    sprintf(
                        'This key already appeared on line %d of the same file. One file naming one person'
                            . ' twice is two instructions about one membership, and taking the last one'
                            . ' silently is how the wrong one wins.',
                        $seen[$key],
                    ),
                    $key,
                    RowProblem::OUTCOME_SKIPPED,
                ));

                continue;
            }

            $seen[$key] = $line;
            $warning = self::isDerived($key) ? $this->derivedKeyWarning($file, $line, $key) : null;

            // The warning rides the person row and not the membership row, so
            // one file line is reported once rather than once per stage.
            yield $stage === ImportLoadOrder::STAGE_PEOPLE
                ? RosterRow::of($file, $line, $this->person($header, $cells, $key, $email), '', $warning)
                : RosterRow::of($file, $line, $this->membership($header, $cells, $key));
        }
    }

    /**
     * The header, case folded, because this format is this product's own.
     *
     * Every documented column and every refused column is lower case, and
     * `RosterCsv::header()` preserves whatever case the file used. Without this
     * an operator watched a file carrying `Organisation_ID` load successfully
     * into the console's organisation, which is verbatim the harm the rejection
     * exists to prevent, and a header spelled `Role_Key` silently discarded the
     * role so a row asking for `org_reporter` landed as an ordinary member.
     *
     * The OneRoster binding keeps its case sensitivity, which is the
     * specification's own rule and belongs to that mapper rather than to this
     * one: a file whose header says `SourcedId` is a file some other tool reads
     * differently.
     *
     * @param array<string, int> $header
     *
     * @return array<string, int>
     */
    private static function normalisedHeader(array $header): array
    {
        $normalised = [];

        foreach ($header as $name => $at) {
            $name = mb_strtolower($name);

            // First occurrence wins, so a file carrying both `email` and
            // `Email` reads the one the documented order puts first rather
            // than whichever happened to be last.
            if (!isset($normalised[$name])) {
                $normalised[$name] = $at;
            }
        }

        return $normalised;
    }

    /**
     * The warning a row keyed on an address carries, which it always carries.
     *
     * The design's rule is that a source key is never an email address, because
     * an address is reassignable, and that where a source genuinely has no
     * stable key the importer derives one **and says so in a warning**. Without
     * the warning the trade is invisible: a changed address creates a second
     * person and a recycled address inherits the previous person's membership,
     * and neither run says anything.
     */
    private function derivedKeyWarning(string $file, int $line, string $key): RowProblem
    {
        return RowProblem::inFile(
            $file,
            $line,
            self::COLUMN_EXTERNAL_ID,
            RowProblem::CODE_MISSING_REQUIRED,
            'This row carries no external_id, so it is matched on its email address instead. An address is'
                . ' reassignable: if this person changes address a second person is created, and if the'
                . ' address is later given to somebody else they inherit this membership.',
            $key,
            RowProblem::OUTCOME_UPDATED,
        );
    }

    /** Whether a derived key was minted for this person rather than read from the file. */
    public static function isDerived(string $externalId): bool
    {
        return str_starts_with($externalId, self::DERIVED_PREFIX);
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function person(array $header, array $cells, string $key, string $email): PersonRecord
    {
        $attributes = [];

        foreach (self::ATTRIBUTES as $column => $attribute) {
            $attributes[$attribute] = RosterCsv::value($header, $cells, $column);
        }

        return PersonRecord::of(
            $key,
            $email === '' ? null : $email,
            RosterCsv::value($header, $cells, 'given_name'),
            RosterCsv::value($header, $cells, 'family_name'),
            true,
            $attributes,
        );
    }

    /**
     * @param array<string, int> $header
     * @param list<string>       $cells
     */
    private function membership(array $header, array $cells, string $key): OrgMembershipRecord
    {
        return OrgMembershipRecord::of(
            $key,
            $key,
            null,
            RosterCsv::value($header, $cells, 'role_key') ?: null,
            OrgMembershipRecord::STATUS_ACTIVE,
            false,
            null,
            null,
            null,
            mb_strtolower(RosterCsv::value($header, $cells, 'manager_email')) ?: null,
            RosterCsv::value($header, $cells, 'unit_path') ?: null,
        );
    }

    /**
     * @param array<string, int> $header
     *
     * @throws RosterFormatException on any of the three organisation columns
     */
    private function refuseOrganisationColumns(string $file, array $header): void
    {
        foreach (self::REJECTED_COLUMNS as $column) {
            if (isset($header[$column])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw RosterFormatException::organisationColumn($file, $column);
            }
        }
    }

    private function fileName(RosterSource $source): string
    {
        $names = $source->names();

        return $names[0] ?? self::FILE;
    }

    /**
     * Deliberately a shape check and not a validator.
     *
     * The address is a hint used to find a host account, and the account is
     * what a membership is written against. A row whose address does not resolve
     * to an account is refused later, by the writer, with a reason of its own;
     * refusing here on a regular expression's opinion of RFC 5322 would reject
     * addresses that exist.
     */
    private function looksLikeAddress(string $email): bool
    {
        $at = strpos($email, '@');

        return $at !== false && $at > 0 && str_contains(substr($email, $at + 1), '.')
            && !str_contains($email, ' ');
    }
}
