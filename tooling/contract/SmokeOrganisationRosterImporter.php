<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;

/**
 * The importer OrganisationImportContract stands the roster importers up with.
 *
 * It lives in tooling rather than in packages/core because nothing ships it,
 * and nothing should: the two roster importers are R5's, and until they exist
 * no shipped importer reads a CSV at all. What it exists to exercise is
 * everything downstream of an importer, all of which is shipped code, on a
 * real host: the runner, import_row_problems, the organisation stamp and its
 * tenancy predicate, the six routes and the failed-rows download.
 *
 * It is deliberately the smallest thing that can produce the three outcomes
 * the surface has to report. It writes exactly one durable row per good row,
 * an import_map entry, which is what makes "the dry pass wrote nothing" a
 * count rather than a claim, and what makes the cleanup one delete keyed on
 * this importer's own key. It writes no learning row, no membership and no
 * entitlement, so a harness run that dies between the import and the cleanup
 * leaves nothing a site owner would have to unpick.
 */
final class SmokeOrganisationRosterImporter extends AbstractImporter implements RunsForAnOrganisation
{
    public const KEY = 'smoke-org-roster';

    public function __construct(
        private readonly ImportMap $map,
        private readonly StoragePort $storage,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Named for what it is, so a screen that happened to render the registry
     * during a harness run says where the entry came from.
     */
    public function label(): string
    {
        return 'Smoke roster CSV (build harness only)';
    }

    /**
     * Only its own file, which carries the contract's marker in its name. A
     * harness provider that answered true to anything would offer itself for
     * every source on the Import screen for the rest of the process.
     */
    public function supports(string $sourcePath): bool
    {
        return str_contains($sourcePath, OrganisationImportContract::MARKER);
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $file = 'roster.csv';
        $lines = preg_split('/\r\n|\n/', trim($this->storage->readPrivate($sourcePath)));

        if ($lines === false) {
            return;
        }

        $rejected = 0;

        // Row 1 is the header and is not a data row, which is exactly why the
        // first data row is row 2: an operator fixing row 3 opens their own
        // spreadsheet and finds the row this reported.
        foreach (array_slice($lines, 1) as $index => $line) {
            $row = $index + RowProblem::FIRST_DATA_ROW;
            $cells = str_getcsv($line, ',', '"', '\\');
            $sourcedId = (string) ($cells[0] ?? '');
            $email = (string) ($cells[1] ?? '');

            if (!str_contains($email, '@')) {
                $this->reportRow($progress, RowProblem::of(
                    $file,
                    $row,
                    'email',
                    RowProblem::CODE_INVALID_EMAIL,
                    'That is not an email address.',
                    $email,
                    RowProblem::OUTCOME_FAILED,
                ));
                $rejected++;

                continue;
            }

            if (!$progress->isDryRun()) {
                $this->map->remember(self::KEY, 'roster_row', $sourcedId, 'roster_row', $row, $progress->jobId());
            }

            $this->reportOutcome($progress, $file, RowProblem::OUTCOME_CREATED);
        }

        // One error-level note when the run rejected rows, because that is
        // what makes the job completed_with_problems rather than completed:
        // ImportResult::hasErrors() reads the notes and the recorder's own
        // per-file summary is warning level. Every shipped importer does the
        // same thing for the same reason, which is exactly why this one has
        // to as well: a harness whose importer got a different status out of
        // the same rows would be proving a shape nothing ships.
        if ($rejected > 0) {
            $progress->note(
                ImportNote::ERROR,
                sprintf('%d row(s) were rejected and are listed on the row problems page.', $rejected),
            );
        }
    }
}
