<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\FailedRowsDownload;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportIntake;
use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemPage;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgImportStore;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;

/**
 * The organisation-scoped import surface: six reads and one write, all of them
 * narrowed to one organisation.
 *
 * It exists because the free build's own sentence, that an organisation "adds
 * people by invitation or by CSV", had no route at all. Every import read was
 * site-manager only, so an org administrator could not list their own import
 * jobs, could not read the log of one, and had nowhere to download the rows
 * that failed. That is a padlock by omission: the feature was described,
 * priced as free, and unreachable.
 *
 * Two rules hold across every method here.
 *
 * **The organisation comes from the path and is proved before anything else.**
 * Each method opens by minting an OrgScope through OrganisationAccess, which
 * validates the id against the caller's own org_memberships rows, and passes
 * that scope, non-defaultable, into the store. Nothing here reads an
 * organisation out of a body, a query string or a file.
 *
 * **The right is "people".** A roster load adds, updates and removes members,
 * so it is the same right that adds one member by hand; org_reporter, whose
 * rights are events and read, may not start one and may not read the failed
 * rows, because those rows are a list of names and addresses of people who
 * were not added.
 *
 * The access-log write is not here and that is deliberate:
 * OrganisationAccessLogMiddleware records every GET whose path names an
 * organisation, above dispatch, precisely so the record cannot be forgotten by
 * one handler. These routes live under /manage/organisations/{id}, so they are
 * recorded on the same terms as every other organisation read, including the
 * site-manager path that bypasses the role predicate.
 */
final class OrganisationImports
{
    /** The right a roster load runs under. */
    public const ACTION = 'people';

    public const MAX_PER_PAGE = 50;

    /** The most rejected rows one download reconstructs. */
    public const MAX_FAILED_ROWS = 10000;

    /**
     * The folder every host's Import screen uploads into, one subfolder per
     * uploading user: "imports/{user id}/{stamp}-{name}". Both adapters
     * compose it identically and neither may compose it differently, because
     * this is the rule that decides whose bytes an organisation may read.
     */
    public const UPLOAD_FOLDER = 'imports';

    public function __construct(
        private readonly OrganisationAccess $access,
        private readonly OrgImportStore $store,
        private readonly QueueImportJob $queue,
        private readonly RunImportJob $run,
        /**
         * Resolves the importer key so the eligibility rule can be asked of
         * the importer itself. Optional so a caller built before the rule
         * existed still constructs; a null intake means no importer can be
         * shown to declare RunsForAnOrganisation, and start() refuses, which
         * is the safe direction for a missing dependency to fail in.
         */
        private readonly ?ImportIntake $intake = null,
    ) {
    }

    /**
     * Starts an import for this organisation.
     *
     * A dry run is executed here and now, because a preview exists to be read
     * now and writes nothing; a real run is queued, because a forty thousand
     * row roster would hold the request open past every timeout the host has.
     *
     * @param array<string, mixed> $options
     */
    public function start(
        Principal $actor,
        int $orgId,
        string $importerKey,
        ?string $sourcePath,
        array $options = [],
    ): ImportJob {
        $scope = $this->access->require($actor, $orgId, self::ACTION);

        $this->requireEligibleImporter($importerKey);
        $this->requireOwnedSource($actor, $sourcePath);

        if (($options['dry_run'] ?? false) === true) {
            return $this->run->executeForOrganisation($scope, $actor, $importerKey, $sourcePath, $options);
        }

        return $this->queue->executeForOrganisation($scope, $actor, $importerKey, $sourcePath, $options);
    }

    /** This organisation's import history, newest first. */
    public function jobs(Principal $actor, int $orgId, int $page = 1, int $perPage = 20): ImportJobPage
    {
        return $this->store->jobs(
            $this->access->require($actor, $orgId, self::ACTION),
            max(1, $page),
            min(self::MAX_PER_PAGE, max(1, $perPage)),
        );
    }

