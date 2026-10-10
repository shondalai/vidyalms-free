<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\FailedRowsFile;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportIntake;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRowProblems;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRunner;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunQueuedImport;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;
use Shondalai\VidyaLMS\Core\Registry\Importer\PreflightsBeforeWriting;
use Shondalai\VidyaLMS\Core\Registry\Importer\RecordsRowProblems;
use Shondalai\VidyaLMS\Core\Registry\Importer\StagedImporterInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The row level error model end to end: a malformed file produces row problems
 * and a finished job, not a failed one.
 */
final class ImportRowProblemModelTest extends TestCase
{
    public function testAMalformedFileProducesRowProblemsAndNotAFailedJob(): void
    {
        $env = $this->env();
        $job = $this->runImport($env, $this->rejectingImporter());

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status, 'Nine hundred rows were written.');
        self::assertSame(2, $job->totals[ImportResult::TOTAL_CREATED] ?? 0);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_FAILED] ?? 0);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_SKIPPED] ?? 0);
    }

    public function testTheRowsGoInTheirOwnTableAndTheLogGetsOneSummaryPerFile(): void
    {
        $env = $this->env();
        $job = $this->runImport($env, $this->rejectingImporter());

        $problems = new DatabaseImportRowProblemRepository($env->database);
        $page = $problems->page($job->id, 1, 25);

        self::assertSame(2, $page->total);
        self::assertSame(2, $page->rejected);
        self::assertSame(
            [RowProblem::CODE_INVALID_EMAIL => 1, RowProblem::CODE_REFERENTIAL_MISS => 1],
            $page->byCode,
        );

        // One line per file, and the rows are not in it.
        $log = (string) $job->log;
        self::assertStringContainsString('users.csv: 3 rows, 2 created, 0 updated, 0 unchanged, 0 skipped, 1 failed.', $log);
        self::assertStringContainsString('enrollments.csv: 1 rows, 0 created, 0 updated, 0 unchanged, 1 skipped, 0 failed.', $log);
        self::assertStringNotContainsString('not-an-address', $log, 'A row problem is not a log line.');
    }

    public function testARowsProblemNamesTheRowTheColumnAndTheCode(): void
    {
        $env = $this->env();
        $job = $this->runImport($env, $this->rejectingImporter());

        $problems = new DatabaseImportRowProblemRepository($env->database);
        $rows = $problems->page($job->id, 1, 25, RowProblem::CODE_INVALID_EMAIL)->items;

        self::assertCount(1, $rows, 'The code filter is a real narrowing.');
        self::assertSame('users.csv', $rows[0]['file']);
        self::assertSame(4, $rows[0]['row'], 'One based and including the header.');
        self::assertSame('email', $rows[0]['column']);
        self::assertSame(RowProblem::CODE_INVALID_EMAIL, $rows[0]['code']);
        self::assertSame(RowProblem::OUTCOME_FAILED, $rows[0]['outcome']);
        self::assertStringContainsString('email:', (string) $rows[0]['message'], 'ImportProblem expanded the fields.');
    }

    public function testAReRunReportsItsOwnRowsAndNotTheLastRuns(): void
    {
        $env = $this->env();
        $first = $this->runImport($env, $this->rejectingImporter());
        $problems = new DatabaseImportRowProblemRepository($env->database);
        self::assertSame(2, $problems->page($first->id, 1, 25)->total);

        $second = $this->runImport($env, $this->rejectingImporter());
        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, $problems->page($second->id, 1, 25)->total);
        self::assertSame(4, $env->database->count(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('import_row_problems'),
        ));
    }

    public function testTheFailedRowsDownloadNeutralisesEveryCellItEchoes(): void
    {
        $env = $this->env();
        $job = $this->runImport($env, $this->hostileImporter());

        $csv = (new ImportRowProblems(
            new DatabaseImportJobRepository($env->database, $env->clock),
            new DatabaseImportRowProblemRepository($env->database),
            new AccessGuard($env->authorization),
        ))->failedRows(Principal::user(9), $job->id)->body;

        self::assertStringStartsWith(FailedRowsFile::BOM, $csv);

        foreach (explode("\r\n", $csv) as $line) {
            foreach (str_getcsv($line) as $cell) {
                if (\is_string($cell) && $cell !== '') {
                    self::assertFalse(
                        CellSafetyPolicy::isDangerous($cell),
                        'A formula reached a cell unprefixed: ' . $cell,
                    );
                }
            }
        }

        self::assertStringContainsString("'=cmd|", $csv, 'The value is kept, and it is neutralised.');
        self::assertStringContainsString("'@SUM", $csv);
        self::assertStringContainsString("\u{FF1D}", $csv, 'The full-width operator survives, prefixed.');
    }

    public function testTheDryPassRunsAutomaticallyBeforeTheRealOneAndCountsOnlyOnce(): void
    {
        $env = $this->env();
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $job = $this->runImport($env, $this->preflightingImporter($log));

        self::assertSame(['dry', 'real'], $log->getArrayCopy(), 'Dry first, then real, in one process.');
        self::assertSame(1, $job->totals[ImportResult::TOTAL_CREATED] ?? 0, 'The preview is not added to the run.');
        self::assertStringContainsString('Preflight: ', (string) $job->log);

        // Nothing durable from the preview: only the real pass wrote a row.
        $problems = new DatabaseImportRowProblemRepository($env->database);
        self::assertSame(1, $problems->page($job->id, 1, 25)->total);
    }

    public function testAStagedImporterLoadsInTheRunnersOrderAndNotItsOwn(): void
    {
        $env = $this->env();
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $this->runImport($env, $this->stagedImporter($log));

        self::assertSame(
            [ImportLoadOrder::STAGE_ORGANISATIONS, ImportLoadOrder::STAGE_PEOPLE, ImportLoadOrder::STAGE_RELEASES],
            $log->getArrayCopy(),
        );
    }

    public function testAnUnregisteredImporterOnAStoredJobIsLeftPendingAndReported(): void
    {
        $env = $this->env();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('gone-away', 'imports/roster.csv', ['dry_run' => false], 9);

        $job = (new RunQueuedImport(
            $jobs,
            new ImportIntake(new ImporterRegistry()),
            new ImportRunner($jobs, new DatabaseImportRowProblemRepository($env->database)),
            new AccessGuard($env->authorization),
        ))->execute($jobId);

        self::assertSame(ImportJob::STATUS_PENDING, $job->status, 'Never failed: the work is not destroyed.');
        self::assertStringContainsString('gone-away', (string) $job->log);
        self::assertStringContainsString('still waiting', (string) $job->log);
        self::assertNull($job->finishedAt);
    }

    public function testAnOrganisationScopedMapperIsRefusedBeforeItWritesAnythingWhenTheJobNamesNoOrganisation(): void
    {
        $env = $this->env();
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $job = $this->runImport($env, $this->tenantedImporter($log));

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertSame([], $log->getArrayCopy(), 'Refused before the first row, not on row nine thousand.');
        self::assertStringContainsString('names no organisation', (string) $job->log);
    }

    public function testTheSameMapperRunsWhenTheJobCarriesTheOrganisation(): void
    {
        $env = $this->env();
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('tenanted', null, ['dry_run' => false], 9, 7);

        $job = (new ImportRunner($jobs, new DatabaseImportRowProblemRepository($env->database)))
            ->run($jobId, $this->tenantedImporter($log), null, ['dry_run' => false], Principal::user(9));

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(['ran'], $log->getArrayCopy());
        self::assertSame(7, $job->organisationId);
    }

    /**
     * A job an operator ended stops collecting row problems.
     *
     * ImportRunner's flush and touch callbacks both ask whether the job is
     * still running, because "a status an operator set is final"; the
     * row-problem callback asked neither, so a run that finished an hour after
     * an operator marked the job failed left that job carrying rows this run
     * wrote afterwards.
     */
    public function testAJobAnOperatorEndedStopsCollectingRowProblems(): void
    {
        $env = $this->env();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $problems = new DatabaseImportRowProblemRepository($env->database);
        $registry = new ImporterRegistry();
        $importer = $this->abandoningImporter($jobs);
        $registry->register($importer);

        $job = (new RunImportJob(
            $jobs,
            new ImportIntake($registry),
            new ImportRunner($jobs, $problems),
            new AccessGuard($env->authorization),
        ))->execute(Principal::user(9), $importer->key(), null, ['dry_run' => false]);

        self::assertSame(
            ImportJob::STATUS_FAILED,
            $job->status,
            'The status the operator set, which the late finish may not write over.',
        );
        self::assertSame(
            1,
            $problems->page($job->id, 1, 25)->total,
            'The one row reported before they ended it, and none of the four after.',
        );
    }

    /**
     * An importer that reports one row, has the job ended under it, and then
     * reports four more.
     */
    private function abandoningImporter(DatabaseImportJobRepository $jobs): ImporterInterface
    {
        return new class ($jobs) extends AbstractImporter {
            public function __construct(private readonly DatabaseImportJobRepository $jobs)
            {
            }

            public function key(): string
            {
                return 'abandoned';
            }

            public function label(): string
            {
                return 'Abandoned';
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

                $progress->rowProblem($this->row(2));

                // What AbandonImportJob does: a person deciding a stalled run
                // is over, while the process is still alive.
                $this->jobs->markFailed($progress->jobId(), 'Ended by hand.', []);

                foreach ([3, 4, 5, 6] as $row) {
                    $progress->rowProblem($this->row($row));
                }
            }

            private function row(int $row): RowProblem
            {
                return RowProblem::of(
                    'users.csv',
                    $row,
                    'email',
                    RowProblem::CODE_INVALID_EMAIL,
                    'Not an address.',
                    'nope',
                    RowProblem::OUTCOME_FAILED,
                );
            }
        };
    }

    private function env(): TestPorts
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);

        return $env;
    }

    private function runImport(TestPorts $env, ImporterInterface $importer): ImportJob
    {
        $registry = new ImporterRegistry();
        $registry->register($importer);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);

        return (new RunImportJob(
            $jobs,
            new ImportIntake($registry),
            new ImportRunner($jobs, new DatabaseImportRowProblemRepository($env->database)),
            new AccessGuard($env->authorization),
        ))->execute(Principal::user(9), $importer->key(), null, ['dry_run' => false]);
    }

    private function rejectingImporter(): ImporterInterface
    {
        return new class extends AbstractImporter {
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
                $progress->rowOutcome('users.csv', RowProblem::OUTCOME_CREATED);
                $progress->rowProblem(RowProblem::fromFailure(
                    'users.csv',
                    4,
                    'email',
                    RowProblem::CODE_INVALID_EMAIL,
                    \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::forField(
                        'email',
                        'An address is required.',
                    ),
                    'not-an-address',
                ));
                $progress->rowProblem(RowProblem::of(
                    'enrollments.csv',
                    2,
                    'classSourcedId',
                    RowProblem::CODE_REFERENTIAL_MISS,
                    'No class carries sourcedId "c-9".',
                    'c-9',
                    RowProblem::OUTCOME_SKIPPED,
                ));
                $progress->note(ImportNote::ERROR, 'Two rows did not land.');
            }
        };
    }

    private function hostileImporter(): ImporterInterface
    {
        return new class extends AbstractImporter {
            public function key(): string
            {
                return 'hostile';
            }

            public function label(): string
            {
                return 'Hostile';
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

                $progress->rowProblem(RowProblem::of(
                    '=danger.csv',
                    2,
                    '@column',
                    RowProblem::CODE_MISSING_REQUIRED,
                    '+message that starts with a plus',
                    '=cmd|\' /c calc\'!A1',
                    RowProblem::OUTCOME_FAILED,
                ));
                $progress->rowProblem(RowProblem::of(
                    'danger.csv',
                    3,
                    'name',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'A name is required.',
                    '@SUM(1+1)*cmd',
                    RowProblem::OUTCOME_SKIPPED,
                ));
                $progress->rowProblem(RowProblem::of(
                    'danger.csv',
                    4,
                    'name',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'A name is required.',
                    "\u{FF1D}1+1",
                    RowProblem::OUTCOME_SKIPPED,
                ));
            }
        };
    }

    /** @param ArrayObject<int, string> $log */
    private function preflightingImporter(ArrayObject $log): AbstractImporter
    {
        return new class ($log) extends AbstractImporter implements PreflightsBeforeWriting {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            public function key(): string
            {
                return 'preflighted';
            }

            public function label(): string
            {
                return 'Preflighted';
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
                $this->log->append($progress->isDryRun() ? 'dry' : 'real');

                if (!$progress instanceof RecordsRowProblems) {
                    return;
                }

                $progress->rowOutcome('people.csv', RowProblem::OUTCOME_CREATED);
                $progress->rowProblem(RowProblem::of(
                    'people.csv',
                    3,
                    'unit_path',
                    RowProblem::CODE_REFERENTIAL_MISS,
                    'No unit carries that path.',
                    'east/none',
                    RowProblem::OUTCOME_SKIPPED,
                ));
            }
        };
    }

    /** @param ArrayObject<int, string> $log */
    private function stagedImporter(ArrayObject $log): AbstractImporter
    {
        return new class ($log) extends AbstractImporter implements StagedImporterInterface {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            public function key(): string
            {
                return 'staged';
            }

            public function label(): string
            {
                return 'Staged';
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
                throw new \LogicException('A staged importer is driven stage by stage.');
            }

            /**
             * @return list<string>
             */
            public function stages(string $sourcePath, array $options): array
            {
                // Declared backwards on purpose.
                return [
                    ImportLoadOrder::STAGE_RELEASES,
                    ImportLoadOrder::STAGE_PEOPLE,
                    ImportLoadOrder::STAGE_ORGANISATIONS,
                ];
            }

            public function importStage(
                string $stage,
                string $sourcePath,
                array $options,
                Principal $principal,
                ImportProgress $progress,
            ): void {
                $this->log->append($stage);
            }
        };
    }

    /** @param ArrayObject<int, string> $log */
    private function tenantedImporter(ArrayObject $log): AbstractImporter
    {
        return new class ($log) extends AbstractImporter implements DeclaresSourceTypes {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            public function key(): string
            {
                return 'tenanted';
            }

            public function label(): string
            {
                return 'Tenanted';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            /**
             * @return list<string>
             */
            public function sourceTypes(): array
            {
                return [ImportSourceKey::TYPE_ORG_MEMBER, 'course'];
            }

            public function importWithProgress(
                string $sourcePath,
                array $options,
                Principal $principal,
                ImportProgress $progress,
            ): void {
                $this->log->append('ran');
            }
        };
    }
}
