<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImports;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * What the host's task runner calls when the scheduled "vidyalms.import.run"
 * task fires, with the job id from the payload.
 *
 * There is no caller principal: cron has no session. The job records who
 * asked for the import, and the run acts as that person, which keeps the
 * authorization story honest in both directions. Every use case the importer
 * calls sees the requester, and if the requester has lost MANAGE since they
 * queued it, the import does not happen.
 *
 * A job that is not pending is refused rather than run twice: a task runner
 * that redelivers must not restart an import that is already halfway.
 */
final class RunQueuedImport
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ImportIntake $intake,
        private readonly ImportRunner $runner,
        private readonly AccessGuard $guard,
        /**
         * How the drain re-mints the authority an organisation job was
         * accepted under. Optional so a caller built before organisation
         * imports existed still constructs; a null resolver leaves an
         * organisation job unrunnable and says so, which is the safe direction
         * for a missing dependency to fail in.
         */
        private readonly ?OrganisationAccess $organisations = null,
    ) {
    }

    public function execute(int $jobId): ImportJob
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $job = $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);

        if ($job->status !== ImportJob::STATUS_PENDING) {
            throw new ConflictException(sprintf(
                'Import job %d is %s and cannot be started again.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $jobId,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->status,
            ));
        }

        $principal = $job->createdBy > 0 ? Principal::user($job->createdBy) : Principal::guest();
        $this->authorise($principal, $job);

        // The Importer registry-miss direction, published in
        // 10-coding-standards.md and implemented in the runner: a stored job
        // whose importer key resolves to nothing is left pending and reported,
        // never marked failed. A job marked failed because an extension was
        // not registered at that moment is a job whose work is destroyed, and
        // the cause (a package not installed, a plugin disabled during a
        // deploy) is usually temporary. Pending is the honest status: the run
        // has not happened yet.
        try {
            $importer = $this->intake->importer($job->importer);
        } catch (ValidationException $miss) {
            return $this->runner->reportUnresolvedImporter($jobId, $job->importer, ImportProblem::describe($miss));
        }

        // Older queued rows do not bypass the current storage boundary.
        $this->intake->source($job->sourcePath, $principal->userId, $importer);
        return $this->runner->run($jobId, $importer, $job->sourcePath, $job->options, $principal);
    }

    /**
     * The authority the job is drained under, which is the authority it was
     * accepted under.
     *
     * A site job is a site manager's and site MANAGE is the check, unchanged.
     * An organisation job is not: QueueImportJob::executeForOrganisation
     * deliberately skips Capability::MANAGE, because "a second
     * Capability::MANAGE here would refuse every org administrator", and
     * demanding it again at drain time undid that on the one path that matters.
     * The job was accepted with a 202, the row sat pending for ever, the
     * host's queue retried three times and marked its own row failed, and the
     * free build's "adds people by CSV" sentence therefore had a route that
     * accepted work and could never do it. Only a dry run, which never queues,
     * completed.
     *
     * So the tenant is re-resolved from the stored job rather than trusted
     * from it: OrganisationAccess runs the caller's own org_memberships
     * predicate again, now, which is the same check that accepted the job and
     * is what makes an administrator who has since been removed lose the drain
     * as well as the console.
     */
    private function authorise(Principal $principal, ImportJob $job): void
    {
        if ($job->organisationId === null) {
            $this->guard->require($principal, Capability::MANAGE);

            return;
        }

        if ($this->organisations === null) {
            throw new ConflictException(sprintf(
                'Import job %d belongs to organisation %d and this build cannot re-resolve that organisation\'s'
                    . ' authority, so it will not run it as the site.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->id,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->organisationId,
            ));
        }

        $scope = $this->organisations->require($principal, $job->organisationId, OrganisationImports::ACTION);
        if ($scope->unitId !== $job->organisationUnitId) {
            throw new ConflictException('The import owner\'s organisation scope has changed. Start a new import under the current scope.');
        }
    }
}
