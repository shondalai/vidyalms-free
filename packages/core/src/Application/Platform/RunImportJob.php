<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Creates a job and runs it here and now, recording every status transition
 * so the admin UI can show what happened even when the importer blew up
 * halfway. An importer failure is recorded on the job, not rethrown.
 *
 * This is the inline path. It is what a dry run uses, because a preview has
 * to answer the person who asked for it; a real import goes through
 * QueueImportJob instead, so a migration that takes four minutes does not
 * hold an HTTP request open for four minutes.
 *
 * Pass "dry_run" => true in the options to analyse without writing. The job
 * then ends as "previewed" with the totals the importer would have created.
 */
final class RunImportJob
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ImportIntake $intake,
        private readonly ImportRunner $runner,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function execute(Principal $principal, string $importerKey, ?string $sourcePath, array $options = []): ImportJob
    {
        $this->guard->require($principal, Capability::MANAGE);

        return $this->start($principal, $importerKey, $sourcePath, $options, null);
    }

    /**
     * The second, already-authorised entry point, for a preview inside one
     * organisation.
     *
     * The scope is required and first, so an unscoped organisation call does
     * not compile, and there is no Capability::MANAGE check: minting the scope
     * through OrganisationAccess is the authorisation, and requiring MANAGE as
     * well would refuse every org administrator, which is precisely who this
     * route is for.
     *
     * @param array<string, mixed> $options
     */
    public function executeForOrganisation(
        OrgScope $scope,
        Principal $principal,
        string $importerKey,
        ?string $sourcePath,
        array $options = [],
    ): ImportJob {
        return $this->start($principal, $importerKey, $sourcePath, $options, $scope);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function start(
        Principal $principal,
        string $importerKey,
        ?string $sourcePath,
        array $options,
        ?OrgScope $scope,
    ): ImportJob {
        $importer = $this->intake->importer($importerKey);
        $this->intake->source($sourcePath, $principal->userId, $importer);
        $options = $this->intake->options($options, $importer);
        $jobId = $this->jobs->create(
            $importerKey,
            $sourcePath,
            // Versioned by the importer that owns the meaning of these keys.
            // The envelope never leaves the repository: it is stripped on read.
            StoredShape::wrap($options, $this->intake->shapeOf($importer)),
            $principal->userId,
            $scope?->organisationId,
            $scope?->unitId,
        );

        return $this->runner->run($jobId, $importer, $sourcePath, $options, $principal);
    }
}
