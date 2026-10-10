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
use Shondalai\VidyaLMS\Core\Provider\Importer\TutorLmsImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Core\Tests\Support\WordPressLmsFixture;

/**
 * The Tutor LMS importer against a synthetic Tutor database: the post types
 * and their `post_parent` tree, the serialised quiz options, the two question
 * tables and the completion user meta the importer's docblock names.
 *
 * The fixture reproduces the documented schema and nothing else. It cannot
 * prove the importer against a real Tutor LMS, and the importer says so in its
 * own label; what it does prove is that every mapping this build claims is the
 * mapping it makes.
 */
final class TutorLmsImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'tutor-db:';

    private const COURSE = 10;
    private const TOPIC = 20;
    private const LESSON = 30;
    private const VIDEO_LESSON = 31;
    private const EMPTY_LESSON = 32;
    private const QUIZ = 40;

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
        $this->source = WordPressLmsFixture::install($this->env->database, WordPressLmsFixture::TUTOR);
        $this->seed();
    }

    public function testItIsRegisteredAndClaimsOnlyItsOwnSchemeOnASiteThatHasTutor(): void
    {
        self::assertContains(TutorLmsImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(TutorLmsImporter::KEY);

        self::assertTrue($importer->supports('tutor-db:'));
        self::assertTrue($importer->supports('tutor-db:courses=10'));
        self::assertFalse($importer->supports('learndash-db:'));
        self::assertFalse($importer->supports('cms-db:'));
    }

    public function testItsLabelAndItsLogBothSayItIsUnverifiedAgainstARealInstall(): void
    {
        $key = $this->kernel->registries->importers->get(TutorLmsImporter::KEY)->label();

        self::assertSame('vidyalms.importer.tutor_lms', $key);
        self::assertStringContainsString(
            'unverified against a real install',
            (new CatalogueTranslator())->translate($key),
        );
        self::assertStringContainsString(
            'has not been verified against a real Tutor LMS install',
            (string) $this->runImport()->log,
        );
    }

    public function testATopicBecomesAModuleAndItsLessonsAndQuizKeepOneOrder(): void
    {
        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            $job->status,
            'the fixture carries one unreadable question on purpose, so this run finishes with problems',
        );

        $course = $this->courseTitled('Baking Bread');
        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(['Dough'], array_column($modules, 'title'));

        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', (int) $course['id'])->orderBy('position'),
        );
        self::assertSame(
            ['Kneading', 'Watch the fold', 'Dough quiz'],
            array_column($lessons, 'title'),
            'a quiz keeps its place in the curriculum rather than being swept to the end',
        );
        self::assertSame(['reading', 'video', 'quiz'], array_column($lessons, 'type'));
    }

    public function testTheQuizSettingsComeOutOfTheSerialisedOptionBag(): void
    {
        $this->runImport();

        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('title', '=', 'Dough quiz'));

        self::assertNotNull($quiz);
        self::assertSame(70, (int) $quiz['pass_mark']);
        self::assertSame(2, (int) $quiz['max_attempts']);
        self::assertSame(900, (int) $quiz['time_limit_seconds'], 'fifteen minutes is nine hundred seconds');
    }

    public function testEveryTutorTypeWithAFreeHomeArrivesAndTheRestAreNamed(): void
    {
        $job = $this->runImport();
        $questions = $this->questions();

        self::assertSame(
            ['true_false', 'single_choice', 'multiple_choice', 'short_answer', 'fill_blank', 'ordering'],
            array_column($questions, 'type'),
        );

        self::assertTrue($this->definitionOf($questions[0])['correct']);
        self::assertSame('o2', $this->definitionOf($questions[1])['correct']);
        self::assertSame(['o1', 'o3'], $this->definitionOf($questions[2])['correct']);

        $open = $this->definitionOf($questions[3]);
        self::assertSame([], $open['accepted'], 'Tutor stores no correct answer for an open question');
        self::assertSame(
            'human',
            $open['scoring_intent'],
            'so the question says it needs a grader rather than being marked wrong by a machine',
        );

        $blank = $this->definitionOf($questions[4]);
        self::assertStringContainsString('[[b1]]', (string) $blank['prompt']);
        self::assertSame([['key' => 'b1', 'accepted' => ['two']]], $blank['blanks']);

        self::assertSame(['o1', 'o2', 'o3'], $this->definitionOf($questions[5])['correct']);

        self::assertStringContainsString('question(s) of type "matching" were not imported', (string) $job->log);
        self::assertStringContainsString('graded by hand became a short answer', (string) $job->log);
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
            'tutor_quiz_questions: 2 rows, 0 created, 0 updated, 0 unchanged, 1 skipped, 1 failed.',
            (string) $job->log,
        );
        self::assertStringContainsString(
            '3 row(s) were rejected',
            (string) $job->log,
            'One line for every rejected row, on RowProblem::wasRejected() alone: a skipped row is a row'
                . ' the operator has to fix, so counting only the failed ones left this job saying one where'
                . ' the download offered three.',
        );
        self::assertCount(6, $this->questions());
    }

    public function testARerunUpdatesRatherThanDuplicates(): void
    {
        $this->runImport();
        $this->runImport();

        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame(1, $this->env->database->count(Query::from('modules')));
        self::assertSame(3, $this->env->database->count(Query::from('lessons')));
        self::assertSame(6, $this->env->database->count(Query::from('questions')));
    }

    public function testTheFourUpsertChoicesEachDoWhatTheyName(): void
    {
        $update = $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::UPDATE_ONLY]);
        self::assertSame(0, $this->env->database->count(Query::from('courses')), 'update_only creates nothing');
        self::assertStringContainsString('left as it is', (string) $update->log);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        $course = $this->courseTitled('Baking Bread');
        $this->kernel->updateCourse->execute($this->admin, (int) $course['id'], ['title' => 'Bread, corrected']);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        $this->courseTitled('Bread, corrected');

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_AND_UPDATE]);
        $this->courseTitled('Baking Bread');
        self::assertSame(1, $this->env->database->count(Query::from('courses')));

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);
        self::assertSame(2, $this->env->database->count(Query::from('courses')));
        $this->courseTitled('Baking Bread (Tutor LMS import)');
    }

    public function testAnEnrolmentPostBecomesAnEntitlementOnlyWhenTheOptionAsks(): void
    {
        $this->runImport();
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));

        $job = $this->runImport(['enrolments' => true]);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_ENROLMENTS]);

        $this->runImport(['enrolments' => true]);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('entitlements')),
            'the source reference is what stops a second grant',
        );
    }

    public function testAFinishedLessonIsReplayedThroughTheProgressUseCaseWhenAsked(): void
    {
        $this->runImport(['enrolments' => true, 'progress' => true]);

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Kneading'));
        self::assertNotNull($lesson);

        $progress = $this->env->database->selectOne(
            Query::from('lesson_progress')->where('lesson_id', '=', (int) $lesson['id']),
        );
        self::assertNotNull($progress);
        self::assertSame(self::LEARNER_ID, (int) $progress['user_id']);
    }

    /**
     * A learner's history from a course this run is not importing.
     *
     * Tutor records a completion as one usermeta row per lesson, keyed
     * `_tutor_completed_lesson_id_{id}`, with nothing in it naming the course.
     * completedLessons() prefix-matches the whole usermeta table, so the draft
     * for one course carried every lesson that learner has ever finished
     * anywhere on the site: the writer then reported a referential miss naming
     * a lesson this run really did import, or failed the row outright when the
     * lesson belonged to a different course.
     */
    public function testALessonFinishedInAnotherCourseIsNotReplayedAgainstThisOne(): void
    {
        // A lesson of some other course entirely, finished by the same learner.
        $this->source->post(220, 'lesson', 'Somebody else\'s lesson');
        $this->source->userMeta(self::LEARNER_ID, '_tutor_completed_lesson_id_220', '2024-03-03 09:00:00');

        $job = $this->runImport(['enrolments' => true, 'progress' => true]);

        $misses = $this->env->database->selectAll(
            Query::from('import_row_problems')
                ->where('import_job_id', '=', $job->id)
                ->where('code', '=', 'referential_miss'),
        );

        self::assertSame(
            [],
            $misses,
            'The other course\'s lesson is not this course\'s history, so it is never offered to the writer.',
        );
        self::assertSame(
            1,
            $this->env->database->count(Query::from('lesson_progress')),
            'Exactly the one lesson of this course the learner finished.',
        );
    }

    public function testItRefusesHonestlyOnASiteWithNoTutor(): void
    {
        $this->env->database->pdo()->exec('DROP TABLE "tutor_quiz_questions"');

        $importer = $this->kernel->registries->importers->get(TutorLmsImporter::KEY);
        self::assertFalse($importer->supports(self::SOURCE));

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
        return $this->kernel->runImportJob->execute($this->admin, TutorLmsImporter::KEY, $source, $options);
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
     * A Tutor site with one course, one topic, two lessons the free build can
     * take and one it cannot, and a quiz holding one question of every Tutor
     * type that has a free home plus two that do not.
     */
    private function seed(): void
    {
        $this->source->option('tutor_version', '2.7.0');

        $this->source->post(self::COURSE, 'courses', 'Baking Bread', [
            'post_content' => '<p>About bread.</p>',
            'post_excerpt' => 'A short course.',
        ]);
        $this->source->post(self::TOPIC, 'topics', 'Dough', [
            'post_parent' => self::COURSE,
            'post_content' => 'The wet part.',
            'menu_order' => 1,
        ]);

        $this->source->post(self::LESSON, 'lesson', 'Kneading', [
            'post_parent' => self::TOPIC,
            'post_content' => '<p>Push and fold.</p>',
            'menu_order' => 1,
        ]);
        $this->source->post(self::VIDEO_LESSON, 'lesson', 'Watch the fold', [
            'post_parent' => self::TOPIC,
            'menu_order' => 2,
        ]);
        $this->source->meta(self::VIDEO_LESSON, [
            '_video' => WordPressLmsFixture::serialised([
                'source' => 'youtube',
                'source_youtube' => 'https://www.youtube.com/watch?v=fold',
            ]),
        ]);
        $this->source->post(self::EMPTY_LESSON, 'lesson', 'Nothing here', [
            'post_parent' => self::TOPIC,
            'menu_order' => 3,
        ]);

        $this->source->post(self::QUIZ, 'tutor_quiz', 'Dough quiz', [
            'post_parent' => self::TOPIC,
            'menu_order' => 4,
        ]);
        $this->source->meta(self::QUIZ, [
            'tutor_quiz_option' => WordPressLmsFixture::serialised([
                'passing_grade' => 70,
                'attempts_allowed' => 2,
                'time_limit' => ['time_value' => 15, 'time_type' => 'minutes'],
            ]),
        ]);

        $this->question(100, 1, 'true_false', 'Bread needs yeast.');
        $this->answer(100, 1, 'True', true);
        $this->answer(100, 2, 'False', false);

        $this->question(101, 2, 'single_choice', 'Which flour?');
        $this->answer(101, 1, 'Cake', false);
        $this->answer(101, 2, 'Bread', true);

        $this->question(102, 3, 'multiple_choice', 'Which are grains?');
        $this->answer(102, 1, 'Rye', true);
        $this->answer(102, 2, 'Basil', false);
        $this->answer(102, 3, 'Spelt', true);

        $this->question(103, 4, 'open_ended', 'Describe the crumb.');

        $this->question(104, 5, 'fill_in_the_blank', 'Fill it in.');
        $this->insertAnswer(104, 1, 'Proof for {dash} hours.', false, 'two');

        $this->question(105, 6, 'ordering', 'Order the steps.');
        $this->answer(105, 1, 'Mix', false);
        $this->answer(105, 2, 'Prove', false);
        $this->answer(105, 3, 'Bake', false);

        $this->question(106, 7, 'matching', 'Match the pairs.');
        $this->answer(106, 1, 'Rye', false);

        $this->question(107, 8, 'single_choice', 'Which is nothing?');
        $this->answer(107, 1, 'One', false);
        $this->answer(107, 2, 'Two', false);

        $this->source->post(50, 'tutor_enrolled', 'Enrolment', [
            'post_parent' => self::COURSE,
            'post_author' => self::LEARNER_ID,
            'post_status' => 'completed',
        ]);
        $this->source->userMeta(self::LEARNER_ID, '_tutor_completed_lesson_id_' . self::LESSON, '2024-02-02 09:00:00');
    }

    private function question(int $id, int $order, string $type, string $title): void
    {
        $this->source->insert('tutor_quiz_questions', [
            'question_id' => $id,
            'quiz_id' => self::QUIZ,
            'question_title' => $title,
            'question_description' => '',
            'question_type' => $type,
            'question_mark' => 1,
            'question_order' => $order,
        ]);
    }

    private function answer(int $questionId, int $order, string $title, bool $correct): void
    {
        $this->insertAnswer($questionId, $order, $title, $correct, '');
    }

    private function insertAnswer(int $questionId, int $order, string $title, bool $correct, string $gap): void
    {
        $this->source->insert('tutor_quiz_question_answers', [
            'answer_id' => $questionId * 10 + $order,
            'belongs_question_id' => $questionId,
            'belongs_question_type' => '',
            'answer_title' => $title,
            'is_correct' => $correct ? 1 : 0,
            'answer_two_gap_match' => $gap,
            'answer_order' => $order,
        ]);
    }
}
