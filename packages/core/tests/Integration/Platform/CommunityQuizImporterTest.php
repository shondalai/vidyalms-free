<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\CommunityQuizImporter;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\CommunityQuizFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The Community Quiz importer against a real Community Quiz database: the
 * cq_ tables of the component, seeded with a course that uses the mappings
 * the importer makes and several it deliberately refuses.
 */
final class CommunityQuizImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'cms-db:';

    private const TEA_COURSE = 8;
    private const WATER_COURSE = 9;
    private const FINAL_QUIZ = 7;

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $admin;
    private CommunityQuizFixture $source;

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
        $this->source = CommunityQuizFixture::install($this->env->database);
        $this->seed();
    }

    public function testItIsRegisteredAndClaimsOnlyTheDatabaseScheme(): void
    {
        self::assertContains(CommunityQuizImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(CommunityQuizImporter::KEY);

        self::assertTrue($importer->supports('cms-db:'));
        self::assertTrue($importer->supports('cms-db:courses=8'));
        self::assertFalse($importer->supports('imports/9/course.json'));
        self::assertFalse($importer->supports('imports/9/backup.mbz'));
    }

    public function testItBuildsTheCurriculumThroughTheUseCasesInSourceOrder(): void
    {
        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_COURSES]);

        $tea = $this->courseTitled('Foundations of Tea');

        self::assertSame(
            CourseState::Draft->value,
            $tea['state'],
            'a migration is a draft until a person publishes it',
        );

        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $tea['id'])->orderBy('position'),
        );
        self::assertSame(['Leaves', 'Water'], array_column($modules, 'title'));

        $lessons = $this->lessons((int) $tea['id']);
        self::assertSame(
            ['Read the leaves', 'Watch the pour', 'Prove it'],
            array_column($lessons, 'title'),
            'the curriculum keeps the source ordering',
        );
        self::assertSame(['reading', 'video', 'quiz'], array_column($lessons, 'type'));

        self::assertSame(
            ['body' => '<p>Steep for four minutes.</p>'],
            $this->lessonContent((int) $lessons[0]['id']),
        );
        self::assertSame(
            ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
            $this->lessonContent((int) $lessons[1]['id']),
        );
    }

    public function testTheQuizzesCarryTheirSettingsAndAreAttachedToTheirLessons(): void
    {
        $this->runImport();

        $tea = $this->courseTitled('Foundations of Tea');
        $quiz = $this->env->database->selectOne(
            Query::from('quizzes')->where('course_id', '=', (int) $tea['id']),
        );

        self::assertNotNull($quiz);
        self::assertSame('Final Quiz', $quiz['title']);
        self::assertSame(60, (int) $quiz['pass_mark']);
        self::assertSame(3, (int) $quiz['max_attempts']);
        self::assertSame(600, (int) $quiz['time_limit_seconds']);

        $quizLesson = $this->env->database->selectOne(
            Query::from('lessons')->where('title', '=', 'Prove it'),
        );
        self::assertNotNull($quizLesson);
        self::assertSame((int) $quizLesson['id'], (int) $quiz['lesson_id']);
    }

    public function testEachFreeQuestionTypeItCanCarryArrives(): void
    {
        $this->runImport();

        $tea = $this->courseTitled('Foundations of Tea');
        $quiz = $this->env->database->selectOne(
            Query::from('quizzes')->where('course_id', '=', (int) $tea['id']),
        );
        self::assertNotNull($quiz);

        $questions = $this->env->database->selectAll(
            Query::from('questions')->where('quiz_id', '=', (int) $quiz['id'])->orderBy('position'),
        );

        self::assertSame(
            ['single_choice', 'true_false', 'short_answer'],
            array_column($questions, 'type'),
        );

        $single = $this->definitionOf($questions[0]);
        self::assertSame('Which leaf is oolong?', $single['prompt']);
        self::assertCount(2, $single['options']);

        $type = $this->kernel->registries->questionTypes->get('single_choice');
        self::assertSame(1.0, $type->grade($single, (string) $single['correct'])->fraction);
    }

    public function testAQuestionTypeWithNoFreeEquivalentIsNamedAndSkipped(): void
    {
        $this->source->insert('questions', [
            'id' => 40,
            'type' => 'hotspot',
            'title' => 'Point at the pot',
            'stem' => '<p>Where is the spout?</p>',
            'content' => ['regions' => []],
            'points' => 2,
            'state' => 1,
        ]);
        $this->source->insert('section_items', [
            'id' => 40,
            'section_id' => 1,
            'question_id' => 40,
            'ordering' => 9,
        ]);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertStringContainsString('hotspot', (string) $job->log);
        self::assertNotNull($job->totals);
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
    }

    public function testALessonTypeWithNoFreeEquivalentIsNamedAndSkipped(): void
    {
        $this->source->insert('lessons', [
            'id' => 90,
            'course_id' => self::TEA_COURSE,
            'module_id' => 1,
            'title' => 'Live tasting',
            'type' => 'live',
            'content' => ['room' => 'https://meet.example.test/tea'],
            'ordering' => 9,
        ]);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertStringContainsString(
            'live',
            (string) $job->log,
            'the note names the lesson type that has no free equivalent',
        );
        self::assertNull(
            $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Live tasting')),
        );
    }

    public function testASelectorNarrowsTheRunToOneCourse(): void
    {
        $job = $this->kernel->runImportJob->execute(
            $this->admin,
            CommunityQuizImporter::KEY,
            'cms-db:courses=' . self::TEA_COURSE,
            [],
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame('Foundations of Tea', $this->courseTitled('Foundations of Tea')['title']);
    }

    public function testEnrolmentsAreLeftBehindUnlessTheOperatorAsksForThem(): void
    {
        $this->runImport();

        self::assertSame(
            0,
            $this->env->database->count(Query::from('entitlements')),
            'enrolling a learner mails them a welcome for a course they joined years ago',
        );

        $second = $this->runImport(['enrolments' => true]);

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(1, $this->env->database->count(Query::from('entitlements')));

        $entitlement = $this->env->database->selectOne(Query::from('entitlements'));
        self::assertNotNull($entitlement);
        self::assertSame(self::LEARNER_ID, (int) $entitlement['user_id']);
    }

    public function testLearnerHistoryIsNeverInvented(): void
    {
        $this->runImport(['enrolments' => true]);

        self::assertSame(
            0,
            $this->env->database->count(Query::from('lesson_progress')),
            'replaying progress would date every completion to the import',
        );
        self::assertSame(0, $this->env->database->count(Query::from('attempts')));
        self::assertSame(0, $this->env->database->count(Query::from('certificates')));
    }

    public function testPublishingIsOptedIntoRatherThanAssumed(): void
    {
        $this->runImport(['publish' => true]);

        self::assertSame(
            CourseState::Published->value,
            $this->courseTitled('Foundations of Tea')['state'],
            'the source course was published, and the operator asked for it',
        );
    }

    public function testASecondRunUpdatesWhatTheFirstCreated(): void
    {
        $this->runImport();

        $this->source->update('courses', ['title' => 'Foundations of Tea, Revised'], ['id' => self::TEA_COURSE]);
        $this->source->update('lessons', ['title' => 'Read the leaves again'], ['id' => 1]);

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(2, $this->env->database->count(Query::from('courses')), 'identity is the source id');
        self::assertSame('Foundations of Tea, Revised', $this->courseTitled('Foundations of Tea, Revised')['title']);
        self::assertNotNull(
            $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Read the leaves again')),
        );
    }

    public function testTwoInstallsInOneDatabaseAreTwoSetsOfCourses(): void
    {
        $this->runImport();

        $other = CommunityQuizFixture::install($this->env->database, 'cq2_');
        $other->insert('courses', [
            'id' => 8,
            'catid' => 3,
            'title' => 'A Different Course With The Same Id',
            'alias' => 'different',
            'description' => '<p>Elsewhere.</p>',
            'access_model' => 'free',
            'visibility' => 'public',
            'state' => 1,
        ]);
        $other->insert('course_modules', ['id' => 1, 'course_id' => 8, 'title' => 'One', 'ordering' => 1]);

        $job = $this->runImport(['table_prefix' => 'cq2_']);

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(
            3,
            $this->env->database->count(Query::from('courses')),
            'ids overlap between installs, so the map is keyed under the prefix as well',
        );
    }

    public function testADryRunReadsEverythingAndWritesNothing(): void
    {
        $job = $this->runImport(['dry_run' => true]);

        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_COURSES], 'a preview still counts what it would bring');
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertSame(0, $this->env->database->count(Query::from('lessons')));
    }

    public function testAPrerequisiteBetweenTwoImportedCoursesIsCarriedOver(): void
    {
        $this->runImport();

        $tea = $this->courseTitled('Foundations of Tea');
        $water = $this->courseTitled('Water First');

        $row = $this->env->database->selectOne(
            Query::from('course_prerequisites')->where('course_id', '=', (int) $tea['id']),
        );

        self::assertNotNull($row);
        self::assertSame((int) $water['id'], (int) $row['prerequisite_course_id']);
    }

    public function testALessonThatMovedBetweenModulesMovesRatherThanBeingCopied(): void
    {
        $this->runImport();

        $tea = $this->courseTitled('Foundations of Tea');
        $before = \count($this->lessons((int) $tea['id']));

        $this->source->update('lessons', ['module_id' => 2], ['id' => 1]);

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(
            $before,
            \count($this->lessons((int) $tea['id'])),
            'a lesson that moved in the source is the same lesson here',
        );

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Read the leaves'));
        self::assertNotNull($lesson);
        self::assertSame(
            (int) $this->moduleTitled((int) $tea['id'], 'Water')['id'],
            (int) $lesson['module_id'],
        );
        self::assertStringContainsString('moved to the module', (string) $second->log);
    }

    public function testAModuleThatMovedToAnotherCourseIsRefusedWithANote(): void
    {
        $this->runImport();

        $tea = $this->courseTitled('Foundations of Tea');
        $water = $this->courseTitled('Water First');
        $module = $this->moduleTitled((int) $tea['id'], 'Water');

        // Community Quiz now keeps module 2, and the lesson it holds, in the
        // other course.
        $this->source->update('course_modules', ['course_id' => self::WATER_COURSE], ['id' => 2]);
        $this->source->update('lessons', ['course_id' => self::WATER_COURSE], ['id' => 3]);

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertStringContainsString(
            'a module cannot change course here',
            (string) $second->log,
            'the note says where the module is, where the source wants it, and what it costs',
        );

        $row = $this->env->database->selectOne(Query::from('modules')->where('id', '=', (int) $module['id']));
        self::assertNotNull($row);
        self::assertSame(
            (int) $tea['id'],
            (int) $row['course_id'],
            'a module carries the course its lessons, progress and attempts belong to',
        );
        self::assertSame(
            ['Boil it'],
            array_column($this->lessons((int) $water['id']), 'title'),
            'this run wrote nothing under a course the module does not sit in here',
        );
    }

    public function testTheEnrolmentsOptionIsKeyedUnderTheTablePrefix(): void
    {
        $this->runImport(['enrolments' => true]);

        $other = CommunityQuizFixture::install($this->env->database, 'cq2_');
        $other->insert('courses', [
            'id' => self::TEA_COURSE,
            'catid' => 3,
            'title' => 'A Different Course With The Same Id',
            'alias' => 'different',
            'description' => '<p>Elsewhere.</p>',
            'access_model' => 'free',
            'visibility' => 'public',
            'state' => 1,
        ]);
        $other->insert('course_modules', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'title' => 'One',
            'ordering' => 1,
        ]);
        $other->insert('enrollments', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'user_id' => self::LEARNER_ID,
            'status' => 'in_progress',
            'source' => 'manual',
            'enrolled_at' => '2024-03-01 09:00:00',
        ]);

        $second = $this->runImport(['table_prefix' => 'cq2_', 'enrolments' => true]);

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(
            2,
            $this->env->database->count(Query::from('entitlements')),
            'enrolment 1 of one install is not enrolment 1 of the other',
        );
        self::assertSame(
            ['community-quiz:cq_|enrollment:1', 'community-quiz:cq2_|enrollment:1'],
            array_column($this->env->database->selectAll(Query::from('entitlements')->orderBy('id')), 'source_ref'),
            'the source_ref is what makes a re-run re-grant nothing, so it carries the prefix',
        );
    }

    public function testAnEnrolmentNamingAnAccountThatIsGoneIsSkippedWithoutEndingTheRun(): void
    {
        $this->source->insert('enrollments', [
            'id' => 2,
            'course_id' => self::TEA_COURSE,
            'user_id' => 4242,
            'status' => 'in_progress',
            'source' => 'manual',
            'enrolled_at' => '2024-02-01 09:00:00',
        ]);

        $job = $this->runImport(['enrolments' => true]);

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('entitlements')),
            'the learner who still has an account arrives',
        );
        self::assertStringContainsString('Enrolment 2 of course', (string) $job->log);
    }

    public function testEnrolmentsNamingNoLearnerAreCountedRatherThanNamedOneByOne(): void
    {
        foreach ([2, 3] as $sourceId) {
            $this->source->insert('enrollments', [
                'id' => $sourceId,
                'course_id' => self::TEA_COURSE,
                'user_id' => 0,
                'status' => 'in_progress',
                'source' => 'manual',
                'enrolled_at' => '2024-02-01 09:00:00',
            ]);
        }

        $job = $this->runImport(['enrolments' => true]);

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(1, $this->env->database->count(Query::from('entitlements')));
        self::assertStringContainsString(
            'name no learner',
            (string) $job->log,
            'a hundred identical skips are one counted note, not a hundred lines',
        );
    }

    public function testATitleFromTheSourceCannotForgeALogLine(): void
    {
        $this->source->insert('lessons', [
            'id' => 92,
            'course_id' => self::TEA_COURSE,
            'module_id' => 1,
            'title' => "Broken video\n[error] every course was deleted",
            'type' => 'video',
            'content' => ['source_url' => 'not a url at all'],
            'ordering' => 8,
        ]);

        $job = $this->runImport();
        $log = (string) $job->log;

        self::assertStringContainsString(
            'every course was deleted',
            $log,
            'the source text arrives as text rather than being dropped',
        );

        $carrying = array_values(array_filter(
            explode("\n", $log),
            static fn (string $line): bool => str_contains($line, 'every course was deleted'),
        ));
        self::assertCount(1, $carrying, 'one note is one line, whatever the row it quotes holds');

        foreach (explode("\n", $log) as $line) {
            self::assertStringStartsNotWith(
                '[error] ',
                $line,
                'a level marker inside a title is quoted text, never a level a reader counts',
            );
        }

        foreach (ImportNote::parseLog($log) as $note) {
            self::assertNotSame(ImportNote::ERROR, $note->level);
        }
    }

    public function testANonsenseRowNeverEndsTheRun(): void
    {
        $this->source->insert('lessons', [
            'id' => 91,
            'course_id' => self::TEA_COURSE,
            'module_id' => 1,
            'title' => 'Broken video',
            'type' => 'video',
            'content' => ['source_url' => 'not a url at all'],
            'ordering' => 8,
        ]);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertStringContainsString('Broken video', (string) $job->log);
        self::assertSame(
            2,
            $this->env->database->count(Query::from('courses')),
            'one bad row does not throw away the rest of the migration',
        );
    }

    public function testAGuestMayNotImport(): void
    {
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);

        $this->kernel->runImportJob->execute(Principal::guest(), CommunityQuizImporter::KEY, self::SOURCE, []);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute(
            $this->admin,
            CommunityQuizImporter::KEY,
            self::SOURCE,
            $options,
        );
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

    /**
     * @return array<string, mixed>
     */
    private function moduleTitled(int $courseId, string $title): array
    {
        $row = $this->env->database->selectOne(
            Query::from('modules')->where('course_id', '=', $courseId)->where('title', '=', $title),
        );
        self::assertNotNull($row, sprintf('no module titled %s was imported', $title));

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lessons(int $courseId): array
    {
        return $this->env->database->selectAll(
            Query::from('lessons')
                ->where('course_id', '=', $courseId)
                ->orderBy('module_id')
                ->orderBy('position'),
        );
    }

    /**
     * @param array<string, mixed> $question
     *
     * @return array<string, mixed>
     */
    private function definitionOf(array $question): array
    {
        self::assertIsString($question['definition']);

        /** @var array<string, mixed> $definition */
        $definition = json_decode($question['definition'], true, 32, \JSON_THROW_ON_ERROR);

        return StoredShape::bodyOf($definition);
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonContent(int $lessonId): array
    {
        $row = $this->env->database->selectOne(Query::from('lesson_content')->where('lesson_id', '=', $lessonId));
        self::assertNotNull($row);
        self::assertIsString($row['content']);

        /** @var array<string, mixed> $content */
        $content = json_decode($row['content'], true, 32, \JSON_THROW_ON_ERROR);

        return StoredShape::bodyOf($content);
    }

    /**
     * A Community Quiz site with two courses: one that uses what the free
     * build can carry, and one small course it depends on.
     */
    private function seed(): void
    {
        $this->source->insert('courses', [
            'id' => self::WATER_COURSE,
            'catid' => 3,
            'title' => 'Water First',
            'alias' => 'water-first',
            'subtitle' => 'Before the leaf',
            'description' => '<p>Start here.</p>',
            'level' => 'beginner',
            'access_model' => 'free',
            'visibility' => 'public',
            'show_in_catalog' => 1,
            'state' => 1,
            'language' => 'en-GB',
        ]);
        $this->source->insert('course_modules', [
            'id' => 10,
            'course_id' => self::WATER_COURSE,
            'title' => 'Basics',
            'ordering' => 1,
        ]);
        $this->source->insert('lessons', [
            'id' => 20,
            'course_id' => self::WATER_COURSE,
            'module_id' => 10,
            'title' => 'Boil it',
            'type' => 'reading',
            'content' => ['body' => '<p>Not too hot.</p>'],
            'ordering' => 1,
        ]);

        $this->source->insert('courses', [
            'id' => self::TEA_COURSE,
            'catid' => 3,
            'title' => 'Foundations of Tea',
            'alias' => 'foundations-of-tea',
            'subtitle' => 'Leaf, water, time',
            'description' => '<p>A short course.</p>',
            'level' => 'beginner',
            'access_model' => 'free',
            'completion' => ['rule' => 'pass_final'],
            'visibility' => 'public',
            'show_in_catalog' => 1,
            'state' => 1,
            'language' => 'en-GB',
        ]);
        $this->source->insert('course_prerequisites', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'required_type' => 'course',
            'required_id' => self::WATER_COURSE,
        ]);

        $this->source->insert('course_modules', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'title' => 'Leaves',
            'summary' => 'What the plant gives you.',
            'ordering' => 1,
        ]);
        $this->source->insert('course_modules', [
            'id' => 2,
            'course_id' => self::TEA_COURSE,
            'title' => 'Water',
            'ordering' => 2,
        ]);

        $this->source->insert('lessons', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'module_id' => 1,
            'title' => 'Read the leaves',
            'type' => 'reading',
            'content' => ['body' => '<p>Steep for four minutes.</p>'],
            'is_preview' => 1,
            'ordering' => 1,
        ]);
        $this->source->insert('lessons', [
            'id' => 2,
            'course_id' => self::TEA_COURSE,
            'module_id' => 1,
            'title' => 'Watch the pour',
            'type' => 'video',
            'content' => ['source_url' => 'https://www.youtube.com/watch?v=abc'],
            'ordering' => 2,
        ]);
        $this->source->insert('lessons', [
            'id' => 3,
            'course_id' => self::TEA_COURSE,
            'module_id' => 2,
            'title' => 'Prove it',
            'type' => 'quiz',
            'quiz_id' => self::FINAL_QUIZ,
            'ordering' => 1,
        ]);

        $this->source->insert('quizzes', [
            'id' => self::FINAL_QUIZ,
            'title' => 'Final Quiz',
            'intro' => '<p>Six ways to be right.</p>',
            'kind' => 'quiz',
            'duration' => 10,
            'pass_mark' => 60,
            'scale_max' => 100,
            'attempts_allowed' => 3,
            'state' => 1,
        ]);
        $this->source->insert('sections', [
            'id' => 1,
            'quiz_id' => self::FINAL_QUIZ,
            'type' => 'fixed',
            'title' => 'All of it',
            'ordering' => 1,
        ]);

        $this->source->insert('questions', [
            'id' => 1,
            'type' => 'mcq',
            'title' => 'Which leaf',
            'stem' => 'Which leaf is oolong?',
            'content' => ['options' => ['The green one', 'The half oxidised one'], 'answer' => 1],
            'points' => 2,
            'state' => 1,
        ]);
        $this->source->insert('questions', [
            'id' => 2,
            'type' => 'tf',
            'title' => 'Boiling',
            'stem' => 'Boiling water suits green tea.',
            'content' => ['answer' => false],
            'points' => 1,
            'state' => 1,
        ]);
        $this->source->insert('questions', [
            'id' => 3,
            'type' => 'short',
            'title' => 'Name one',
            'stem' => 'Name an oolong.',
            'content' => ['answers' => ['Tieguanyin'], 'caseSensitive' => false],
            'points' => 1,
            'state' => 1,
        ]);

        foreach ([1, 2, 3] as $index => $questionId) {
            $this->source->insert('section_items', [
                'id' => $questionId,
                'section_id' => 1,
                'question_id' => $questionId,
                'ordering' => $index + 1,
            ]);
        }

        $this->source->insert('enrollments', [
            'id' => 1,
            'course_id' => self::TEA_COURSE,
            'user_id' => self::LEARNER_ID,
            'status' => 'in_progress',
            'source' => 'manual',
            'enrolled_at' => '2024-02-01 09:00:00',
        ]);
        $this->source->insert('lesson_progress', [
            'id' => 1,
            'enrollment_id' => 1,
            'course_id' => self::TEA_COURSE,
            'lesson_id' => 1,
            'user_id' => self::LEARNER_ID,
            'status' => 'done',
        ]);
        $this->source->insert('attempts', [
            'id' => 1,
            'quiz_id' => self::FINAL_QUIZ,
            'user_id' => self::LEARNER_ID,
            'status' => 'submitted',
        ]);
    }
}
