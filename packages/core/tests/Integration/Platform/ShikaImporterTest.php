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
use Shondalai\VidyaLMS\Core\Provider\Importer\Shika\ShikaImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\Shika\ShikaSource;
use Shondalai\VidyaLMS\Core\Registry\Importer\HonoursUpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\JoomlaSourceFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The Shika importer against a Shika-shaped database.
 *
 * Shika publishes no schema, so the fixture is the importer's documented
 * expectation rather than a copy of the real thing, and these tests prove the
 * behaviour rather than the shape: the lesson format vocabulary Techjoomla
 * does publish, the refusals, the idempotency, the four upsert choices, and
 * that a table whose shape has moved costs that table and not the migration.
 */
final class ShikaImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'cms-db:';

    private const COURSE = 3;
    private const MODULE = 7;
    private const TEXT_LESSON = 12;
    private const VIDEO_LESSON = 13;
    private const DOCUMENT_LESSON = 14;
    private const QUIZ_LESSON = 15;
    private const SCORM_LESSON = 16;
    private const QUIZ = 20;

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
        $this->source = JoomlaSourceFixture::shika($this->env->database);
        $this->seed($this->source);
    }

    public function testItIsRegisteredAndDeclaresItselfHonestly(): void
    {
        self::assertContains(ShikaImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(ShikaImporter::KEY);

        self::assertInstanceOf(HonoursUpsertPolicy::class, $importer);
        self::assertSame(UpsertPolicy::POLICIES, $importer->upsertPolicies());
        self::assertTrue($importer->supports(self::SOURCE));
        self::assertFalse($importer->supports('imports/9/course.json'));

        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';
        self::assertStringContainsString('unverified against a real install', $catalogue['vidyalms.importer.shika']);
    }

    public function testItRefusesWhenShikaIsNotInstalled(): void
    {
        $bare = TestPorts::create();
        $bare->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $bare->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $bare->users->add(self::ADMIN_ID, 'Ada Admin');
        $kernel = new CoreKernel($bare->ports);

        self::assertFalse($kernel->registries->importers->get(ShikaImporter::KEY)->supports(self::SOURCE));

        try {
            $kernel->runImportJob->execute($this->admin, ShikaImporter::KEY, self::SOURCE, []);
            self::fail('Unavailable import sources must be rejected before queuing.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException $failure) {
            self::assertArrayHasKey('source', $failure->errors);
        }
        self::assertSame(0, $bare->database->count(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('import_jobs')));
    }

    public function testItMapsTheDocumentedLessonFormatsAndRefusesTheRest(): void
    {
        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);

        $course = $this->courseTitled('Learning Shika');
        self::assertSame(CourseState::Draft->value, $course['state']);

        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(
            ['reading', 'video', 'reading', 'quiz'],
            array_column($lessons, 'type'),
            'textmedia and document are readings, video is a video, and scorm is refused rather than emptied',
        );
        self::assertStringContainsString('"scorm" lesson(s) were not imported', (string) $job->log);

        $scorm = $this->problemForRow($this->rowProblems(), self::SCORM_LESSON);
        self::assertSame(RowProblem::CODE_UNMAPPED_ROLE, $scorm->code);
        self::assertSame('scorm', $scorm->value);
        self::assertStringContainsString('SCORM player', $scorm->message);
    }

    public function testItReadsTheOlderTableFamilyJustAsWell(): void
    {
        $legacy = TestPorts::create();
        $legacy->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $legacy->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $legacy->users->add(self::ADMIN_ID, 'Ada Admin');
        $legacy->users->add(self::LEARNER_ID, 'Lena Learner', 'lena@example.test');
        $kernel = new CoreKernel($legacy->ports);
        $this->seed(JoomlaSourceFixture::shikaLegacy($legacy->database));

        $job = $kernel->runImportJob->execute($this->admin, ShikaImporter::KEY, self::SOURCE, []);

        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString(ShikaSource::LEGACY_COURSES, (string) $job->log);
        self::assertSame(1, $legacy->database->count(Query::from('courses')));
    }

    public function testTheQuizAndItsQuestionsComeAcross(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Learning Shika');
        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('course_id', '=', (int) $course['id']));

        self::assertNotNull($quiz);
        self::assertSame('Shika basics', $quiz['title']);
        self::assertSame(80, (int) $quiz['pass_mark']);

        $questions = $this->env->database->selectAll(
            Query::from('questions')->where('quiz_id', '=', (int) $quiz['id'])->orderBy('position'),
        );
        self::assertSame(['single_choice', 'true_false'], array_column($questions, 'type'));
    }

    public function testAQuestionWithNoOptionsIsARowProblemRatherThanAFailedJob(): void
    {
        $this->source->insert('tj_lms_quiz_questions', [
            'id' => 77,
            'quiz_id' => self::QUIZ,
            'question' => 'What did I mean by this',
            'type' => 'essay',
            'marks' => 5,
            'ordering' => 9,
        ]);

        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $job->status,
            'a malformed row is a problem on the row, not a dead migration',
        );

        $problem = $this->problemForRow($this->rowProblems(), 77);
        self::assertSame(ShikaSource::T_QUIZ_QUESTIONS, $problem->file);
        self::assertSame(RowProblem::CODE_UNMAPPED_ROLE, $problem->code);
        self::assertSame(RowProblem::OUTCOME_SKIPPED, $problem->outcome);
        self::assertSame('essay', $problem->value, 'the refusal names the source type');
        self::assertSame(1, $this->env->database->count(Query::from('courses')));
    }

    public function testACourseWithNoReadableModuleTableStillLandsInOneModule(): void
    {
        $this->source->drop('tj_lms_modules');

        $job = $this->runImport();

        self::assertNotSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('imported into one module named after the course', (string) $job->log);

        $course = $this->courseTitled('Learning Shika');
        $modules = $this->env->database->selectAll(Query::from('modules')->where('course_id', '=', (int) $course['id']));
        self::assertCount(1, $modules);
        self::assertSame('Learning Shika', $modules[0]['title']);
    }

    public function testASecondRunUpdatesRatherThanDuplicating(): void
    {
        $this->runImport();
        $this->source->update('tj_lms_courses', ['title' => 'Learning Shika, revised'], ['id' => self::COURSE]);

        $job = $this->runImport();

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame(4, $this->env->database->count(Query::from('lessons')));
        self::assertSame(1, $this->env->database->count(Query::from('quizzes')));
        self::assertNotNull($this->env->database->selectOne(
            Query::from('courses')->where('title', '=', 'Learning Shika, revised'),
        ));
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);
    }

    public function testTheThreeOtherUpsertChoicesDoWhatTheySay(): void
    {
        $this->runImport();
        $this->source->update('tj_lms_courses', ['title' => 'Renamed in Shika'], ['id' => self::COURSE]);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        self::assertNotNull(
            $this->env->database->selectOne(Query::from('courses')->where('title', '=', 'Learning Shika')),
            'create_only changes nothing that is already here',
        );

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);
        self::assertSame(2, $this->env->database->count(Query::from('courses')), 'create_with_suffix makes a copy');

        $titles = array_column($this->env->database->selectAll(Query::from('courses')->orderBy('id')), 'title');
        self::assertStringStartsWith('Renamed in Shika (import ', $titles[1]);
    }

    public function testUpdateOnlyCreatesNothingOnAnEmptySite(): void
    {
        $job = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]);

        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertStringContainsString('were not created', (string) $job->log);
    }

    public function testLearnersAreReadFromTheTrackingTableAndOnlyWhenAsked(): void
    {
        $withoutThem = $this->runImport();
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));
        self::assertStringContainsString('were not imported', (string) $withoutThem->log);

        $job = $this->runImport([
            JoomlaSourceImporter::OPTION_LEARNERS => true,
            JoomlaSourceImporter::OPTION_PROGRESS => true,
        ]);

        self::assertStringContainsString(
            'rather than from an enrolment table',
            (string) $job->log,
            'inferring access from activity is a judgement, so the log says so',
        );

        $entitlement = $this->env->database->selectOne(
            Query::from('entitlements')->where('user_id', '=', self::LEARNER_ID),
        );
        self::assertNotNull($entitlement);
        self::assertSame('import', $entitlement['source']);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('lesson_progress')->where('status', '=', 'completed')),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute($this->admin, ShikaImporter::KEY, self::SOURCE, $options);
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

        $importer = $this->kernel->registries->importers->get(ShikaImporter::KEY);
        self::assertInstanceOf(ShikaImporter::class, $importer);
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

    private function seed(JoomlaSourceFixture $source): void
    {
        // The same seed serves both spellings of the family, because an
        // install on the older one has to import exactly as well.
        $prefix = $source->has('tj_lms_courses') ? 'tj_lms_' : 'tjlms_';
        $table = static fn (string $name): string => $prefix . $name;

        $source->insert($table('courses'), $this->courseRow());
        $source->insert($table('modules'), [
            'id' => self::MODULE,
            'course_id' => self::COURSE,
            'title' => 'The basics',
            'ordering' => 1,
        ]);

        $source->insert($table('lessons'), [
            'id' => self::TEXT_LESSON,
            'course_id' => self::COURSE,
            'module_id' => self::MODULE,
            'title' => 'What Shika is',
            'format' => 'textmedia',
            'content' => '<p>An LMS for Joomla.</p>',
            'duration' => 5,
            'ordering' => 1,
        ]);
        $source->insert($table('lessons'), [
            'id' => self::VIDEO_LESSON,
            'course_id' => self::COURSE,
            'module_id' => self::MODULE,
            'title' => 'Watch it work',
            'format' => 'video',
            'url' => 'https://vimeo.com/12345',
            'duration' => 8,
            'ordering' => 2,
        ]);
        $source->insert($table('lessons'), [
            'id' => self::DOCUMENT_LESSON,
            'course_id' => self::COURSE,
            'module_id' => self::MODULE,
            'title' => 'The handbook',
            'format' => 'document',
            'url' => 'https://example.test/handbook.pdf',
            'ordering' => 3,
        ]);
        $source->insert($table('lessons'), [
            'id' => self::QUIZ_LESSON,
            'course_id' => self::COURSE,
            'module_id' => self::MODULE,
            'title' => 'Prove it',
            'format' => 'quiz',
            'ordering' => 4,
        ]);
        $source->insert($table('lessons'), [
            'id' => self::SCORM_LESSON,
            'course_id' => self::COURSE,
            'module_id' => self::MODULE,
            'title' => 'The packaged module',
            'format' => 'scorm',
            'ordering' => 5,
        ]);

        $source->insert($table('quiz'), [
            'id' => self::QUIZ,
            'lesson_id' => self::QUIZ_LESSON,
            'title' => 'Shika basics',
            'description' => 'Two questions.',
            'pass_percentage' => 80,
            'attempts' => 2,
            'duration' => 5,
            'ordering' => 1,
        ]);

        $source->insert($table('quiz_questions'), [
            'id' => 31,
            'quiz_id' => self::QUIZ,
            'question' => 'What does com_tjlms stand for',
            'type' => 'single',
            'marks' => 2,
            'ordering' => 1,
        ]);
        $source->insert($table('quiz_options'), [
            'id' => 41,
            'question_id' => 31,
            'option' => 'The Shika component',
            'correct' => 1,
            'ordering' => 1,
        ]);
        $source->insert($table('quiz_options'), [
            'id' => 42,
            'question_id' => 31,
            'option' => 'Something else',
            'correct' => 0,
            'ordering' => 2,
        ]);

        $source->insert($table('quiz_questions'), [
            'id' => 32,
            'quiz_id' => self::QUIZ,
            'question' => 'Shika supports SCORM',
            'type' => '',
            'marks' => 1,
            'ordering' => 2,
        ]);
        $source->insert($table('quiz_options'), [
            'id' => 43,
            'question_id' => 32,
            'option' => 'True',
            'correct' => 1,
            'ordering' => 1,
        ]);
        $source->insert($table('quiz_options'), [
            'id' => 44,
            'question_id' => 32,
            'option' => 'False',
            'correct' => 0,
            'ordering' => 2,
        ]);

        $source->insert($table('lesson_track'), [
            'id' => 51,
            'user_id' => self::LEARNER_ID,
            'course_id' => self::COURSE,
            'lesson_id' => self::TEXT_LESSON,
            'status' => 'completed',
        ]);
        $source->insert($table('lesson_track'), [
            'id' => 52,
            'user_id' => self::LEARNER_ID,
            'course_id' => self::COURSE,
            'lesson_id' => self::VIDEO_LESSON,
            'status' => 'in_progress',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function courseRow(): array
    {
        return [
            'id' => self::COURSE,
            'title' => 'Learning Shika',
            'alias' => 'learning-shika',
            'short_description' => 'A short course',
            'description' => 'Everything a Shika site needs.',
            'difficulty_level' => 'beginner',
            'access' => 1,
            'state' => 1,
        ];
    }
}
