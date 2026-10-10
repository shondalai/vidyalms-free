<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;

/**
 * R4's import surface, driven over the shipped HTTP routes against a real site
 * and a real database.
 *
 * One body of assertions, two callers, exactly as OrganisationSeatContract and
 * DatabasePortContract are run: each free adapter's build/smoke.php runs it
 * against its own ports. That matters here because almost everything this wave
 * added is a row written on one side of a tenancy predicate and read back on
 * the other, and the two hosts write and read those rows through different
 * drivers.
 *
 * What it proves that a unit test cannot:
 *
 *   - the six organisation import routes are served by the booted kernel and
 *     answer under the organisation's own path, on a real MySQL;
 *   - a real run that meets one malformed row finishes as
 *     completed_with_problems rather than failed, so the thirty-nine rows that
 *     worked are kept;
 *   - the row problems land in import_row_problems and page back through the
 *     organisation's own route, keyed to the job, with the operator's own
 *     one-based spreadsheet line;
 *   - the failed-rows download neutralises every cell it echoes, on the real
 *     driver's round trip through a MEDIUMTEXT column and back;
 *   - the dry pass writes nothing: same route, same file, same importer, and
 *     not one durable row;
 *   - a job that belongs to no organisation is still served by the site-scoped
 *     list, which is what every row both development sites already carry is;
 *   - the nine registered importers include R4's six format importers, each
 *     declaring its options and each refusing honestly on a host where its
 *     source product is not installed.
 *
 * **It seeds its own organisation, its own source file and its own importer,
 * and removes every row it wrote.** The removal runs in a finally, so a failed
 * assertion still leaves the site as it was found. It creates no table and
 * drops none, and it never names a Community Quiz table.
 *
 * The one thing it registers rather than finds is the importer that produces
 * the malformed row. The two roster importers that would be the natural
 * subject are R5's, and no shipped importer reads a CSV, so a throwaway
 * provider stands in for them: it is registered into the booted registry for
 * the length of the run, and everything downstream of it, the runner, the
 * table, the tenancy predicate, the routes and the download, is the shipped
 * code. Registering it after the Import screen section has counted the
 * registry is deliberate.
 */
final class OrganisationImportContract
{
    /** Everything this contract creates carries it, so cleanup can be exact. */
    public const MARKER = 'vidyalms-import-contract';

    /** One key, named once, on the provider that answers to it. */
    private const IMPORTER_KEY = SmokeOrganisationRosterImporter::KEY;

    /**
     * The uploaded source, under the uploading user's own folder.
     *
     * That prefix is the ownership record the private store does not keep, and
     * OrganisationImports refuses a source outside it: an organisation import
     * reads a file its own people uploaded, and both hosts' Import screens
     * write an upload to "imports/{uploader}/...".
     */
    private const SOURCE_FOLDER = 'imports';

    private const SOURCE_NAME = 'vidyalms-import-contract-roster.csv';

    private string $source = '';

    /**
     * Four rows and a header, and every one of them is load bearing.
     *
     * Row 1 is the header, so the first data row is row 2 and an operator
     * counting lines in their own spreadsheet finds the same number the
     * problems page shows. Row 3 is the malformed one, and its address is the
     * hostile cell: it opens with a full-width equals sign, which is what a
     * spreadsheet treats as a formula and what CellSafetyPolicy exists to
     * neutralise. It is malformed and hostile at once on purpose, because the
     * only cells the failed-rows download echoes are the ones that failed.
     */
    private const CSV = "sourced_id,email,role\n"
        . "person-01,ada@example.test,org_member\n"
        . "person-02,\u{FF1D}HYPERLINK(\"http://evil.test\"),org_member\n"
        . "person-03,grace@example.test,org_member\n";

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<int> */
    private array $jobIds = [];

    private int $organisationId = 0;

    private int $accessLogAtStart = 0;

