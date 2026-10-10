<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\PruneImportSources;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Uploaded import sources are kept for a window and then removed, because a
 * finished job is exactly the one an operator retries. The window is the whole
 * contract: inside it the file is there, outside it the file is gone and the
 * job stops claiming to have one.
 */
final class PruneImportSourcesTest extends TestCase
{
    public function testASourceIsRemovedOnceItsRunHasBeenFinishedLongerThanTheWindow(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $env->storage->putPrivate('imports/9/course.mbz', 'archive bytes');

        $jobId = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);
        $jobs->markRunning($jobId);
        $jobs->markCompleted($jobId, 'Done.', ['courses' => 1]);

        $env->clock->advance('P31D');
        $result = $this->useCase($env)->execute(30);

        self::assertSame(1, $result['deleted']);
        self::assertSame(1, $result['cleared']);
        self::assertFalse($env->storage->exists('imports/9/course.mbz'));

        $job = $jobs->find($jobId);
        self::assertNotNull($job);
        self::assertNull($job->sourcePath, 'A job must stop naming a source that no longer exists.');
        self::assertSame('Done.', $job->log, 'What the run did is history and is kept.');
        self::assertSame(['courses' => 1], $job->totals);
    }

    public function testASourceInsideTheWindowIsKept(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $env->storage->putPrivate('imports/9/course.mbz', 'archive bytes');

        $jobId = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);
        $jobs->markRunning($jobId);
        $jobs->markFailed($jobId, 'Stopped after eleven lessons.', ['lessons' => 11]);

        $env->clock->advance('P29D');
        $result = $this->useCase($env)->execute(30);

        self::assertSame(0, $result['deleted']);
        self::assertTrue($env->storage->exists('imports/9/course.mbz'));
        self::assertSame('imports/9/course.mbz', $jobs->find($jobId)?->sourcePath);
    }

    public function testASourceARetryStillNeedsIsKeptEvenWhenTheFirstRunIsAncient(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $env->storage->putPrivate('imports/9/course.mbz', 'archive bytes');

        $first = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);
        $jobs->markRunning($first);
        $jobs->markFailed($first, 'Stopped.', []);

        $env->clock->advance('P40D');

        // The Import screen's Retry: the same source queued again.
        $retry = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);

        $result = $this->useCase($env)->execute(30);

        self::assertSame(0, $result['deleted'], 'A queued retry still needs the file the first run used.');
        self::assertTrue($env->storage->exists('imports/9/course.mbz'));
        self::assertSame('imports/9/course.mbz', $jobs->find($first)?->sourcePath);
        self::assertSame('imports/9/course.mbz', $jobs->find($retry)?->sourcePath);
    }

    public function testEveryJobNamingAPrunedSourceForgetsIt(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $env->storage->putPrivate('imports/9/course.mbz', 'archive bytes');

        $first = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);
        $jobs->markRunning($first);
        $jobs->markFailed($first, 'Stopped.', []);

        $second = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);
        $jobs->markRunning($second);
        $jobs->markCompleted($second, 'Done.', ['courses' => 1]);

        $env->clock->advance('P31D');
        $result = $this->useCase($env)->execute(30);

        self::assertSame(1, $result['deleted']);
        self::assertSame(2, $result['cleared']);
        self::assertNull($jobs->find($first)?->sourcePath);
        self::assertNull($jobs->find($second)?->sourcePath);
    }

    public function testADatabaseSourceIsNeverTouched(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);

        $jobId = $jobs->create('community-quiz', 'cms-db:categories=3,7', [], 9);
        $jobs->markRunning($jobId);
        $jobs->markCompleted($jobId, 'Done.', ['quizzes' => 4]);

        $env->clock->advance('P400D');
        $result = $this->useCase($env)->execute(30);

        self::assertSame(0, $result['deleted']);
        self::assertSame(
            'cms-db:categories=3,7',
            $jobs->find($jobId)?->sourcePath,
            'Nothing was uploaded, so the source stays runnable for ever.'
        );
    }

    /**
     * The row report of a database-source run ages on the same clock.
     *
     * The sweep skipped every source that is not a stored file before it
     * reached forgetJob(), and every one of the seven database-source
     * importers takes a scheme source: cms-db:, learndash-db:, tutor-db:,
     * lifterlms-db:. So their row problems, quoting learner ids and source
     * values, were kept for exactly as long as the job row, which is for ever,
     * while the class docblock said the retention window was not a fiction.
     */
    public function testTheRowProblemsOfASchemeSourceAreSweptEvenThoughNoFileIsDeleted(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $problems = new DatabaseImportRowProblemRepository($env->database);

        $jobId = $jobs->create('learndash', 'learndash-db:courses=10', [], 9);
        $jobs->markRunning($jobId);
        $jobs->markCompleted($jobId, 'Done.', ['courses' => 1]);
        $problems->append($jobId, [RowProblem::of(
            'learndash_pro_quiz_question',
            107,
            'answer_data',
            RowProblem::CODE_MISSING_REQUIRED,
            'It marks no correct answer.',
            'lena@example.test',
            RowProblem::OUTCOME_FAILED,
        )->toArray()]);

        self::assertSame(1, $problems->page($jobId, 1, 25)->total);

        $env->clock->advance('P31D');
        $result = $this->useCase($env, $problems)->execute(30);

        self::assertSame(0, $result['deleted'], 'Nothing was uploaded, so no file is deleted.');
        self::assertSame(1, $result['forgotten']);
        self::assertSame(0, $problems->page($jobId, 1, 25)->total);
        self::assertSame(
            'learndash-db:courses=10',
            $jobs->find($jobId)?->sourcePath,
            'And the source stays runnable for ever, which is the half that was right.',
        );
    }

    public function testAPreviewedDryRunReleasesItsSourceLikeAnyOtherFinishedJob(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $env->storage->putPrivate('imports/9/course.json', '{}');

        $jobId = $jobs->create('json-course', 'imports/9/course.json', ['dry_run' => true], 9);
        $jobs->markRunning($jobId);
        $jobs->markPreviewed($jobId, 'Would create one course.', ['courses' => 1]);

        $env->clock->advance('P31D');

        self::assertSame(1, $this->useCase($env)->execute(30)['deleted']);
        self::assertFalse($env->storage->exists('imports/9/course.json'));
    }

    /**
     * The row report is pruned with the source it quotes.
     *
     * import_row_problems.value_excerpt is a verbatim copy of a CSV line that
     * may hold a name or an email address. Keeping it after the file it was
     * copied from has been deleted would make the retention window a fiction:
     * the personal data would outlive its source by as long as the job row
     * lives, which is for ever.
     */
    public function testTheRowProblemsGoWithTheSourceTheyQuote(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $problems = new DatabaseImportRowProblemRepository($env->database);
        $env->storage->putPrivate('imports/9/roster.csv', 'email,given_name');

        $jobId = $jobs->create('roster', 'imports/9/roster.csv', [], 9);
        $jobs->markRunning($jobId);
        $jobs->markCompleted($jobId, 'Done.', ['org_members' => 1]);
        $problems->append($jobId, [RowProblem::of(
            'roster.csv',
            2,
            'email',
            RowProblem::CODE_INVALID_EMAIL,
            'Not an address.',
            'ada@example.test',
            RowProblem::OUTCOME_FAILED,
        )->toArray()]);

        self::assertSame(1, $problems->page($jobId, 1, 25)->total);

        $env->clock->advance('P31D');
        self::assertSame(1, $this->useCase($env, $problems)->execute(30)['deleted']);

        self::assertSame(0, $problems->page($jobId, 1, 25)->total);
        self::assertNotNull($jobs->find($jobId), 'The job itself is history and stays.');
    }

    private function useCase(TestPorts $env, ?DatabaseImportRowProblemRepository $problems = null): PruneImportSources
    {
        return new PruneImportSources(
            new DatabaseImportJobRepository($env->database, $env->clock),
            $env->storage,
            $env->clock,
            $problems,
        );
    }
}
