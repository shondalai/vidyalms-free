<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\LearnDashImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Core\Tests\Support\WordPressLmsFixture;

/**
 * The LearnDash importer against a synthetic LearnDash database: the post
 * types, the relation meta, the serialised settings and the two ProQuiz tables
 * the importer's docblock names, seeded with the mappings it makes and several
 * it deliberately refuses.
 *
 * The fixture reproduces the documented schema and nothing else. It cannot
 * prove the importer against a real LearnDash, and the importer says so in its
 * own label; what it does prove is that every mapping this build claims is the
 * mapping it makes.
 */
final class LearnDashImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'learndash-db:';

    private const COURSE = 10;
    private const LESSON = 20;
    private const TOPIC = 30;
    private const VIDEO_TOPIC = 31;
    private const EMPTY_TOPIC = 32;
    private const QUIZ = 40;
    private const PRO_QUIZ = 7;

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $admin;
    private WordPressLmsFixture $source;

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
        $this->source = WordPressLmsFixture::install($this->env->database, WordPressLmsFixture::LEARNDASH);
        $this->seed();
    }

    public function testItIsRegisteredAndClaimsOnlyItsOwnSchemeOnASiteThatHasLearnDash(): void
    {
        self::assertContains(LearnDashImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(LearnDashImporter::KEY);

        self::assertTrue($importer->supports('learndash-db:'));
        self::assertTrue($importer->supports('learndash-db:courses=10'));
        self::assertFalse($importer->supports('cms-db:'), 'the Community Quiz scheme is not this importer\'s');
        self::assertFalse($importer->supports('tutor-db:'));
        self::assertFalse($importer->supports('imports/9/course.json'));
    }

    public function testItsLabelAndItsLogBothSayItIsUnverifiedAgainstARealInstall(): void
    {
        $key = $this->kernel->registries->importers->get(LearnDashImporter::KEY)->label();

        self::assertSame('vidyalms.importer.learndash', $key, 'the label is a catalogue key, not English');
        self::assertStringContainsString(
            'unverified against a real install',
            (new CatalogueTranslator())->translate($key),
            'the warning reaches the Import screen, where an operator reads it',
        );

        self::assertStringContainsString(
            'has not been verified against a real LearnDash install',
            (string) $this->runImport()->log,
            'and the run says it again before it writes anything',
        );
    }

    public function testItBuildsTheCurriculumThroughTheUseCasesInLearnDashOrder(): void
    {
        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);

        $course = $this->courseTitled('Foundations of Tea');

        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(
            ['Leaves'],
            array_column($modules, 'title'),
            'a LearnDash lesson becomes a module, because its topics have to go somewhere',
        );

        $lessons = $this->lessons((int) $course['id']);
        self::assertSame(
            ['Leaves', 'Steeping', 'Video pour', 'Final Quiz'],
            array_column($lessons, 'title'),
            'the lesson\'s own content leads, then its topics, then its quiz',
        );
        self::assertSame(['reading', 'reading', 'video', 'quiz'], array_column($lessons, 'type'));

        self::assertSame(
            ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
            $this->lessonContent((int) $lessons[2]['id']),
        );
    }

    public function testTheQuizCarriesItsSettingsAndIsAttachedToItsLesson(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Foundations of Tea');
        $quiz = $this->env->database->selectOne(
            Query::from('quizzes')->where('course_id', '=', (int) $course['id']),
        );

        self::assertNotNull($quiz);
        self::assertSame('Final Quiz', $quiz['title']);
        self::assertSame(60, (int) $quiz['pass_mark'], 'the pass mark comes from the serialised quiz settings');
        self::assertSame(3, (int) $quiz['max_attempts'], 'two LearnDash repeats are three attempts');
        self::assertSame(600, (int) $quiz['time_limit_seconds'], 'the time limit comes from the ProQuiz master row');

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Final Quiz'));
        self::assertNotNull($lesson);
        self::assertSame((int) $lesson['id'], (int) $quiz['lesson_id']);
    }

    /**
     * A cap the source does not enforce is not a cap.
     *
     * `sfwd-quiz_repeats` applies only when `sfwd-quiz_retry_restrictions`
     * says the quiz restricts retakes, and the ProQuiz master's own
     * `quiz_run_once` is the other half of the same question. Both were read
     * unconditionally or not at all, so a quiz with unlimited attempts and a
     * stale repeats of 2 imported as three attempts.
     */
    public function testAnAttemptCapIsOnlyImportedWhenLearnDashSaysItApplies(): void
    {
        $this->source->replaceMeta(self::QUIZ, [
            '_sfwd-quiz' => WordPressLmsFixture::serialised([
                'sfwd-quiz_passingpercentage' => 60,
                // The restriction turned off, with the number left behind.
                'sfwd-quiz_retry_restrictions' => '',
                'sfwd-quiz_repeats' => 2,
            ]),
        ]);
        $this->source->update('learndash_pro_quiz_master', self::PRO_QUIZ, ['quiz_run_once' => 0]);

        $this->runImport();

        $course = $this->courseTitled('Foundations of Tea');
        $quiz = $this->env->database->selectOne(
            Query::from('quizzes')->where('course_id', '=', (int) $course['id']),
        );

        self::assertNotNull($quiz);
        self::assertNull($quiz['max_attempts'], 'A stale repeats under a restriction that is off is not a cap.');
    }

    /** quiz_run_once was selected and read nowhere. */
    public function testAQuizLearnDashRunsOnceIsImportedAsOneAttempt(): void
    {
        $this->source->update('learndash_pro_quiz_master', self::PRO_QUIZ, ['quiz_run_once' => 1]);

        $this->runImport();

        $course = $this->courseTitled('Foundations of Tea');
        $quiz = $this->env->database->selectOne(
            Query::from('quizzes')->where('course_id', '=', (int) $course['id']),
        );

        self::assertNotNull($quiz);
        self::assertSame(1, (int) $quiz['max_attempts']);
    }

    public function testEveryProQuizTypeWithAFreeHomeArrivesAndTheRestAreNamed(): void
    {
        $job = $this->runImport();

        $questions = $this->questions();

        self::assertSame(
            ['single_choice', 'true_false', 'multiple_choice', 'short_answer', 'ordering', 'fill_blank'],
            array_column($questions, 'type'),
        );

        $single = $this->definitionOf($questions[0]);
        self::assertSame('Which leaf is oolong?', $single['prompt']);
        self::assertSame('o2', $single['correct']);

        self::assertFalse($this->definitionOf($questions[1])['correct'], 'a two answer true or false question maps');

        self::assertSame(['o1', 'o3'], $this->definitionOf($questions[2])['correct']);
        self::assertSame(['Camellia', 'Camellia sinensis'], $this->definitionOf($questions[3])['accepted']);
        self::assertSame(['o1', 'o2', 'o3'], $this->definitionOf($questions[4])['correct']);

        $cloze = $this->definitionOf($questions[5]);
        self::assertStringContainsString('[[b1]]', (string) $cloze['prompt']);
        self::assertSame([['key' => 'b1', 'accepted' => ['four', '4']]], $cloze['blanks']);

        self::assertStringContainsString(
            'question(s) of type "essay" were not imported',
            (string) $job->log,
            'an unmapped type is named rather than converted into something else',
        );
    }

    public function testAMalformedRowIsARowProblemAndNotAFailedJob(): void
    {
        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $job->status,
            'a question nobody can read costs that question and not the migration',
        );
        self::assertStringContainsString('it marks no correct answer', (string) $job->log);
        self::assertStringContainsString(
            'learndash_pro_quiz_question: 2 rows, 0 created, 0 updated, 0 unchanged, 1 skipped, 1 failed.',
            (string) $job->log,
            'the row problems are summarised once per file rather than one log line each',
        );
        self::assertStringContainsString(
            'sfwd-topic: 1 rows, 0 created, 0 updated, 0 unchanged, 1 skipped, 0 failed.',
            (string) $job->log,
            'a topic with neither content nor a video is a deliberate skip, not a rejection',
        );
        self::assertStringContainsString(
            '3 row(s) were rejected',
            (string) $job->log,
            'One line for every rejected row, on RowProblem::wasRejected()'
                . ' alone: a skipped row is a row the operator has to fix and re-upload, so counting only the'
                . ' failed ones left this job saying one where the download offered three.',
        );

        self::assertCount(6, $this->questions(), 'everything readable still arrived');
    }

    public function testARerunUpdatesRatherThanDuplicates(): void
    {
        $first = $this->runImport();
        $second = $this->runImport();

        self::assertNotNull($first->totals);
        self::assertNotNull($second->totals);
        self::assertSame(1, $first->totals[ImportResult::TOTAL_COURSES]);
        self::assertSame(1, $second->totals[ImportResult::TOTAL_COURSES]);

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame(1, $this->env->database->count(Query::from('modules')));
        self::assertSame(4, $this->env->database->count(Query::from('lessons')));
        self::assertSame(6, $this->env->database->count(Query::from('questions')));
    }

    public function testCreateOnlyLeavesAHandCorrectionAlone(): void
    {
        $this->runImport();

        $course = $this->courseTitled('Foundations of Tea');
        $this->kernel->updateCourse->execute($this->admin, (int) $course['id'], ['title' => 'Tea, corrected']);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        $this->courseTitled('Tea, corrected');
    }

    public function testUpdateOnlyCreatesNothingOnASiteThatHasNeverImported(): void
    {
        $job = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]);

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertStringContainsString('left as it is', (string) $job->log);
    }

    public function testCreateWithSuffixMakesASecondCopyAndSaysSo(): void
    {
        $this->runImport();
        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);

        self::assertSame(2, $this->env->database->count(Query::from('courses')));
        $this->courseTitled('Foundations of Tea (LearnDash import)');
    }

    /**
     * create_only against a source that has gained a lesson.
     *
     * UpsertPolicy's own docblock calls create_only "the choice for a file
     * that adds this term's intake to an existing roster", and it created
     * nothing at all: saveCourse() answered null for a course it was leaving
     * alone, write() gave up on the null, and the sections, lessons, quizzes,
     * questions and learners under it were never reached. The Joomla writer
     * has always returned the existing id here, which is why only the three
     * WordPress importers were affected.
     */
    public function testCreateOnlyStillCreatesAChildThatDidNotExistBefore(): void
    {
        $this->runImport();

        $this->source->post(33, 'sfwd-topic', 'Steeping longer', [
            'post_content' => '<p>Six minutes.</p>',
            'menu_order' => 9,
        ]);
        $this->source->meta(33, ['course_id' => self::COURSE, 'lesson_id' => self::LESSON]);

        $job = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);

        self::assertContains(
            'Steeping longer',
            array_column($this->lessons((int) $this->courseTitled('Foundations of Tea')['id']), 'title'),
            'create_only writes rows that are new and leaves the rest alone.',
        );
        self::assertNotNull($job->totals);
        self::assertSame(1, $this->env->database->count(Query::from('courses')), 'and it creates no second course');
    }

    public function testLearnersArriveOnlyWhenTheOptionAsksAndTheirHistoryOnlyWhenItAsksToo(): void
    {
        $job = $this->runImport();
        self::assertStringContainsString('which were not imported', (string) $job->log);
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));

        $withLearners = $this->runImport(['enrolments' => true]);
        self::assertNotNull($withLearners->totals);
        self::assertSame(1, $withLearners->totals[ImportResult::TOTAL_ENROLMENTS]);
        self::assertSame(1, $this->env->database->count(Query::from('entitlements')));
        self::assertSame(
            0,
            $this->env->database->count(Query::from('lesson_progress')),
            'access is a statement about today; a replayed completion is not',
        );

        $again = $this->runImport(['enrolments' => true]);
        self::assertNotNull($again->totals);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('entitlements')),
            'the source reference is what stops a second grant',
        );
        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $again->status);
    }

    /**
     * The learners LearnDash's own published enrolment functions answer with.
     *
     * memberships() read learndash_user_activity alone, which answers "who has
     * done something" rather than "who is enrolled":
     * learndash_get_course_users_access_from_meta() reads the usermeta key
     * course_{id}_access_from. A learner enrolled last week who has not opened
     * the course was left behind, with nothing in the log saying they existed.
     */
    public function testALearnerWithAccessMetaAndNoActivityIsStillEnrolled(): void
    {
        $this->env->users->add(6, 'Newly Enrolled', 'newly@example.test');
        $this->source->userMeta(6, sprintf('course_%d_access_from', self::COURSE), '1700000000');

        $job = $this->runImport(['enrolments' => true]);

        self::assertNotNull($job->totals);
        self::assertSame(
            2,
            $job->totals[ImportResult::TOTAL_ENROLMENTS],
            'The learner with an activity row and the learner with only an access row.',
        );
        self::assertSame(2, $this->env->database->count(Query::from('entitlements')));
    }

    /**
     * A step shared into a second course.
     *
     * steps() found a course's lessons through the primary course_id meta
     * only, which is one course per step. LearnDash's Shared Course Steps
     * writes a secondary association as well, so a course assembled out of
     * shared steps imported with no modules and no row problem: there was
     * nothing there to report a problem about.
     */
    public function testAStepSharedIntoASecondCourseIsImportedIntoIt(): void
    {
        $this->source->post(11, 'sfwd-courses', 'Advanced Tea');
        $this->source->meta(self::LESSON, ['ld_course_11' => 11]);

        $this->runImport();

        $shared = $this->courseTitled('Advanced Tea');
        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $shared['id']),
        );

        self::assertSame(['Leaves'], array_column($modules, 'title'));
    }

    /**
     * A row is counted once, by the outcome it reports.
     *
     * ImportRecorder::countRow already counts every row problem into its
     * outcome's total, which its own docblock gives as the reason that an
     * importer never counts a row twice. Four call sites followed problem()
     * with an explicit counted(TOTAL_SKIPPED), and WordPressImportRun::failed()
     * counted a skipped row for the closing summary sentence, which is a note
     * and not a row. This fixture reported four skips for three skipped things.
     */
    public function testEverySkippedRowIsCountedExactlyOnce(): void
    {
        $job = $this->runImport();

        self::assertNotNull($job->totals);
        self::assertSame(
            3,
            $job->totals[ImportResult::TOTAL_SKIPPED] ?? 0,
            'The untitled topic, the essay question, and the learner the enrolments option left behind.'
                . ' Not the closing sentence, which is a note, and not any of them twice. LOG: ' . $job->log,
        );
        self::assertSame(
            1,
            $job->totals[ImportResult::TOTAL_FAILED] ?? 0,
            'The question with no correct answer, once.',
        );
    }

    /**
     * A lesson with video progression keeps its written content somewhere an
     * operator can find it.
     *
     * LearnDash's video URL setting is progression added to a lesson that
     * keeps its own post_content, not a replacement for it, and the free video
     * lesson type carries a provider and a URL. The body was dropped with
     * nothing said, so the loss was invisible in the log and in the row report
     * alike.
     */
    public function testALessonWithBothAVideoAndItsOwnContentSaysWhatWasDropped(): void
    {
        $this->source->replaceMeta(self::VIDEO_TOPIC, [
            'course_id' => self::COURSE,
            'lesson_id' => self::LESSON,
            '_sfwd-topic' => WordPressLmsFixture::serialised([
                'sfwd-topic_lesson_video_url' => 'https://www.youtube.com/watch?v=abc',
            ]),
        ]);
        $this->source->update('posts', self::VIDEO_TOPIC, ['post_content' => '<p>Pour slowly.</p>'], 'ID');

        $job = $this->runImport();

        $problems = $this->env->database->selectAll(
            Query::from('import_row_problems')->where('import_job_id', '=', $job->id),
        );
        $dropped = array_values(array_filter(
            $problems,
            static fn (array $row): bool => (int) $row['row'] === self::VIDEO_TOPIC,
        ));

        self::assertCount(1, $dropped);
        self::assertSame('created', $dropped[0]['outcome'], 'The lesson was written; only its body was not.');
        self::assertStringContainsString('written content was not imported', (string) $dropped[0]['message']);
    }

    public function testFinishedLessonsAreReplayedThroughTheProgressUseCaseWhenAsked(): void
    {
        $job = $this->runImport(['enrolments' => true, 'progress' => true]);

        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Steeping'));
        self::assertNotNull($lesson);

        $progress = $this->env->database->selectOne(
            Query::from('lesson_progress')->where('lesson_id', '=', (int) $lesson['id']),
        );
        self::assertNotNull($progress, 'the finished topic came across as progress');
        self::assertSame(self::LEARNER_ID, (int) $progress['user_id']);
    }

    public function testTheCurriculumOnlyChoiceLeavesTheQuizzesBehind(): void
    {
        $job = $this->runImport(['content' => 'curriculum_only']);

        self::assertSame(0, $this->env->database->count(Query::from('quizzes')));
        self::assertSame(3, $this->env->database->count(Query::from('lessons')));
        self::assertStringContainsString('asked for the curriculum only', (string) $job->log);
    }

    public function testTheSelectorIsReadAndAnUnreadableOneFailsTheRun(): void
    {
        $job = $this->runImport(source: 'learndash-db:courses=10');
        self::assertSame(ImportJob::STATUS_COMPLETED_WITH_PROBLEMS, $job->status);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));

        $refused = $this->runImport(source: 'learndash-db:groups=3');
        self::assertSame(ImportJob::STATUS_FAILED, $refused->status);
        self::assertStringContainsString('is not a selector this importer understands', (string) $refused->log);
    }

    public function testItRefusesHonestlyOnASiteWithNoLearnDash(): void
    {
        $this->env->database->pdo()->exec('DELETE FROM "posts" WHERE "post_type" = \'sfwd-courses\'');

        $importer = $this->kernel->registries->importers->get(LearnDashImporter::KEY);
        self::assertFalse($importer->supports(self::SOURCE), 'an absent product does not offer itself');

        try {
            $this->runImport();
            self::fail('Unavailable import sources must be rejected before queuing.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException $failure) {
            self::assertArrayHasKey('source', $failure->errors);
        }
        self::assertSame(0, $this->env->database->count(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('import_jobs')));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = [], string $source = self::SOURCE): ImportJob
    {
        return $this->kernel->runImportJob->execute($this->admin, LearnDashImporter::KEY, $source, $options);
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
     * @return list<array<string, mixed>>
     */
    private function lessons(int $courseId): array
    {
        return $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', $courseId)->orderBy('module_id')->orderBy('position'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(): array
    {
        return $this->env->database->selectAll(Query::from('questions')->orderBy('position')->orderBy('id'));
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
     * A LearnDash site with one course: a lesson carrying content, two topics
     * the free build can take, one it cannot, and a quiz holding one question
     * of every ProQuiz type that has a free home plus two that do not.
     */
    private function seed(): void
    {
        $this->source->option('learndash_settings_permalinks', 'a:0:{}');

        $this->source->post(self::COURSE, 'sfwd-courses', 'Foundations of Tea', [
            'post_content' => '<p>About tea.</p>',
            'post_excerpt' => 'A short course.',
        ]);

        $this->source->post(self::LESSON, 'sfwd-lessons', 'Leaves', [
            'post_content' => '<p>Leaf types.</p>',
            'menu_order' => 1,
        ]);
        $this->source->meta(self::LESSON, ['course_id' => self::COURSE]);

        $this->source->post(self::TOPIC, 'sfwd-topic', 'Steeping', [
            'post_content' => '<p>Four minutes.</p>',
            'menu_order' => 1,
        ]);
        $this->source->meta(self::TOPIC, ['course_id' => self::COURSE, 'lesson_id' => self::LESSON]);

        $this->source->post(self::VIDEO_TOPIC, 'sfwd-topic', 'Video pour', ['menu_order' => 2]);
        $this->source->meta(self::VIDEO_TOPIC, [
            'course_id' => self::COURSE,
            'lesson_id' => self::LESSON,
            '_sfwd-topic' => WordPressLmsFixture::serialised([
                'sfwd-topic_lesson_video_url' => 'https://www.youtube.com/watch?v=abc',
            ]),
        ]);

        $this->source->post(self::EMPTY_TOPIC, 'sfwd-topic', 'Nothing here', ['menu_order' => 3]);
        $this->source->meta(self::EMPTY_TOPIC, ['course_id' => self::COURSE, 'lesson_id' => self::LESSON]);

        $this->source->post(self::QUIZ, 'sfwd-quiz', 'Final Quiz', ['menu_order' => 4]);
        $this->source->meta(self::QUIZ, [
            'course_id' => self::COURSE,
            'lesson_id' => self::LESSON,
            'quiz_pro_id' => self::PRO_QUIZ,
            '_sfwd-quiz' => WordPressLmsFixture::serialised([
                'sfwd-quiz_passingpercentage' => 60,
                // The switch and the number, which LearnDash keeps apart: the
                // number is a stale leftover without the restriction on.
                'sfwd-quiz_retry_restrictions' => 'on',
                'sfwd-quiz_repeats' => 2,
            ]),
        ]);

        $this->source->insert('learndash_pro_quiz_master', [
            'id' => self::PRO_QUIZ,
            'name' => 'Final Quiz',
            'time_limit' => 600,
        ]);

        $this->question(100, 1, 'single', 'Which leaf is oolong?', [
            ['answer' => 'Green'],
            ['answer' => 'Oolong', 'correct' => true],
        ]);
        $this->question(101, 2, 'single', 'Tea is a vegetable.', [
            ['answer' => 'True'],
            ['answer' => 'False', 'correct' => true],
        ]);
        $this->question(102, 3, 'multiple', 'Which are teas?', [
            ['answer' => 'Oolong', 'correct' => true],
            ['answer' => 'Rooibos'],
            ['answer' => 'Sencha', 'correct' => true],
        ]);
        $this->question(103, 4, 'free_answer', 'Name the plant.', [
            ['answer' => "Camellia\nCamellia sinensis"],
        ]);
        $this->question(104, 5, 'sort_answer', 'Put these in order.', [
            ['answer' => 'Boil'],
            ['answer' => 'Steep'],
            ['answer' => 'Pour'],
        ]);
        $this->question(105, 6, 'cloze_answer', 'Fill the gap.', [
            ['answer' => 'Steep for {four|4} minutes.'],
        ]);
        $this->question(106, 7, 'essay', 'Discuss tea.', []);
        $this->question(107, 8, 'single', 'Which is nothing?', [
            ['answer' => 'One'],
            ['answer' => 'Two'],
        ]);

        $this->source->insert('learndash_user_activity', [
            'activity_id' => 900,
            'user_id' => self::LEARNER_ID,
            'post_id' => self::COURSE,
            'course_id' => self::COURSE,
            'activity_type' => 'course',
            'activity_status' => 0,
        ]);
        $this->source->insert('learndash_user_activity', [
            'activity_id' => 901,
            'user_id' => self::LEARNER_ID,
            'post_id' => self::TOPIC,
            'course_id' => self::COURSE,
            'activity_type' => 'topic',
            'activity_status' => 1,
        ]);
    }

    /**
     * @param list<array{answer: string, correct?: bool, points?: int}> $answers
     */
    private function question(int $id, int $sort, string $type, string $question, array $answers): void
    {
        $this->source->insert('learndash_pro_quiz_question', [
            'id' => $id,
            'quiz_id' => self::PRO_QUIZ,
            'sort' => $sort,
            'title' => $question,
            'points' => 1,
            'question' => $question,
            'answer_type' => $type,
            'answer_data' => WordPressLmsFixture::answerData($answers),
        ]);
    }
}
