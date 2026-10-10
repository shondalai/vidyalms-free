<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\AbandonImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportIntake;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRunner;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Tests\Support\FrozenClock;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class RunImportJobTest extends TestCase
{
    public function testASuccessfulImportCompletesWithTotalsAndLog(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register($this->succeedingImporter());

        $job = $this->useCase($env, $registry)->execute(
            Principal::user(9),
            'stub',
            'imports/9/backup.mbz',
            ['dry_run' => false],
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame('stub', $job->importer);
        self::assertSame('imports/9/backup.mbz', $job->sourcePath);
        self::assertSame(['dry_run' => false], $job->options);
        self::assertSame(['courses' => 2, 'lessons' => 5], $job->totals);
        self::assertSame("course A\ncourse B", $job->log);
        self::assertSame(9, $job->createdBy);
        self::assertNotNull($job->startedAt);
        self::assertNotNull($job->finishedAt);
        self::assertFalse($job->isDryRun());
    }

    public function testAFailingImporterMarksTheJobFailedWithTheError(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register($this->failingImporter());

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'boom', null);

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertSame('[error] bad archive', $job->log);
        self::assertNull($job->totals);
        self::assertNotNull($job->finishedAt);
    }

    public function testAPartialFailureKeepsEverythingTheImporterCompleted(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register(new class extends AbstractImporter {
            public function key(): string
            {
                return 'half';
            }

            public function label(): string
            {
                return 'Half importer';
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
                $progress->counted(ImportResult::TOTAL_COURSES);
                $progress->note(ImportNote::INFO, 'Course "Tea" created.');
                $progress->counted(ImportResult::TOTAL_LESSONS, 3);
                $progress->note(ImportNote::WARNING, 'Lesson 4 has no body.');

                throw new \RuntimeException('the archive ended early');
            }
        });

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'half', 'imports/9/half.zip');

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertSame(['courses' => 1, 'lessons' => 3], $job->totals);
        self::assertSame(
            "Course \"Tea\" created.\n[warning] Lesson 4 has no body.\n[error] the archive ended early",
            $job->log,
        );
    }

    public function testNotesReachTheJobLogWhileTheImportIsStillRunning(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $importer = new class ($env) extends AbstractImporter {
            public ?string $seen = null;

            public function __construct(
                private readonly TestPorts $env,
            ) {
            }

            public function key(): string
            {
                return 'watched';
            }

            public function label(): string
            {
                return 'Watched importer';
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
                $progress->note(ImportNote::INFO, 'first');

                $row = $this->env->database->selectOne(
                    Query::from('import_jobs')->where('id', '=', $progress->jobId()),
                );
                /** @var mixed $log */
                $log = $row['log'] ?? null;
                $this->seen = \is_string($log) ? $log : null;

                $progress->note(ImportNote::INFO, 'second');
            }
        };

        $registry = new ImporterRegistry();
        $registry->register($importer);

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'watched', null);

        self::assertSame('first', $importer->seen, 'The first note must be readable before the import ends.');
        self::assertSame("first\nsecond", $job->log);
        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
    }

    public function testAChattyImportKeepsEveryNoteEvenThoughFlushingBacksOff(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register(new class extends AbstractImporter {
            public function key(): string
            {
                return 'chatty';
            }

            public function label(): string
            {
                return 'Chatty importer';
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
                for ($i = 1; $i <= 65; $i++) {
                    $progress->counted(ImportResult::TOTAL_QUESTIONS);
                    $progress->note(ImportNote::INFO, 'note ' . $i);
                }
            }
        });

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'chatty', null);

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(['questions' => 65], $job->totals);
        self::assertCount(65, explode("\n", (string) $job->log));
        self::assertStringEndsWith('note 65', (string) $job->log);
    }

    public function testADryRunEndsAsPreviewedAndTellsTheImporterNotToWrite(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register(new class extends AbstractImporter {
            public function key(): string
            {
                return 'peek';
            }

            public function label(): string
            {
                return 'Peeking importer';
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
                if (!$progress->isDryRun()) {
                    throw new \RuntimeException('The dry run flag never reached the importer.');
                }

                $progress->counted(ImportResult::TOTAL_COURSES);
                $progress->counted(ImportResult::TOTAL_LESSONS, 12);
                $progress->note(ImportNote::INFO, 'Course "Tea" would be created.');
            }
        });

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'peek', 'imports/9/tea.json', ['dry_run' => 1]);

        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertTrue($job->isDryRun());
        self::assertSame(['dry_run' => true], $job->options, 'The stored flag must be a real boolean.');
        self::assertSame(['courses' => 1, 'lessons' => 12], $job->totals);
        self::assertSame('Course "Tea" would be created.', $job->log);
    }

    /**
     * A run that only counts says nothing, and a reader that mistook silence
     * for death would show a healthy import as stopped responding. So the
     * heartbeat moves on progress of any kind, and the check happens while
     * the importer is still counting rather than after it has finished.
     */
    public function testALongCountingPhaseKeepsTheJobAliveWithoutWritingANote(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $importer = new class ($env->clock, new DatabaseImportJobRepository($env->database, $env->clock)) extends AbstractImporter {
            public bool $stalledWhileCounting = true;

            public int $counted = 0;

            public function __construct(
                private readonly FrozenClock $clock,
                private readonly DatabaseImportJobRepository $jobs,
            ) {
            }

            public function key(): string
            {
                return 'counter';
            }

            public function label(): string
            {
                return 'Counting importer';
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
                // Half an hour of counting and not one note, which is what a
                // dry run over a large source looks like.
                for ($row = 0; $row < 300; $row++) {
                    if ($row % 100 === 0) {
                        $this->clock->advance('PT10M');
                    }

                    $progress->counted(ImportResult::TOTAL_LEARNERS);
                    $this->counted++;
                }

                $job = $this->jobs->find($progress->jobId());
                $this->stalledWhileCounting = $job === null || $job->hasStalled($this->clock->now());
            }
        };

        $registry->register($importer);

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'counter', null, ['dry_run' => true]);

        self::assertSame(300, $importer->counted);
        self::assertFalse(
            $importer->stalledWhileCounting,
            'A run that counts without noting must still be reported as alive.',
        );
        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertSame(['learners' => 300], $job->totals);
    }

    /**
     * The race the admin screen makes possible: an operator ends a run that
     * stopped responding, the process was not dead after all, and it finishes
     * afterwards. Their decision and their note stand, because the process
     * cannot know what has already been done about them, and the late finish
     * is appended rather than written over the top.
     */
    public function testARunThatFinishesAfterAnOperatorEndedItLeavesTheirDecisionStanding(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $registry = new ImporterRegistry();
        $registry->register(new class (
            $env->clock,
            new AbandonImportJob($jobs, new AccessGuard($env->authorization), $env->clock),
        ) extends AbstractImporter {
            public function __construct(
                private readonly FrozenClock $clock,
                private readonly AbandonImportJob $abandon,
            ) {
            }

            public function key(): string
            {
                return 'stalling';
            }

            public function label(): string
            {
                return 'Stalling importer';
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
                $progress->counted(ImportResult::TOTAL_COURSES);
                $progress->note(ImportNote::INFO, 'Course "Tea" created.');

                // The run goes quiet long enough to look dead, and an
                // operator ends it from the admin screen.
                $this->clock->advance('PT31M');
                $this->abandon->execute(Principal::user(9), $progress->jobId());

                // And then it finishes after all.
                $progress->counted(ImportResult::TOTAL_LESSONS, 11);
                $progress->note(ImportNote::INFO, 'Lesson "Water" created.');
            }
        });

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'stalling', 'imports/9/tea.mbz');
        $log = (string) $job->log;

        self::assertSame(ImportJob::STATUS_FAILED, $job->status, 'A status a person set is not rewritten by the run they ended.');
        self::assertStringContainsString('[error] ' . AbandonImportJob::NOTE, $log, 'The operator\'s note survives.');
        self::assertStringContainsString('Course "Tea" created.', $log, 'And so does what the run had written by then.');

        $lines = explode("\n", $log);
        self::assertStringContainsString(
            'The process running this import finished after the job had been marked "failed" here',
            end($lines),
            'The late finish is appended, and appended last.',
        );
        self::assertStringContainsString('courses 1, lessons 11', $log, 'The note says what the run had done.');
        self::assertSame(
            ['courses' => 1, 'lessons' => 11],
            $job->totals,
            'The totals are the run\'s own: those rows exist, whatever the status says.',
        );
    }

    /**
     * The same race with the late run failing rather than finishing: the
     * operator's status still stands, and the reason the run gives is kept
     * rather than lost with it.
     */
    public function testARunThatFailsAfterAnOperatorEndedItAppendsItsReasonWithoutTakingTheRowBack(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $registry = new ImporterRegistry();
        $registry->register(new class (
            $env->clock,
            new AbandonImportJob($jobs, new AccessGuard($env->authorization), $env->clock),
        ) extends AbstractImporter {
            public function __construct(
                private readonly FrozenClock $clock,
                private readonly AbandonImportJob $abandon,
            ) {
            }

            public function key(): string
            {
                return 'stalling-then-failing';
            }

            public function label(): string
            {
                return 'Stalling importer that then fails';
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
                $progress->counted(ImportResult::TOTAL_COURSES);
                $progress->note(ImportNote::INFO, 'Course "Tea" created.');
                $this->clock->advance('PT31M');
                $this->abandon->execute(Principal::user(9), $progress->jobId());

                throw new \RuntimeException('the archive ended early');
            }
        });

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'stalling-then-failing', null);
        $log = (string) $job->log;

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('[error] ' . AbandonImportJob::NOTE, $log, 'The operator\'s note survives.');
        self::assertStringContainsString('[error] the archive ended early', $log, 'And the run says why it ended.');
        self::assertSame(['courses' => 1], $job->totals);
    }

    public function testAnUnknownImporterIsRejectedBeforeAnyRowIsWritten(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register($this->succeedingImporter());

        try {
            $this->useCase($env, $registry)->execute(Principal::user(9), 'nope', null);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('importer', $exception->errors);
            self::assertStringContainsString('Registered importers: stub.', $exception->errors['importer']);
        }

        self::assertSame(0, $env->database->count(Query::from('import_jobs')));
    }

    public function testTheRefusalNamesAnEmptyRegistryPlainly(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);

        try {
            $this->useCase($env, new ImporterRegistry())->execute(Principal::user(9), 'nope', null);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Registered importers: none.', $exception->errors['importer']);
        }
    }

    public function testAGuestMayNotImport(): void
    {
        $env = TestPorts::create();

        $this->expectException(NotAuthorizedException::class);
        $this->useCase($env, new ImporterRegistry())->execute(Principal::guest(), 'stub', null);
    }

    public function testAnImportCannotReadAnotherUploadOrAnInternalPrivateFile(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register($this->succeedingImporter());
        foreach (['certificates/1.pdf', 'imports/8/source.json', 'imports/9/../8/source.json',
            'imports/9/.secrets/key', '/imports/9/source.json'] as $path) {
            try {
                $this->useCase($env, $registry)->execute(Principal::user(9), 'stub', $path);
                self::fail('Unsafe import source accepted: ' . $path);
            } catch (ValidationException $failure) {
                self::assertArrayHasKey('source', $failure->errors);
            }
        }
        self::assertSame(0, $env->database->count(Query::from('import_jobs')));
    }

    public function testAUserWithoutTheManageCapabilityMayNotImport(): void
    {
        $env = TestPorts::create();
        $registry = new ImporterRegistry();
        $registry->register($this->succeedingImporter());

        $this->expectException(NotAuthorizedException::class);
        $this->useCase($env, $registry)->execute(Principal::user(9), 'stub', null);
    }

    /**
     * Every entry point coerces an option the same way, because the coercion
     * is the importer's declaration and it runs in the intake.
     *
     * ImportOption::read() exists so that "the same form on two CMSes cannot
     * mean two things", and it was called from exactly two places: the Joomla
     * screen and the WordPress screen. Everything arriving over the API
     * skipped it, so a choice outside the declared values and a boolean
     * arriving as the string "no" both reached import() verbatim.
     */
    public function testADeclaredOptionIsCoercedWhereverTheRunWasStartedFrom(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $importer = $this->configurableImporter();
        $registry->register($importer);

        $job = $this->useCase($env, $registry)->execute(Principal::user(9), 'configurable', null, [
            // A choice the importer never offered, and a boolean as the word
            // an operator would say rather than as one.
            'shape' => 'delete_everything',
            'careful' => 'no',
            'dry_run' => true,
        ]);

        self::assertArrayNotHasKey('shape', $job->options, 'A choice outside the declared values is dropped.');
        self::assertFalse($job->options['careful'], '"no" is false, not a non-empty string.');
        self::assertTrue($job->options['dry_run'], 'And the pipeline still owns its own key.');
        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
    }

    /**
     * A queued job's options are read back by the shape that wrote them.
     *
     * import_jobs.options is provider-owned JSON, RunQueuedImport reads it at
     * drain time days later and after a plugin upgrade, and the column carried
     * no version at all: a replaced importer read the map as though it had
     * written it. The envelope never leaves the repository, so the body an
     * importer sees is unchanged.
     */
    public function testAJobsOptionsAreStoredWithTheShapeTheImporterWrote(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $registry = new ImporterRegistry();
        $registry->register($this->configurableImporter());
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);

        $job = (new RunImportJob(
            $jobs,
            new ImportIntake($registry),
            new ImportRunner($jobs),
            new AccessGuard($env->authorization),
        ))->execute(Principal::user(9), 'configurable', null, ['shape' => 'square', 'dry_run' => true]);

        self::assertSame(1, $job->optionsVersion, 'An importer declaring nothing writes shape 1.');
        self::assertSame('square', $job->options['shape'] ?? null);
        self::assertArrayNotHasKey(
            '_v',
            $job->options,
            'The envelope never leaves the repository, so the importer sees the body alone.',
        );

        $stored = $env->database->selectOne(Query::from('import_jobs')->where('id', '=', $job->id));
        self::assertNotNull($stored);
        self::assertStringContainsString('"_v":1', (string) $stored['options'], 'And it is on the row.');
    }

    private function configurableImporter(): ImporterInterface
    {
        return new class extends AbstractImporter implements
            \Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface
        {
            public function key(): string
            {
                return 'configurable';
            }

            public function label(): string
            {
                return 'Configurable';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            public function importOptions(): array
            {
                return [
                    \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::choice('shape', 'Shape', [
                        ['value' => 'round', 'label' => 'Round'],
                        ['value' => 'square', 'label' => 'Square'],
                    ], '', 'round'),
                    \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::boolean('careful', 'Careful'),
                ];
            }

            public function importWithProgress(
                string $sourcePath,
                array $options,
                Principal $principal,
                ImportProgress $progress,
            ): void {
                $progress->note(ImportNote::INFO, 'ran');
            }
        };
    }

    private function useCase(TestPorts $env, ImporterRegistry $registry): RunImportJob
    {
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);

        return new RunImportJob(
            $jobs,
            new ImportIntake($registry),
            new ImportRunner($jobs),
            new AccessGuard($env->authorization),
        );
    }

    private function succeedingImporter(): ImporterInterface
    {
        return new class implements ImporterInterface {
            public function key(): string
            {
                return 'stub';
            }

            public function label(): string
            {
                return 'Stub importer';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            public function import(string $sourcePath, array $options, Principal $principal): array
            {
                return [
                    'totals' => ['courses' => 2, 'lessons' => 5],
                    'log' => ['course A', 'course B'],
                ];
            }
        };
    }

    private function failingImporter(): ImporterInterface
    {
        return new class implements ImporterInterface {
            public function key(): string
            {
                return 'boom';
            }

            public function label(): string
            {
                return 'Failing importer';
            }

            public function supports(string $sourcePath): bool
            {
                return true;
            }

            public function import(string $sourcePath, array $options, Principal $principal): array
            {
                throw new \RuntimeException('bad archive');
            }
        };
    }
    /**
     * The narrower half of the same guarantee: not "the run noticed in time"
     * but "the write cannot land". Every terminal write matches only a job
     * still marked running, so there is no window between deciding and
     * writing for an operator's decision to be lost in.
     */
    public function testATerminalWriteOnlyLandsOnAJobThatIsStillRunning(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);

        $jobId = $jobs->create('json-course', 'imports/9/tea.json', [], 9);
        $jobs->markRunning($jobId);

        // The run goes quiet long enough for the screen to offer the button,
        // then an operator ends it. Whatever the running process does next,
        // these are the values that must survive.
        $env->clock->advance('PT31M');

        (new AbandonImportJob($jobs, new AccessGuard($env->authorization), $env->clock))
            ->execute(Principal::user(9), $jobId);

        $ended = $jobs->find($jobId);
        self::assertNotNull($ended);
        self::assertSame(ImportJob::STATUS_FAILED, $ended->status);

        // The late process ends through the guarded finisher, which is the
        // only way a run may end a job. Every status it could report loses,
        // and none of them changes the row.
        foreach ([ImportJob::STATUS_COMPLETED, ImportJob::STATUS_PREVIEWED, ImportJob::STATUS_FAILED] as $attempt) {
            self::assertFalse(
                $jobs->finishIfRunning($jobId, $attempt, 'rewritten log', ['courses' => 99]),
                sprintf('A late run cannot end the job as %s.', $attempt),
            );
        }

        $after = $jobs->find($jobId);
        self::assertNotNull($after);
        self::assertSame(ImportJob::STATUS_FAILED, $after->status);
        self::assertSame($ended->log, $after->log, 'The operator note is untouched.');
        self::assertStringNotContainsString('rewritten log', (string) $after->log);
    }
}
