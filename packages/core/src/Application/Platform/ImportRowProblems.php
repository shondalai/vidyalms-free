<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * The site console's half of the row report.
 *
 * The organisation half is OrganisationImports, scoped to one tenant. This one
 * is for the migration an operator starts from the site console, which has no
 * organisation at all: a Moodle backup, a Community Quiz load, a LearnDash
 * course tree. Those runs produce row problems on exactly the same terms, and
 * without this they would be written and never read.
 *
 * Both halves are needed and neither is the other's fallback. This one guards
 * on Capability::MANAGE and serves whatever job the id names, which is exactly
 * what the shipped GetImportJob does with the same job's log; the organisation
 * half guards on an OrgScope and serves only that organisation's. Making this
 * route hide an organisation's job while GET /import/{id} still returns its
 * whole log would be a padlock on the window beside an open door, and an
 * inconsistency a reader would have to discover rather than a rule.
 */
final class ImportRowProblems
{
    public const MAX_PER_PAGE = 50;

    /** The most rejected rows one download reconstructs. */
    public const MAX_FAILED_ROWS = 10000;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ImportRowProblemRepository $problems,
        private readonly AccessGuard $guard,
    ) {
    }

    public function page(
        Principal $principal,
        int $jobId,
        int $page = 1,
        int $perPage = 25,
        ?string $code = null,
        ?string $outcome = null,
    ): ImportRowProblemPage {
        $this->requireJob($principal, $jobId);

        return $this->problems->page(
            $jobId,
            max(1, $page),
            min(self::MAX_PER_PAGE, max(1, $perPage)),
            $code,
            $outcome,
        );
    }

    /** The failed-rows CSV for a site-scoped run. */
    public function failedRows(Principal $principal, int $jobId): FailedRowsDownload
    {
        $this->requireJob($principal, $jobId);

        return FailedRowsDownload::of(
            $this->problems->rejected($jobId, self::MAX_FAILED_ROWS),
            $this->problems->page($jobId, 1, 1)->rejected,
            self::MAX_FAILED_ROWS,
        );
    }

    /**
     * MANAGE, and the job must exist.
     *
     * The existence check is not ceremony: a page of problems for a job id
     * nobody created would answer an empty page and read as "this import went
     * perfectly", which is the opposite of the truth.
     */
    private function requireJob(Principal $principal, int $jobId): void
    {
        $this->guard->require($principal, Capability::MANAGE);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }
}