    private int $accessLogAtEnd = 0;

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
    ) {
    }

    /**
     * @return array{failures: list<string>, facts: list<string>, access_log_rows_added: int}
     */
    public static function run(CoreKernel $kernel, Ports $ports, Principal $actor): array
    {
        $contract = new self($kernel, $ports, $actor);

        try {
            $contract->seed();
            $contract->checkDryPassWritesNothing();
            $contract->checkARealRunFinishesWithProblems();
            $contract->checkTheProblemsPage();
            $contract->checkTheFailedRowsDownload();
            $contract->checkTheSiteListStillServesJobsWithNoOrganisation();
            $contract->checkTheFormatImportersRefuseHonestly();
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEverythingItWrote();
        }

        return [
            'failures' => $contract->failures,
            'facts' => $contract->facts,
            'access_log_rows_added' => max(0, $contract->accessLogAtEnd - $contract->accessLogAtStart),
        ];
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    private function seed(): void
    {
        $this->accessLogAtStart = $this->accessLogRows();

        $created = $this->call('POST', '/manage/organisations', [
            'name' => 'Import contract',
            'slug' => self::MARKER,
        ], 201);
        $this->organisationId = (int) ($created['id'] ?? 0);

        $this->source = sprintf(
            '%s/%d/%s',
            self::SOURCE_FOLDER,
            max(0, $this->actor->userId),
            self::SOURCE_NAME,
        );
        $this->ports->storage->putPrivate($this->source, self::CSV);

        $this->kernel->registries->importers->register(
            new SmokeOrganisationRosterImporter($this->kernel->importMap, $this->ports->storage),
        );

        $this->facts[] = 'organisation ' . $this->organisationId
            . ', source ' . $this->source . ', importer ' . self::IMPORTER_KEY;
    }

    /**
     * The dry pass, over the same route, the same file and the same importer.
     *
     * A preview is answered in the request rather than queued, because it
     * exists to be read now. What it must not do is leave anything behind,
     * and the importer this contract registers writes exactly one durable
     * thing per good row, an import_map entry, so "nothing" is countable
     * rather than asserted.
     */
    private function checkDryPassWritesNothing(): void
    {
        $before = $this->mapRows();

        $preview = $this->call('POST', '/manage/organisations/' . $this->organisationId . '/imports', [
            'importer' => self::IMPORTER_KEY,
            'source' => $this->source,
            'dry_run' => true,
            // A caller naming a different organisation in the options bag,
            // which is the only part of the body an importer ever sees. The
            // stamp comes from the path, never from the file or the options.
            'options' => ['organisation_id' => $this->organisationId + 100000],
        ], 200);

        $this->rememberJob($preview);

        $this->expect(
            ($preview['status'] ?? null) === ImportJob::STATUS_PREVIEWED,
            'a dry run is answered now, as previewed',
            'status ' . (string) ($preview['status'] ?? 'null'),
        );
        $this->expect(
            (int) ($preview['organisation_id'] ?? 0) === $this->organisationId,
            'the organisation is stamped from the path and not from the payload',
            'organisation_id ' . (string) ($preview['organisation_id'] ?? 'null'),
        );
        $this->expect(
            $this->mapRows() === $before,
            'the dry pass wrote nothing durable',
            $before . ' import_map row(s) before, ' . $this->mapRows() . ' after',
        );
    }

    /**
     * The real run: queued, drained, and finished with its problems reported
     * rather than its work thrown away.
     */
    private function checkARealRunFinishesWithProblems(): void
    {
        // 202, because a real run has not happened yet: a forty thousand row
        // roster would hold the request open past every timeout the host has.
        $queued = $this->call('POST', '/manage/organisations/' . $this->organisationId . '/imports', [
            'importer' => self::IMPORTER_KEY,
            'source' => $this->source,
        ], 202);
        $jobId = $this->rememberJob($queued);

        $this->expect(
            ($queued['status'] ?? null) === ImportJob::STATUS_PENDING,
            'a real run is queued rather than answered in the request',
            'status ' . (string) ($queued['status'] ?? 'null'),
        );

        // What the host drain does when it reaches the row, without waiting
        // for the host drain: the same use case, on the same job id.
        $finished = $this->kernel->runQueuedImport->execute($jobId);

        $this->expect(
            $finished->status === ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            'one malformed row finishes the job with problems rather than failing it',
            'status ' . $finished->status,
        );
        $this->expect(
            ((int) ($finished->totals['created'] ?? 0)) === 2 && ((int) ($finished->totals['failed'] ?? 0)) === 1,
            'the rows that worked are kept and the one that did not is counted apart',
            (string) json_encode($finished->totals),
        );

        $read = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/imports/' . $jobId,
            [],
            200,
        );

        $this->expect(
            ($read['status'] ?? null) === ImportJob::STATUS_COMPLETED_WITH_PROBLEMS
            && str_contains((string) ($read['log'] ?? ''), '1 failed'),
            'the organisation reads its own job back with one summary line per file',
            substr((string) ($read['log'] ?? ''), 0, 200),
        );
    }

    /** The paged row report, under the organisation's own path. */
    private function checkTheProblemsPage(): void
    {
        $jobId = $this->jobIds[\count($this->jobIds) - 1] ?? 0;

        $page = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/imports/' . $jobId . '/problems',
            [],
            200,
        );

        /** @var list<array<string, mixed>> $items */
        $items = \is_array($page['items'] ?? null) ? $page['items'] : [];
        $first = $items[0] ?? [];

        $this->expect(
            (int) ($page['total'] ?? 0) === 1,
            'the one bad row is one row in import_row_problems, not a rewritten log',
            'total ' . (string) ($page['total'] ?? 'null'),
        );
        $this->expect(
            (int) ($first['row'] ?? 0) === 3,
            'the row number is the operator\'s own, counting the header as row 1',
            'row ' . (string) ($first['row'] ?? 'null'),
        );
        $this->expect(
            ($first['code'] ?? null) === RowProblem::CODE_INVALID_EMAIL
            && ($first['column'] ?? $first['column_name'] ?? null) === 'email',
            'the problem names its code and its column',
            (string) json_encode($first),
        );
    }

    /**
     * The download, and the cell that would otherwise run.
     *
     * The excerpt is neutralised once, when the problem is made, and the
     * writer neutralises the other cells; both halves go through the real
     * driver on the way to this assertion, because the excerpt is stored and
     * read back rather than held in memory.
     */
    private function checkTheFailedRowsDownload(): void
    {
        $jobId = $this->jobIds[\count($this->jobIds) - 1] ?? 0;

        $csv = $this->raw(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/imports/' . $jobId . '/failed-rows',
        );

        $this->expect(
            str_contains($csv, "'\u{FF1D}HYPERLINK"),
            'the hostile cell arrives prefixed, so a spreadsheet reads it as text',
            'the neutralising prefix is ' . ($csv === '' ? 'unreadable: empty body' : 'absent'),
        );
        $this->expect(
            !preg_match('/(^|,|")\x{FF1D}HYPERLINK/u', $csv),
            'no cell in the file opens with a formula trigger',
            substr($csv, 0, 200),
        );
        $this->expect(
            str_contains($csv, 'invalid_email') && !str_contains($csv, 'person-01'),
            'only the rows the operator has to fix are in the file',
            substr($csv, 0, 200),
        );
    }

    /**
     * The rows both development sites already carry.
     *
     * organisation_id is nullable and a null there means the job belongs to
     * the site, which is what every import written before this wave was. The
     * site-scoped list has to keep serving them, and the organisation's list
     * has to not.
     */
    private function checkTheSiteListStillServesJobsWithNoOrganisation(): void
    {
        $siteJob = $this->call('POST', '/import', [
            'importer' => self::IMPORTER_KEY,
            'source' => $this->source,
            'dry_run' => true,
        ], 200);
        $siteJobId = $this->rememberJob($siteJob);

        // array_key_exists rather than ??, because null is the answer being
        // asserted and ?? cannot tell it from an absent key.
        $this->expect(
            \array_key_exists('organisation_id', $siteJob) && $siteJob['organisation_id'] === null,
            'a site import belongs to no organisation',
            'organisation_id ' . json_encode($siteJob['organisation_id'] ?? 'absent'),
        );

        $siteList = $this->call('GET', '/import', [], 200);
        $orgList = $this->call('GET', '/manage/organisations/' . $this->organisationId . '/imports', [], 200);

        $this->expect(
            $this->listHas($siteList, $siteJobId),
            'the site-scoped list still serves a job with a null organisation',
            'job ' . $siteJobId . ' is not in the site list',
        );
        $this->expect(
            !$this->listHas($orgList, $siteJobId),
            'the organisation\'s list does not serve the site\'s job',
            'job ' . $siteJobId . ' leaked into the organisation list',
        );
    }

    /**
     * R4's six format importers, on a host where none of their products is
     * installed, which is every host there is today.
     *
     * The claim is not that they work. It is the one the flag on each label
     * makes: they are registered, they declare their options, and they refuse
     * by name rather than half loading a migration out of a table that is not
     * there. Both hosts run this: three of the six read WordPress products
     * and three read Joomla ones, and all six are absent on both.
     */
    private function checkTheFormatImportersRefuseHonestly(): void
    {
        $registry = $this->kernel->registries->importers;
        $absent = [];
        $offered = [];
        $undeclared = [];
        $unflagged = [];
        $untranslated = [];

        // Each importer's own scheme, because supports() answers on the scheme
        // and on the product's tables both: handed the wrong scheme it would
        // answer false for the wrong reason and prove nothing. The three
        // Joomla-source importers share cms-db: with the Community Quiz
        // importer and tell themselves apart by probing.
        $schemes = [
            'learndash' => 'learndash-db:',
            'tutor-lms' => 'tutor-db:',
            'lifterlms' => 'lifterlms-db:',
            'shika' => 'cms-db:',
            'guru' => 'cms-db:',
            'oscampus' => 'cms-db:',
        ];

        foreach ($schemes as $key => $scheme) {
            if (!$registry->has($key)) {
                $absent[] = $key;

                continue;
            }

            $importer = $registry->get($key);

            if ($importer->supports($scheme)) {
                $offered[] = $key;
            }

            if (!$importer instanceof ConfigurableImporterInterface || $importer->importOptions() === []) {
                $undeclared[] = $key;
            }

            // A label is a catalogue key resolved by the host's translator.
            // On a site the catalogue is loaded and the resolved sentence
            // carries the flag; a harness running without one gets the key
            // back, and reporting that as an unflagged importer would be
            // reporting the translator. It is recorded as a fact instead, and
            // the catalogue checks are what hold the strings themselves.
            $label = $importer->label();

            if (str_starts_with($label, 'vidyalms.') && !str_contains($label, ' ')) {
                $untranslated[] = $key;
            } elseif (!str_contains(strtolower($label), 'unverified')) {
                $unflagged[] = $key;
            }
        }

        $this->expect($absent === [], 'all six format importers are registered', 'missing: ' . implode(', ', $absent));
        $this->expect(
            $offered === [],
            'not one of them offers itself on a host where its product is not installed',
            'offered anyway: ' . implode(', ', $offered),
        );
        $this->expect(
            $undeclared === [],
            'each declares its options rather than hiding them in a screen',
            'declared nothing: ' . implode(', ', $undeclared),
        );
        $this->expect(
            $unflagged === [],
            'each says in its own label that it is unverified against a real install',
            'unflagged: ' . implode(', ', $unflagged),
        );

        if ($untranslated !== []) {
            $this->facts[] = 'no catalogue on this runner, so the label flag was not read for: '
                . implode(', ', $untranslated);
        }

        // An explicitly named unavailable source is refused before a job is queued.
        $named = $registry->has('shika') ? 'shika' : ($registry->has('learndash') ? 'learndash' : '');

        if ($named === '') {
            return;
        }

        $refused = $this->call('POST', '/import', [
            'importer' => $named,
            'source' => $schemes[$named],
        ], 422);
    }

    // -----------------------------------------------------------------------
    // Mechanics
    // -----------------------------------------------------------------------

    /**
     * Every row this contract wrote.
     *
     * admin_access_log is deliberately not on the list, for the reason it is
     * not on OrganisationSeatContract's: it is a hash-chained append-only
     * audit over the whole site, and deleting the rows this run caused would
     * break the link of every row written after them. Its growth is reported
     * instead, which is also what the wave has to record.
     */
    private function removeEverythingItWrote(): void
    {
        $database = $this->ports->database;

        foreach ($this->jobIds as $jobId) {
            try {
                $database->delete('import_row_problems', ['import_job_id' => $jobId]);
                $database->delete('import_jobs', ['id' => $jobId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove import job ' . $jobId . ': ' . $failure->getMessage();
            }
        }

        try {
            $database->delete('import_map', ['importer' => self::IMPORTER_KEY]);
        } catch (\Throwable $failure) {
            $this->failures[] = 'could not remove the import map rows: ' . $failure->getMessage();
        }

        if ($this->organisationId > 0) {
            foreach (['org_membership_events', 'org_memberships', 'organisations'] as $table) {
                try {
                    $database->delete($table, $table === 'organisations'
                        ? ['id' => $this->organisationId]
                        : ['organisation_id' => $this->organisationId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }
        }

        try {
            if ($this->ports->storage->exists($this->source)) {
                $this->ports->storage->delete($this->source);
            }
        } catch (\Throwable $failure) {
            $this->failures[] = 'could not remove the source file: ' . $failure->getMessage();
        }

        $this->accessLogAtEnd = $this->accessLogRows();
    }

    private function accessLogRows(): int
    {
        try {
            return $this->ports->database->count(Query::from('admin_access_log'));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function mapRows(): int
    {
        return $this->ports->database->count(
            Query::from('import_map')->where('importer', '=', self::IMPORTER_KEY),
        );
    }

    /** @param array<string, mixed> $job */
    private function rememberJob(array $job): int
    {
        $id = (int) ($job['id'] ?? 0);

        if ($id > 0) {
            $this->jobIds[] = $id;
        }

        return $id;
    }

    /** @param array<string, mixed> $list */
    private function listHas(array $list, int $jobId): bool
    {
        /** @var list<array<string, mixed>> $items */
        $items = \is_array($list['items'] ?? null) ? $list['items'] : [];

        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $jobId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body, int $expected): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            $body,
            self::MARKER . '-' . bin2hex(random_bytes(8)),
        ));

        if ($response->status !== $expected) {
            $this->failures[] = sprintf(
                '%s %s answered %d, expected %d: %s',
                $method,
                $path,
                $response->status,
                $expected,
                json_encode($response->data),
            );

            return [];
        }

        $payload = \is_array($response->data) ? $response->data : [];

        return \is_array($payload['item'] ?? null) ? $payload['item'] : $payload;
    }

    /**
     * A route whose body is a file rather than a document.
     *
     * The download answers CSV text, so it cannot go through call(), which
     * unwraps an array. What is asserted is the bytes a browser would save.
     */
    private function raw(string $method, string $path): string
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            [],
            self::MARKER . '-' . bin2hex(random_bytes(8)),
        ));

        if ($response->status !== 200) {
            $this->failures[] = sprintf('%s %s answered %d, expected 200', $method, $path, $response->status);

            return '';
        }

        // ApiResponse::binary carries the bytes base64 encoded in the document,
        // so a transport that can only serialise JSON still delivers a file.
        $encoded = $response->data[ApiResponse::BINARY_CONTENT_KEY] ?? null;

        if (!\is_string($encoded)) {
            $this->failures[] = $path . ' did not answer a file body';

            return '';
        }

        return (string) base64_decode($encoded, true);
    }

    private function expect(bool $condition, string $what, string $evidence): void
    {
        if ($condition) {
            $this->facts[] = 'ok: ' . $what;

            return;
        }

        $this->failures[] = $what . ': ' . $evidence;
    }
}
