<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRecorder;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\Guru\GuruImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\Guru\GuruSource;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\JoomlaSourceImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\HonoursUpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\JoomlaSourceFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The Guru importer against a Guru-shaped database, under the product's own
 * older words: a course is a program, a module is a day, a lesson is a task,
 * and access is a purchase.
 *
 * Guru publishes no schema at all, so this is the most speculative of the
 * three Joomla importers and its fixture is entirely the importer's documented
 * expectation. What these tests prove is the behaviour that has to hold
 * whatever the columns turn out to be called: the vocabulary mapping, the
 * refusals, the idempotency, the upsert choices, and that an unreadable table
 * costs that table and nothing else.
 */
final class GuruImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const BUYER_ID = 4;
    private const CUSTOMER_BUYER_ID = 5;
    private const SOURCE = 'cms-db:';

    private const PROGRAM = 2;
    private const DAY = 6;
    private const TEXT_TASK = 10;
    private const VIDEO_TASK = 11;
    private const QUIZ_TASK = 12;
    private const AUDIO_TASK = 13;
    private const QUIZ = 18;

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $admin;
    private JoomlaSourceFixture $source;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::ENROL_OTHERS);
        $this->env->users->add(self::ADMIN_ID, 'Ada Admin');
        $this->env->users->add(self::BUYER_ID, 'Bo Buyer', 'bo@example.test');
        $this->env->users->add(self::CUSTOMER_BUYER_ID, 'Cass Customer', 'cass@example.test');
        $this->kernel = new CoreKernel($this->env->ports);
        $this->admin = Principal::user(self::ADMIN_ID);
        $this->source = JoomlaSourceFixture::guru($this->env->database);
        $this->seed();
    }

    public function testItIsRegisteredAndDeclaresItselfHonestly(): void
    {
        self::assertContains(GuruImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(GuruImporter::KEY);

        self::assertInstanceOf(HonoursUpsertPolicy::class, $importer);
        self::assertSame(UpsertPolicy::POLICIES, $importer->upsertPolicies());
        self::assertTrue($importer->supports(self::SOURCE));

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';
        self::assertStringContainsString('unverified against a real install', $catalogue['vidyalms.importer.guru']);
    }

    public function testItRefusesWhenGuruIsNotInstalled(): void
    {
        $bare = TestPorts::create();
        $bare->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $bare->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $bare->users->add(self::ADMIN_ID, 'Ada Admin');
        $kernel = new CoreKernel($bare->ports);

        self::assertFalse($kernel->registries->importers->get(GuruImporter::KEY)->supports(self::SOURCE));

        try {
            $kernel->runImportJob->execute($this->admin, GuruImporter::KEY, self::SOURCE, []);
            self::fail('Unavailable import sources must be rejected before queuing.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException $failure) {
            self::assertArrayHasKey('source', $failure->errors);
        }
        self::assertSame(0, $bare->database->count(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('import_jobs')));
    }

    public function testAProgramBecomesACourseAndItsDaysBecomeModules(): void
    {
        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);

        $course = $this->courseTitled('Selling with Guru');
        self::assertSame(CourseState::Draft->value, $course['state']);
        self::assertSame('A short course', $course['summary']);

        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(['Day one'], array_column($modules, 'title'), 'a Guru day is a module');

        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(
            ['reading', 'video', 'quiz'],
            array_column($lessons, 'type'),
            'the audio task has no free equivalent and is refused rather than converted',
        );
    }

    public function testTheQuestionAnswersAreReadOutOfTheQuestionRow(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Selling with Guru');
        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('course_id', '=', (int) $course['id']));

        self::assertNotNull($quiz);
        self::assertSame('Day one quiz', $quiz['title']);
        self::assertSame(1, (int) $quiz['is_final'], 'a Guru final exam stays a final quiz here');

        $questions = $this->env->database->selectAll(
            Query::from('questions')->where('quiz_id', '=', (int) $quiz['id'])->orderBy('position'),
        );
        self::assertSame(
            ['single_choice', 'multiple_choice'],
            array_column($questions, 'type'),
            'the flat answer map and the list of answer objects both read',
        );
    }

    public function testAQuestionWhoseAnswersCannotBeReadIsARowProblemRatherThanAFailedJob(): void
    {
        $this->source->insert('guru_questions', [
            'id' => 64,
            'quiz_id' => self::QUIZ,
            'question' => 'What is in this column',
            'type' => 'radio',
            'answers' => 'a:2:{i:0;s:3:"one";i:1;s:3:"two";}',
            'points' => 1,
            'ordering' => 9,
        ]);

        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $job->status,
            'a serialised answer column is refused, not unserialised, and it costs one row',
        );

        $problem = $this->problemForRow($this->rowProblems(), 64);
        self::assertSame(GuruSource::T_QUESTIONS, $problem->file);
        self::assertSame(RowProblem::CODE_UNMAPPED_ROLE, $problem->code);
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $problem->outcome);
        self::assertStringContainsString('answers could not be read', $problem->message);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));
    }

    public function testAnUnreadableDayTableCostsTheLessonsAndNotTheCourse(): void
    {
        $this->source->drop('guru_days');

        $job = $this->runImport();

        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('guru_days', (string) $job->log);
        self::assertSame(1, $this->env->database->count(Query::from('courses')), 'the course still landed');
        self::assertSame(0, $this->env->database->count(Query::from('lessons')));
    }

    public function testASecondRunUpdatesRatherThanDuplicating(): void
    {
        $this->runImport();
        $this->source->update('guru_program', ['name' => 'Selling with Guru, second run'], ['id' => self::PROGRAM]);

        $this->runImport();

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame(3, $this->env->database->count(Query::from('lessons')));
        self::assertNotNull($this->env->database->selectOne(
            Query::from('courses')->where('title', '=', 'Selling with Guru, second run'),
        ));
    }

    public function testTheFourUpsertChoicesDoWhatTheySay(): void
    {
        $job = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]);
        self::assertSame(0, $this->env->database->count(Query::from('courses')), 'update_only creates nothing');
        self::assertStringContainsString('were not created', (string) $job->log);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));

        $this->source->update('guru_program', ['name' => 'Renamed in Guru'], ['id' => self::PROGRAM]);
        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        self::assertNotNull(
            $this->env->database->selectOne(Query::from('courses')->where('title', '=', 'Selling with Guru')),
            'create_only leaves what is already here alone',
        );

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_AND_UPDATE]);
        self::assertNotNull(
            $this->env->database->selectOne(Query::from('courses')->where('title', '=', 'Renamed in Guru')),
        );

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);
        self::assertSame(2, $this->env->database->count(Query::from('courses')));
    }

    public function testAPurchaseBecomesAccessAndACustomerRowResolvesTheBuyer(): void
    {
        $withoutThem = $this->runImport();
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));
        self::assertStringContainsString('learner(s) in the source', (string) $withoutThem->log);

        $job = $this->runImport([JoomlaSourceImporter::OPTION_LEARNERS => true]);

        $userIds = array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->env->database->selectAll(Query::from('entitlements')->orderBy('user_id')),
        );
        self::assertSame(
            [self::BUYER_ID, self::CUSTOMER_BUYER_ID],
            $userIds,
            'a purchase naming a customer is resolved through guru_customer',
        );
        self::assertStringContainsString(
            'repeat purchase(s)',
            (string) $job->log,
            'a second order for the same person is access they already have',
        );
    }

    public function testTheCurriculumOnlyChoiceLeavesTheQuizBehind(): void
    {
        $job = $this->runImport([JoomlaSourceImporter::OPTION_CONTENT => JoomlaSourceImporter::CONTENT_CURRICULUM]);

        self::assertSame(0, $this->env->database->count(Query::from('quizzes')));
        self::assertSame(2, $this->env->database->count(Query::from('lessons')));
        self::assertStringContainsString('quiz lesson(s) were not imported', (string) $job->log);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute($this->admin, GuruImporter::KEY, self::SOURCE, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<RowProblem>
     */
    private function rowProblems(array $options = []): array
    {
        $collected = [];
        $recorder = new ImportRecorder(0, false, null, null, static function (RowProblem $problem) use (&$collected): void {
            $collected[] = $problem;
        });

        $importer = $this->kernel->registries->importers->get(GuruImporter::KEY);
        self::assertInstanceOf(GuruImporter::class, $importer);
        $importer->importWithProgress(self::SOURCE, $options, $this->admin, $recorder);

        return $collected;
    }

    /**
     * @param list<RowProblem> $problems
     */
    private function problemForRow(array $problems, int $row): RowProblem
    {
        foreach ($problems as $problem) {
            if ($problem->row === $row) {
                return $problem;
            }
        }

        self::fail(sprintf('no row problem was recorded for source row %d', $row));
    }

    /**
     * @return array<string, mixed>
     */
    private function courseTitled(string $title): array
    {
        $row = $this->env->database->selectOne(Query::from('courses')->where('title', '=', $title));
        self::assertNotNull($row, sprintf('no course titled %s was imported', $title));

        return $row;
    }

    private function seed(): void
    {
        $this->source->insert('guru_program', [
            'id' => self::PROGRAM,
            'name' => 'Selling with Guru',
            'intro' => 'A short course',
            'description' => 'How the product is meant to be used.',
            'difficulty' => 'beginner',
            'access' => 1,
            'published' => 1,
        ]);
        $this->source->insert('guru_days', [
            'id' => self::DAY,
            'program_id' => self::PROGRAM,
            'name' => 'Day one',
            'description' => 'The opening day.',
            'ordering' => 1,
        ]);

        $this->source->insert('guru_tasks', [
            'id' => self::TEXT_TASK,
            'day_id' => self::DAY,
            'name' => 'Read this first',
            'description' => '<p>Start here.</p>',
            'type' => 'text',
            'duration' => 4,
            'ordering' => 1,
        ]);
        $this->source->insert('guru_tasks', [
            'id' => self::VIDEO_TASK,
            'day_id' => self::DAY,
            'name' => 'Then watch this',
            'description' => 'A recording of the session.',
            'url' => 'https://www.youtube.com/watch?v=guru',
            'type' => 'video',
            'duration' => 12,
            'ordering' => 2,
        ]);
        $this->source->insert('guru_tasks', [
            'id' => self::QUIZ_TASK,
            'day_id' => self::DAY,
            'name' => 'Then answer this',
            'type' => 'quiz',
            'ordering' => 3,
        ]);
        $this->source->insert('guru_tasks', [
            'id' => self::AUDIO_TASK,
            'day_id' => self::DAY,
            'name' => 'The podcast',
            'type' => 'audio',
            'ordering' => 4,
        ]);

        $this->source->insert('guru_quiz', [
            'id' => self::QUIZ,
            'task_id' => self::QUIZ_TASK,
            'name' => 'Day one quiz',
            'description' => 'Two questions.',
            'pass_score' => 60,
            'attempts' => 3,
            'time_limit' => 15,
            'is_final' => 1,
            'ordering' => 1,
        ]);

        // The flat shape a component that grew its answers out of a form post
        // writes, and the list of objects a later one writes. Both read.
        $this->source->insert('guru_questions', [
            'id' => 61,
            'quiz_id' => self::QUIZ,
            'question' => 'Which one is a Guru module',
            'type' => 'radio',
            'answers' => ['A day' => 1, 'A task' => 0, 'A program' => 0],
            'points' => 2,
            'ordering' => 1,
        ]);
        $this->source->insert('guru_questions', [
            'id' => 62,
            'quiz_id' => self::QUIZ,
            'question' => 'Which of these does Guru sell',
            'type' => 'checkbox',
            'answers' => [
                ['text' => 'Courses', 'correct' => 1],
                ['text' => 'Subscriptions', 'correct' => 1],
                ['text' => 'Nothing', 'correct' => 0],
            ],
            'points' => 3,
            'ordering' => 2,
        ]);

        $this->source->insert('guru_buy', [
            'id' => 71,
            'program_id' => self::PROGRAM,
            'user_id' => self::BUYER_ID,
            'customer_id' => 0,
        ]);
        $this->source->insert('guru_buy', [
            'id' => 72,
            'program_id' => self::PROGRAM,
            'user_id' => 0,
            'customer_id' => 81,
        ]);
        $this->source->insert('guru_buy', [
            'id' => 73,
            'program_id' => self::PROGRAM,
            'user_id' => self::BUYER_ID,
            'customer_id' => 0,
        ]);
        $this->source->insert('guru_customer', [
            'id' => 81,
            'user_id' => self::CUSTOMER_BUYER_ID,
            'name' => 'Cass Customer',
        ]);
    }
}