    /**
     * One job, with its log.
     *
     * The log route and the job route are the same read, because the log is a
     * column on the job: a separate endpoint would be a second query for the
     * same row and a second place for the tenancy check to be forgotten.
     */
    public function job(Principal $actor, int $orgId, int $jobId): ImportJob
    {
        $scope = $this->access->require($actor, $orgId, self::ACTION);

        return $this->store->job($scope, $jobId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Import job', $jobId);
    }

    /** One page of a job's row problems. */
    public function problems(
        Principal $actor,
        int $orgId,
        int $jobId,
        int $page = 1,
        int $perPage = 25,
        ?string $code = null,
        ?string $outcome = null,
    ): ImportRowProblemPage {
        $scope = $this->access->require($actor, $orgId, self::ACTION);

        return $this->store->problems(
            $scope,
            $jobId,
            max(1, $page),
            min(self::MAX_PER_PAGE, max(1, $perPage)),
            $code,
            $outcome,
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        ) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }

    /**
     * The failed-rows CSV, already neutralised cell by cell.
     *
     * The operator fixes these rows and re-uploads only them, and per-row
     * idempotency makes that safe: the rows that worked are already in the
     * import map and are updated rather than duplicated.
     */
    public function failedRows(Principal $actor, int $orgId, int $jobId): FailedRowsDownload
    {
        $scope = $this->access->require($actor, $orgId, self::ACTION);

        $rejected = $this->store->rejected($scope, $jobId, self::MAX_FAILED_ROWS)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Import job', $jobId);

        return FailedRowsDownload::of(
            $rejected,
            $this->store->rejectedCount($scope, $jobId) ?? \count($rejected),
            self::MAX_FAILED_ROWS,
        );
    }

    /**
     * Refuses an importer that has not said it may run under a tenant's
     * authority.
     *
     * The hole this closes: start() used to pass whatever key the body
     * carried straight to the runner, with the scope proving only that the
     * caller administers the organisation. An org administrator holding
     * MANAGE_GROUPS could therefore run community-quiz, which reads the host's
     * own tables, or any of the six format importers, which read another
     * product's tables on the same site. None of those is this organisation's
     * data and none of it is within the right the scope granted.
     *
     * The eligibility is the importer's own declaration rather than a list
     * here, so a third-party roster mapper can be reachable without a core
     * edit and a new site-only importer is refused by default. An unknown key
     * and an ineligible one answer the same sentence, because a caller who
     * could tell them apart could enumerate the registry.
     */
    private function requireEligibleImporter(string $importerKey): void
    {
        $importer = null;

        try {
            $importer = $this->intake?->importer($importerKey);
        } catch (ValidationException) {
            $importer = null;
        }

        if ($importer instanceof RunsForAnOrganisation) {
            return;
        }

        throw ValidationException::forField('importer', sprintf(
            'No importer an organisation may run answers to "%s". An importer becomes available here by'
                . ' declaring RunsForAnOrganisation, which is the importer saying it reads only what this'
                . ' organisation gave it and writes only inside this organisation.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $importerKey,
        ));
    }

    /**
     * Refuses a source this organisation's own people did not upload.
     *
     * Two rules, and both of them are about whose bytes these are.
     *
     * **A scheme source is refused outright.** "cms-db:", "learndash-db:" and
     * every other scheme name the tables of the site's own database or of
     * another product installed on it. Those are the site's data, not this
     * tenant's, and the scope that got the caller here proves membership of an
     * organisation and nothing whatever about the site.
     *
     * **A stored file must be one this caller uploaded.** Every host's Import
     * screen writes an upload to "imports/{uploader's user id}/...", so the
     * prefix is the ownership record the private store itself does not keep. A
     * path outside it, including a traversal dressed up as one, is refused
     * before the importer is reached, which is also what stops the refusal
     * being an existence oracle: a caller learns that the path is not theirs
     * and never whether it is anybody's.
     *
     * The check is per caller rather than per organisation because an upload
     * is recorded against a person and not against a tenant. R5's roster
     * importers land with an upload recorded against the organisation, and
     * this rule narrows to it then; it may never widen.
     */
    private function requireOwnedSource(Principal $actor, ?string $sourcePath): void
    {
        $source = trim((string) $sourcePath);

        if ($source === '') {
            throw ValidationException::forField('source', 'A source is required.');
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $source) === 1) {
            throw ValidationException::forField('source', sprintf(
                'A scheme source names tables of this site\'s own database, which belong to the site and not to'
                    . ' one organisation. An organisation import reads a file its own people uploaded, under'
                    . ' "%s/{your user id}/".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::UPLOAD_FOLDER,
            ));
        }

        $prefix = self::UPLOAD_FOLDER . '/' . max(0, $actor->userId) . '/';

        if (!str_starts_with($source, $prefix) || str_contains($source, '..')) {
            throw ValidationException::forField('source', sprintf(
                'An organisation import reads a file you uploaded yourself, which lands under "%s". This source'
                    . ' is not one of them.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $prefix,
            ));
        }
    }
}
