<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImportRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImports;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportIntake;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRunner;
use Shondalai\VidyaLMS\Core\Application\Platform\ListImportJobs;
use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunQueuedImport;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgImportStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\RecordsRowProblems;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The organisation-scoped import surface, which is the route the free build's
 * own "adds people by CSV" sentence had none of.
 */
final class OrganisationImportsTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrganisationAccess $access;
    private DatabaseImportJobRepository $jobs;
    private DatabaseImportRowProblemRepository $problems;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->jobs = new DatabaseImportJobRepository($this->env->database, $this->env->clock);
        $this->problems = new DatabaseImportRowProblemRepository($this->env->database);

        foreach ([1, 2] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $this->access = new OrganisationAccess(
            new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
        );
    }

    public function testTheSurfaceIsSixRoutesUnderTheOrganisationsOwnPath(): void
    {
        $paths = array_map(
            static fn (array $definition): string => $definition[0] . ' ' . $definition[1],
            OrganisationImportRoutes::definitions(),
        );

        self::assertSame([
            'POST /manage/organisations/{id}/imports',
            'GET /manage/organisations/{id}/imports',
            'GET /manage/organisations/{id}/imports/{job_id}',
            'GET /manage/organisations/{id}/imports/{job_id}/log',
            'GET /manage/organisations/{id}/imports/{job_id}/problems',
            'GET /manage/organisations/{id}/imports/{job_id}/failed-rows',
        ], $paths, 'Under the org path, so OrgScopeMiddleware pins it and the access log records it.');
    }

    public function testAnOrganisationSeesItsOwnJobsAndNeitherTheSitesNorAnothersFalse(): void
    {
        [$first, $second] = $this->twoOrganisations();

        $mine = $this->jobs->create('roster', 'imports/1/a.csv', [], 1, $first);
        $theirs = $this->jobs->create('roster', 'imports/1/b.csv', [], 1, $second);
        $site = $this->jobs->create('moodle-backup', 'imports/c.mbz', [], 1);

        $page = $this->imports()->jobs(Principal::user(1), $first);

        self::assertSame([$mine], array_map(static fn (ImportJob $job): int => $job->id, $page->items));
        self::assertSame(1, $page->total);
        self::assertNotContains($theirs, array_map(static fn (ImportJob $job): int => $job->id, $page->items));
        self::assertNotContains($site, array_map(static fn (ImportJob $job): int => $job->id, $page->items));
    }

    public function testTheSiteScopedListStillServesEveryJobWithNoOrganisation(): void
    {
        [$first] = $this->twoOrganisations();
        $site = $this->jobs->create('moodle-backup', 'imports/c.mbz', [], 1);
        $this->jobs->create('roster', 'imports/1/a.csv', [], 1, $first);

        // The risk this wave carries: both development sites hold real
        // import_jobs rows from earlier journeys, and every one of them has a
        // null organisation.
        $page = (new ListImportJobs($this->jobs, new AccessGuard($this->env->authorization)))
            ->execute(Principal::user(1));

        $ids = array_map(static fn (ImportJob $job): int => $job->id, $page->items);
        self::assertContains($site, $ids);
        self::assertSame(2, $page->total, 'The site console keeps its whole history.');
        self::assertNull($this->jobs->find($site)?->organisationId);
    }

    public function testAnotherOrganisationsJobIsNotFoundRatherThanRefused(): void
    {
        [$first, $second] = $this->twoOrganisations();
        $theirs = $this->jobs->create('roster', 'imports/1/b.csv', [], 1, $second);

        $this->expectException(EntityNotFoundException::class);

        $this->imports()->job(Principal::user(1), $first, $theirs);
    }

    public function testAPersonWithNoRoleInTheOrganisationIsRefused(): void
    {
        [$first] = $this->twoOrganisations();

        // A foreign organisation and a missing one read identically, which is
        // the resolver's own rule: a caller who could tell them apart learns
        // that the organisation exists.
        $this->expectException(EntityNotFoundException::class);

        $this->imports()->jobs(Principal::user(2), $first);
    }

    public function testTheProblemsAndFailedRowsOfAnotherOrganisationsJobAreNotReachable(): void
    {
        [$first, $second] = $this->twoOrganisations();
        $theirs = $this->jobs->create('roster', 'imports/1/b.csv', [], 1, $second);
        $this->problems->append($theirs, [RowProblem::of('users.csv', 2, 'email', RowProblem::CODE_INVALID_EMAIL, 'no')
            ->toArray()]);

        $store = new OrgImportStore($this->env->database, $this->problems);
        $scope = $this->access->require(Principal::user(1), $first, OrganisationImports::ACTION);

        self::assertNull($store->problems($scope, $theirs, 1, 25));
        self::assertNull($store->rejected($scope, $theirs, 10));
    }

    public function testStartingAnImportStampsTheOrganisationFromTheContextAndNotFromTheOptions(): void
    {
        [$first, $second] = $this->twoOrganisations();

        $job = $this->imports()->start(
            Principal::user(1),
            $first,
            'roster',
            'imports/1/roster.csv',
            // A caller trying to name a different organisation in the payload.
            ['dry_run' => true, 'organisation_id' => $second, 'organisation' => 'Somebody Else'],
        );

        self::assertSame($first, $job->organisationId);
        self::assertNotSame($second, $job->organisationId);
        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status, 'A dry run is answered now.');
    }

    public function testTheFailedRowsDownloadIsScopedAndCarriesTheRejectedRows(): void
    {
        [$first] = $this->twoOrganisations();

        // Queued, then drained, which is what a real run is: the request that
        // asks for a forty thousand row roster does not wait for it.
        $queued = $this->imports()->start(Principal::user(1), $first, 'roster', 'imports/1/roster.csv');
        self::assertSame(ImportJob::STATUS_PENDING, $queued->status);
        self::assertSame($first, $queued->organisationId);

        $registry = new ImporterRegistry();
        $registry->register($this->rosterImporter());
        (new ImportRunner($this->jobs, $this->problems))->run(
            $queued->id,
            $registry->get('roster'),
            $queued->sourcePath,
            $queued->options,
            Principal::user(1),
        );

        $csv = $this->imports()->failedRows(Principal::user(1), $first, $queued->id)->body;

        self::assertStringContainsString('invalid_email', $csv);
        self::assertStringContainsString('users.csv', $csv);
        self::assertStringNotContainsString('unchanged', $csv, 'Only the rows the operator has to fix.');
    }

    /**
     * The hole this closes: the scope proved the caller administers the
     * organisation and then any registered importer key was passed straight to
     * the runner. community-quiz reads the host's own tables; the six format
     * importers read another product's tables on the same site. None of it is
     * this organisation's data.
     */
    public function testAnImporterThatHasNotDeclaredItselfOrganisationEligibleIsRefused(): void
    {
        [$first] = $this->twoOrganisations();

        $registry = new ImporterRegistry();
        $registry->register($this->siteOnlyImporter());
        $intake = new ImportIntake($registry);
        $imports = $this->imports($intake);

        $this->expectException(ValidationException::class);

        $imports->start(Principal::user(1), $first, 'site-only', 'imports/1/roster.csv', ['dry_run' => true]);
    }

    /**
     * The other half: a scheme source names the site's own tables, and an
     * organisation console may not aim an importer at them.
     */
    public function testASchemeSourceIsRefusedFromAnOrganisationConsole(): void
    {
        [$first] = $this->twoOrganisations();

        foreach (['cms-db:', 'learndash-db:courses=10', 'tutor-db:'] as $scheme) {
            try {
                $this->imports()->start(Principal::user(1), $first, 'roster', $scheme, ['dry_run' => true]);
                self::fail('Expected ' . $scheme . ' to be refused.');
            } catch (ValidationException $refused) {
                self::assertStringContainsString(
                    'tables of this site\'s own database',
                    (string) ($refused->errors['source'] ?? ''),
                    'It is refused for being the site\'s data, by its own rule and with its own sentence.',
                );
            }
        }
    }

    /**
     * A private path somebody else uploaded, and a path that does not exist,
     * answer the same refusal, so the route is not an existence oracle over
     * the whole private store.
     */
    public function testAFileTheCallerDidNotUploadIsRefusedAndSaysNothingAboutWhetherItExists(): void
    {
        [$first] = $this->twoOrganisations();
        $imports = $this->imports();

        $refusals = [];

        foreach (['imports/9/site-private.json', 'configuration.json', 'imports/1/../9/x.json'] as $source) {
            try {
                $imports->start(Principal::user(1), $first, 'roster', $source, ['dry_run' => true]);
                self::fail('Expected ' . $source . ' to be refused.');
            } catch (ValidationException $refused) {
                $refusals[] = $refused->errors['source'] ?? '';
            }
        }

        self::assertCount(3, $refusals);
        self::assertCount(1, array_unique($refusals), 'One sentence, so nothing is learned from which one came back.');
    }

    /**
     * The free build's own "adds people by CSV" sentence, drained.
     *
     * A dry run always worked because it never queues. A real run answered 202
     * and then threw NotAuthorizedException at drain time, because
     * RunQueuedImport demanded site MANAGE of the job's creator while
     * QueueImportJob::executeForOrganisation deliberately skips it. The row sat
     * pending for ever.
     */
    public function testAnOrganisationAdministratorsQueuedImportActuallyDrains(): void
    {
        [$first] = $this->twoOrganisations();

        // An org administrator and nothing else: MANAGE_GROUPS opens the
        // delegated console and answers nothing about tenancy, and there is no
        // site MANAGE anywhere.
        $this->env->users->add(7, 'Olga OrgAdmin', 'olga@example.test');
        $this->env->authorization->grant(7, Capability::MANAGE_GROUPS);
        $this->store->addMember(
            $this->access->require(Principal::user(1), $first, OrganisationImports::ACTION),
            7,
            'org_admin',
            'manual',
            null,
        );

        $imports = $this->imports();
        $queued = $imports->start(Principal::user(7), $first, 'roster', 'imports/7/roster.csv');

        self::assertSame(ImportJob::STATUS_PENDING, $queued->status);
        self::assertSame($first, $queued->organisationId);

        $registry = new ImporterRegistry();
        $registry->register($this->rosterImporter());

        $drained = (new RunQueuedImport(
            $this->jobs,
            new ImportIntake($registry),
            new ImportRunner($this->jobs, $this->problems),
            new AccessGuard($this->env->authorization),
            $this->access,
        ))->execute($queued->id);

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $drained->status,
            'It ran, and it reported the row it rejected.',
        );
    }

    /**
     * The status is decided from the rows, not from a note the importer has to
     * remember to write.
     */
    public function testARunThatRejectsRowsAndWritesNoErrorNoteStillFinishesWithProblems(): void
    {
        [$first] = $this->twoOrganisations();

        $queued = $this->imports()->start(Principal::user(1), $first, 'roster', 'imports/1/roster.csv');

        $registry = new ImporterRegistry();
        $registry->register($this->rosterImporter());
        $job = (new ImportRunner($this->jobs, $this->problems))->run(
            $queued->id,
            $registry->get('roster'),
            $queued->sourcePath,
            $queued->options,
            Principal::user(1),
        );

        self::assertStringNotContainsString(
            '[error]',
            (string) $job->log,
            'The harness importer writes no error note at all, which is the whole point.',
        );
        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame(1, $job->totals['failed'] ?? 0);
    }

    /**
     * A download that hit its cap says so, in the file and in the headers.
     */
    public function testAFailedRowsDownloadThatHitItsCapCarriesAMarkerAndTheTrueTotal(): void
    {
        [$first] = $this->twoOrganisations();
        $jobId = $this->jobs->create('roster', 'imports/1/roster.csv', [], 1, $first);

        $rows = [];

        for ($row = 2; $row <= OrganisationImports::MAX_FAILED_ROWS + 200; $row++) {
            $rows[] = RowProblem::of(
                'users.csv',
                $row,
                'email',
                RowProblem::CODE_INVALID_EMAIL,
                'Not an address.',
                'nope',
                RowProblem::OUTCOME_FAILED,
            )->toArray();
        }

        $this->problems->append($jobId, $rows);

        $download = $this->imports()->failedRows(Principal::user(1), $first, $jobId);

        self::assertTrue($download->wasTruncated());
        self::assertSame(OrganisationImports::MAX_FAILED_ROWS, $download->rendered);
        self::assertSame(\count($rows), $download->rejected);
        self::assertStringContainsString('truncated', $download->body);
        self::assertSame(
            (string) \count($rows),
            $download->headers()['X-VidyaLMS-Rejected-Rows'] ?? '',
        );
    }

    private function imports(?ImportIntake $intake = null): OrganisationImports
    {
        if ($intake === null) {
            $registry = new ImporterRegistry();
            $registry->register($this->rosterImporter());
            $intake = new ImportIntake($registry);
        }

        $runner = new ImportRunner($this->jobs, $this->problems);

        return new OrganisationImports(
            $this->access,
            new OrgImportStore($this->env->database, $this->problems),
            new QueueImportJob(
                $this->jobs,
                $intake,
                $this->env->scheduler,
                new AccessGuard($this->env->authorization),
                $this->env->clock,
            ),
            new RunImportJob($this->jobs, $intake, $runner, new AccessGuard($this->env->authorization)),
            $intake,
        );
    }

    private function rosterImporter(): AbstractImporter
    {
        return new class extends AbstractImporter implements RunsForAnOrganisation {
            public function key(): string
            {
                return 'roster';
            }

            public function label(): string
            {
                return 'Roster';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            public function importWithProgress(
                string $sourcePath,
                array $options,
                Principal $principal,
                ImportProgress $progress,
            ): void {
                if (!$progress instanceof RecordsRowProblems) {
                    return;
                }

                $progress->rowOutcome('users.csv', RowProblem::OUTCOME_CREATED);
                $progress->rowProblem(RowProblem::of(
                    'users.csv',
                    3,
                    'email',
                    RowProblem::CODE_INVALID_EMAIL,
                    'Not an address.',
                    'nope',
                    RowProblem::OUTCOME_FAILED,
                ));
            }
        };
    }

    /**
     * One key, two districts.
     *
     * The start route scoped its idempotency claim on the flat constant
     * "org.import.start", and IdempotentExecution scopes on the route name
     * plus the principal id with no organisation and no body fingerprint. So
     * one person administering two districts who reused a key across
     * /organisations/1/imports and /organisations/2/imports was handed the
     * first district's job back with a 202, and the second district's roster
     * never loaded. The three sibling organisation handlers already scope on
     * hash(method:path), which puts the organisation id in the claim.
     */
    public function testTheSameKeyOnTwoOrganisationsStartsTwoImports(): void
    {
        [$first, $second] = $this->twoOrganisations();

        $kernel = new CoreKernel($this->env->ports);
        $kernel->registries->importers->register($this->rosterImporter());
        $api = $kernel->apiKernel();

        $this->env->storage->putPrivate('imports/1/north.csv', 'email');
        $this->env->storage->putPrivate('imports/1/south.csv', 'email');

        $north = $api->handle(new ApiRequest(
            'POST',
            '/manage/organisations/' . $first . '/imports',
            Principal::user(1),
            [],
            ['importer' => 'roster', 'source' => 'imports/1/north.csv'],
            'one-key',
        ));
        $south = $api->handle(new ApiRequest(
            'POST',
            '/manage/organisations/' . $second . '/imports',
            Principal::user(1),
            [],
            ['importer' => 'roster', 'source' => 'imports/1/south.csv'],
            'one-key',
        ));

        self::assertSame(202, $north->status);
        self::assertSame(202, $south->status);
        self::assertNotNull($north->data);
        self::assertNotNull($south->data);
        self::assertNotSame($north->data['id'], $south->data['id'], 'Two districts, two jobs.');
        self::assertSame($first, $north->data['organisation_id']);
        self::assertSame($second, $south->data['organisation_id']);
        self::assertSame(2, $this->env->database->count(Query::from('import_jobs')));
    }

    /** An importer that has not declared itself safe to run under a tenant. */
    private function siteOnlyImporter(): AbstractImporter
    {
        return new class extends AbstractImporter {
            public function key(): string
            {
                return 'site-only';
            }

            public function label(): string
            {
                return 'Site only';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            public function importWithProgress(
                string $sourcePath,
                array $options,
                Principal $principal,
                ImportProgress $progress,
            ): void {
                $progress->note('info', 'ran');
            }
        };
    }

    /** @return array{0: int, 1: int} */
    private function twoOrganisations(): array
    {
        $ids = [];

        foreach ([1, 2] as $index) {
            $ids[] = $this->store->create([
                'name' => 'Organisation ' . $index,
                'slug' => 'org-' . $index,
                'created_by' => 1,
            ]);
        }

        return [$ids[0], $ids[1]];
    }
}
