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
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\JoomlaSourceImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\OSCampus\OSCampusImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\OSCampus\OSCampusSource;
use Shondalai\VidyaLMS\Core\Registry\Importer\HonoursUpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\JoomlaSourceFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The OSCampus importer against an OSCampus-shaped database: the course,
 * module, lesson and progress tables of the component's own published install
 * script, plus the later quiz tables whose shape is published nowhere.
 *
 * These tests prove the mapping, the idempotency, the four upsert choices and
 * the refusals. They deliberately do not prove that the schema is right: no
 * OSCampus is installed on either development site, the importer says so in
 * its label and in every run's log, and a fixture written from the same
 * documentation as the importer cannot contradict it. What it can do is fix
 * the behaviour, so a maintainer with a real install changes column names and
 * finds out immediately whether anything else moved.
 */
final class OSCampusImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'cms-db:';

    private const COURSE = 5;
    private const MODULE = 11;
    private const READING_LESSON = 21;
    private const VIDEO_LESSON = 22;
    private const QUIZ_LESSON = 23;
    private const SCORM_LESSON = 24;
    private const QUIZ = 31;

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
        $this->env->users->add(self::LEARNER_ID, 'Lena Learner', 'lena@example.test');
        $this->kernel = new CoreKernel($this->env->ports);
        $this->admin = Principal::user(self::ADMIN_ID);
        $this->source = JoomlaSourceFixture::oscampus($this->env->database);
        $this->seed();
    }

    public function testItIsRegisteredAndDeclaresItselfHonestly(): void
    {
        self::assertContains(OSCampusImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(OSCampusImporter::KEY);

        self::assertInstanceOf(HonoursUpsertPolicy::class, $importer);
        self::assertSame(UpsertPolicy::POLICIES, $importer->upsertPolicies());

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        self::assertStringContainsString(
            'unverified against a real install',
            $catalogue['vidyalms.importer.oscampus'],
            'the label is where an operator finds out this path has never been run against the real product',
        );
    }

    public function testItRefusesWhenOSCampusIsNotInstalled(): void
    {
        $bare = TestPorts::create();
        $bare->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $bare->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $bare->users->add(self::ADMIN_ID, 'Ada Admin');
        $kernel = new CoreKernel($bare->ports);

        self::assertFalse(
            $kernel->registries->importers->get(OSCampusImporter::KEY)->supports(self::SOURCE),
            'an importer whose product is not on this site does not offer itself',
        );

        try {
            $kernel->runImportJob->execute($this->admin, OSCampusImporter::KEY, self::SOURCE, []);
            self::fail('Unavailable import sources must be rejected before queuing.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException $failure) {
            self::assertArrayHasKey('source', $failure->errors);
        }
        self::assertSame(0, $bare->database->count(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('import_jobs')));
    }

    public function testItBuildsTheCurriculumThroughTheUseCases(): void
    {
        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);

        $course = $this->courseTitled('Joomla Templates');
        self::assertSame(CourseState::Draft->value, $course['state'], 'a migration is a draft until a person publishes it');
        self::assertSame('Beginner course', $course['summary']);

        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(['Getting started'], array_column($modules, 'title'));

        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(
            ['What a template is', 'Watch the override', 'Check yourself'],
            array_column($lessons, 'title'),
            'the SCORM lesson is refused rather than converted, and the rest keep their order',
        );
        self::assertSame(['reading', 'video', 'quiz'], array_column($lessons, 'type'));
    }

    /**
     * `oscampus_lessons.length` carries no comment in the install script, but
     * its sibling `oscampus_courses.length` in the same script is commented
     * "Time to take course in minutes". Read as seconds, a five minute lesson
     * was written as five seconds.
     */
    public function testALessonLengthIsReadAsTheMinutesTheProductsOwnSiblingColumnDeclares(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Joomla Templates');
        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );

        self::assertSame(300 * 60, (int) $lessons[0]['duration_seconds'], '300 in the column is 300 minutes');
        self::assertSame(420 * 60, (int) $lessons[1]['duration_seconds']);
    }

    public function testTheQuizAndItsQuestionsComeAcross(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Joomla Templates');
        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('course_id', '=', (int) $course['id']));

        self::assertNotNull($quiz);
        self::assertSame('Template quiz', $quiz['title']);
        self::assertSame(70, (int) $quiz['pass_mark']);
        self::assertSame(600, (int) $quiz['time_limit_seconds'], 'the OSCampus limit is minutes and ours is seconds');

        $questions = $this->env->database->selectAll(
            Query::from('questions')->where('quiz_id', '=', (int) $quiz['id'])->orderBy('position'),
        );
        self::assertSame(
            ['single_choice', 'multiple_choice', 'true_false'],
            array_column($questions, 'type'),
            'the type is inferred from the answers when the source type word is not one we know',
        );
    }

    public function testAnUnmappableLessonTypeIsRefusedByNameAndTheJobFinishesWithProblems(): void
    {
        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $job->status,
            'finishing and finishing cleanly are different terminal states',
        );
        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status, 'one bad row is not a dead migration');
        self::assertStringContainsString('"scorm" lesson(s) were not imported', (string) $job->log);

        $problems = $this->rowProblems();
        $codes = array_map(static fn (RowProblem $problem): string => $problem->code, $problems);
        self::assertContains(RowProblem::CODE_UNMAPPED_ROLE, $codes);

        $scorm = $this->problemForRow($problems, self::SCORM_LESSON);
        self::assertSame(OSCampusSource::T_LESSONS, $scorm->file, 'a database source names its table where a CSV names its file');
        self::assertSame('type', $scorm->column);
        self::assertSame(
            'scorm',
            $scorm->value,
            'the refusal names the source type, because the count per type is what says what to rebuild',
        );
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $scorm->outcome);
        self::assertSame(1, $this->env->database->count(Query::from('courses')), 'the rest of the course landed');
    }

    public function testAQuestionWithNoCorrectAnswerIsARowProblemRatherThanAFailedJob(): void
    {
        $this->source->insert('oscampus_lessons_quiz_questions', [
            'id' => 44,
            'quizzes_id' => self::QUIZ,
            'title' => 'Which of these is nothing',
            'type' => '',
            'points' => 1,
            'ordering' => 9,
        ]);
        $this->source->insert('oscampus_lessons_quiz_answers', [
            'id' => 91,
            'questions_id' => 44,
            'title' => 'Neither',
            'correct' => 0,
            'ordering' => 1,
        ]);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);

        $question = $this->problemForRow($this->rowProblems(), 44);

        self::assertSame(OSCampusSource::T_QUIZ_QUESTIONS, $question->file);
        self::assertSame(RowProblem::CODE_UNMAPPED_ROLE, $question->code);
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $question->outcome);
        self::assertStringContainsString('marks none of its answers correct', $question->message);
    }

    public function testAnUnreadableQuizTableCostsTheQuizAndNotTheCourse(): void
    {
        $this->source->drop('oscampus_lessons_quiz_questions');

        $job = $this->runImport();

        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('oscampus_lessons_quiz_questions', (string) $job->log);
        self::assertStringContainsString('has not been verified against a real', (string) $job->log);

        $course = $this->courseTitled('Joomla Templates');
        self::assertSame(
            2,
            $this->env->database->count(
                Query::from('lessons')->where('course_id', '=', (int) $course['id'])->where('type', '!=', 'quiz'),
            ),
            'the reading and the video still landed',
        );
    }

    public function testASecondRunUpdatesRatherThanDuplicating(): void
    {
        $this->runImport();
        $this->source->update('oscampus_courses', ['title' => 'Joomla Templates, second edition'], ['id' => self::COURSE]);

        $job = $this->runImport();

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame(
            3,
            $this->env->database->count(Query::from('lessons')),
            'a re-run finds what the first one made instead of building it again',
        );
        self::assertNotNull($this->env->database->selectOne(
            Query::from('courses')->where('title', '=', 'Joomla Templates, second edition'),
        ));
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);
    }

    public function testCreateOnlyLeavesWhatIsAlreadyHereExactlyAsItIs(): void
    {
        $this->runImport();
        $this->source->update('oscampus_courses', ['title' => 'Renamed in OSCampus'], ['id' => self::COURSE]);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertNotNull(
            $this->env->database->selectOne(Query::from('courses')->where('title', '=', 'Joomla Templates')),
            'create_only really creates only',
        );
    }

    public function testUpdateOnlyCreatesNothing(): void
    {
        $job = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]);

        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertStringContainsString('were not created', (string) $job->log);
    }

    public function testCreateWithSuffixMakesASecondCopyAndKeepsTheFirst(): void
    {
        $this->runImport();
        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);

        $titles = array_column(
            $this->env->database->selectAll(Query::from('courses')->orderBy('id')),
            'title',
        );

        self::assertCount(2, $titles);
        self::assertSame('Joomla Templates', $titles[0]);
        self::assertStringStartsWith('Joomla Templates (import ', $titles[1]);
    }

    public function testTheCurriculumOnlyChoiceLeavesTheQuizLessonBehind(): void
    {
        $job = $this->runImport([JoomlaSourceImporter::OPTION_CONTENT => JoomlaSourceImporter::CONTENT_CURRICULUM]);

        self::assertSame(0, $this->env->database->count(Query::from('quizzes')));
        self::assertSame(2, $this->env->database->count(Query::from('lessons')));
        self::assertStringContainsString('quiz lesson(s) were not imported', (string) $job->log);
    }

    public function testLearnersAndTheirCompletionsAreOptionalAndComeAcrossWhenAsked(): void
    {
        $withoutThem = $this->runImport();

        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));
        self::assertStringContainsString('learner(s) in the source, which were not imported', (string) $withoutThem->log);

        $this->runImport([
            JoomlaSourceImporter::OPTION_LEARNERS => true,
            JoomlaSourceImporter::OPTION_PROGRESS => true,
        ]);

        $entitlement = $this->env->database->selectOne(
            Query::from('entitlements')->where('user_id', '=', self::LEARNER_ID),
        );
        self::assertNotNull($entitlement);
        self::assertSame('import', $entitlement['source']);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('lesson_progress')->where('status', '=', 'completed')),
            'only the lesson OSCampus recorded as completed is completed here',
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute($this->admin, OSCampusImporter::KEY, self::SOURCE, $options);
    }

    /**
     * The row problems one real pass produces, collected from the sink rather
     * than read back out of a table.
     *
     * The importer reports through RecordsRowProblems, which is the contract
     * it owes; where those rows are then stored belongs to the pipeline, and
     * asserting on the sink is what keeps this test about the importer.
     *
     * @param array<string, mixed> $options
     *
     * @return list<RowProblem>
     */
    private function rowProblems(array $options = []): array
    {
        $collected = [];
        $recorder = new ImportRecorder(
            0,
            false,
            null,
            null,
            static function (RowProblem $problem) use (&$collected): void {
                $collected[] = $problem;
            },
        );

        $importer = $this->kernel->registries->importers->get(OSCampusImporter::KEY);
        self::assertInstanceOf(OSCampusImporter::class, $importer);
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
        $this->source->insert('oscampus_courses', [
            'id' => self::COURSE,
            'title' => 'Joomla Templates',
            'alias' => 'joomla-templates',
            'introtext' => 'Beginner course',
            'description' => 'Everything about template overrides.',
            'difficulty' => 'beginner',
            'access' => 1,
            'published' => 1,
        ]);
        $this->source->insert('oscampus_modules', [
            'id' => self::MODULE,
            'courses_id' => self::COURSE,
            'title' => 'Getting started',
            'ordering' => 1,
        ]);

        $this->source->insert('oscampus_lessons', [
            'id' => self::READING_LESSON,
            'modules_id' => self::MODULE,
            'title' => 'What a template is',
            'type' => 'text',
            'content' => '<p>A template is a set of overrides.</p>',
            'length' => 300,
            'published' => 1,
            'ordering' => 1,
        ]);
        $this->source->insert('oscampus_lessons', [
            'id' => self::VIDEO_LESSON,
            'modules_id' => self::MODULE,
            'title' => 'Watch the override',
            'type' => 'video',
            'content' => 'https://www.youtube.com/watch?v=overrides',
            'length' => 420,
            'published' => 1,
            'ordering' => 2,
        ]);
        $this->source->insert('oscampus_lessons', [
            'id' => self::QUIZ_LESSON,
            'modules_id' => self::MODULE,
            'title' => 'Check yourself',
            'type' => 'quiz',
            'content' => '',
            'published' => 1,
            'ordering' => 3,
        ]);
        $this->source->insert('oscampus_lessons', [
            'id' => self::SCORM_LESSON,
            'modules_id' => self::MODULE,
            'title' => 'The packaged one',
            'type' => 'scorm',
            'content' => '',
            'published' => 1,
            'ordering' => 4,
        ]);

        $this->source->insert('oscampus_lessons_quizzes', [
            'id' => self::QUIZ,
            'lessons_id' => self::QUIZ_LESSON,
            'title' => 'Template quiz',
            'description' => 'Three questions.',
            'pass' => 70,
            'attempts' => 2,
            'time_limit' => 10,
            'ordering' => 1,
        ]);

        $this->question(41, 'Which folder holds an override', [['Templates', true], ['Media', false]], 'single');
        $this->question(42, 'Which of these are overridable', [['Layouts', true], ['Modules', true], ['Nothing', false]], '');
        $this->question(43, 'An override survives an update', [['True', true], ['False', false]], '');

        $this->source->insert('oscampus_users_lessons', [
            'id' => 1,
            'users_id' => self::LEARNER_ID,
            'lessons_id' => self::READING_LESSON,
            'completed' => '2024-03-01 10:00:00',
            'visits' => 3,
        ]);
        $this->source->insert('oscampus_users_lessons', [
            'id' => 2,
            'users_id' => self::LEARNER_ID,
            'lessons_id' => self::VIDEO_LESSON,
            'completed' => null,
            'visits' => 1,
        ]);
    }

    /**
     * @param list<array{0: string, 1: bool}> $answers
     */
    private function question(int $id, string $prompt, array $answers, string $type): void
    {
        $this->source->insert('oscampus_lessons_quiz_questions', [
            'id' => $id,
            'quizzes_id' => self::QUIZ,
            'title' => $prompt,
            'type' => $type,
            'points' => 1,
            'ordering' => $id,
        ]);

        foreach ($answers as $index => $answer) {
            $this->source->insert('oscampus_lessons_quiz_answers', [
                'id' => ($id * 10) + $index,
                'questions_id' => $id,
                'title' => $answer[0],
                'correct' => $answer[1] ? 1 : 0,
                'ordering' => $index + 1,
            ]);
        }
    }
}
