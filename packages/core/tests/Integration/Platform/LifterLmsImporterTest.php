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
use Shondalai\VidyaLMS\Core\Provider\Importer\LifterLmsImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Core\Tests\Support\WordPressLmsFixture;

/**
 * The LifterLMS importer against a synthetic LifterLMS database: the post
 * types, the `_llms_*` relation and settings meta, the repeated choice meta and
 * the `lifterlms_user_postmeta` table the importer's docblock names.
 *
 * The fixture reproduces the documented schema and nothing else. It cannot
 * prove the importer against a real LifterLMS, and the importer says so in its
 * own label; what it does prove is that every mapping this build claims is the
 * mapping it makes.
 */
final class LifterLmsImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const LAPSED_ID = 5;
    private const SOURCE = 'lifterlms-db:';

    private const COURSE = 10;
    private const SECTION = 20;
    private const LESSON = 30;
    private const QUIZ_LESSON = 31;
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
        $this->env->users->add(self::LAPSED_ID, 'Lars Lapsed', 'lars@example.test');
        $this->kernel = new CoreKernel($this->env->ports);
        $this->admin = Principal::user(self::ADMIN_ID);
        $this->source = WordPressLmsFixture::install($this->env->database, WordPressLmsFixture::LIFTERLMS);
        $this->seed();
    }

    public function testItIsRegisteredAndClaimsOnlyItsOwnSchemeOnASiteThatHasLifterLms(): void
    {
        self::assertContains(LifterLmsImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(LifterLmsImporter::KEY);

        self::assertTrue($importer->supports('lifterlms-db:'));
        self::assertTrue($importer->supports('lifterlms-db:courses=10'));
        self::assertFalse($importer->supports('tutor-db:'));
        self::assertFalse($importer->supports('cms-db:'));
    }

    public function testItsLabelAndItsLogBothSayItIsUnverifiedAgainstARealInstall(): void
    {
        $key = $this->kernel->registries->importers->get(LifterLmsImporter::KEY)->label();

        self::assertSame('vidyalms.importer.lifterlms', $key);
        self::assertStringContainsString(
            'unverified against a real install',
            (new CatalogueTranslator())->translate($key),
        );
        self::assertStringContainsString(
            'has not been verified against a real LifterLMS install',
            (string) $this->runImport()->log,
        );
    }

    public function testASectionBecomesAModuleAndAQuizFollowsTheLessonThatNamesIt(): void
    {
        $course = $this->import();

        $modules = $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', $course)->orderBy('position'),
        );
        self::assertSame(['Knots'], array_column($modules, 'title'));

        $lessons = $this->env->database->selectAll(
            Query::from('lessons')->where('course_id', '=', $course)->orderBy('position'),
        );
        self::assertSame(
            ['Bowline', 'Cleat hitch', 'Knots quiz'],
            array_column($lessons, 'title'),
            'the LifterLMS order meta decides the curriculum, and the quiz follows its lesson',
        );
        self::assertSame(['reading', 'reading', 'quiz'], array_column($lessons, 'type'));
    }

    public function testTheQuizSettingsComeOutOfTheLlmsMeta(): void
    {
        $this->source->meta(self::QUIZ, [
            '_llms_limit_attempts' => 'yes',
            '_llms_limit_time' => 'yes',
        ]);

        $this->import();

        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('title', '=', 'Knots quiz'));

        self::assertNotNull($quiz);
        self::assertSame(65, (int) $quiz['pass_mark']);
        self::assertSame(3, (int) $quiz['max_attempts']);
        self::assertSame(1200, (int) $quiz['time_limit_seconds'], 'LifterLMS writes the limit in minutes');
    }

    /**
     * A cap the source does not enforce is not a cap.
     *
     * LifterLMS keeps the number and the switch apart, so a quiz whose limits
     * were turned off carries the stale numbers for as long as nobody edits
     * it. Reading the numbers without their flags gave the learner a lock-out
     * the source never had.
     */
    /**
     * The link LifterLMS publishes is the meta, not the column.
     *
     * `LLMS_Question` is an `LLMS_Post_Model` whose reference declares
     * `'parent_id' => 'absint'`, which is the `_llms_parent_id` post meta, and
     * nothing in the product's published documentation says `post_parent`
     * carries it. Reading the column alone found questions only on a site that
     * happens to set it too, and the fixture seeded `post_parent` itself, which
     * is why the suite could not see this.
     */
    public function testAQuestionLinkedOnlyByTheParentMetaIsStillFound(): void
    {
        // No post_parent at all, which is what the published link does not
        // require.
        $this->source->post(70, 'llms_question', 'Named by meta alone', ['menu_order' => 9]);
        $this->source->meta(70, [
            '_llms_parent_id' => self::QUIZ,
            '_llms_question_type' => 'true_false',
            '_llms_points' => 1,
        ]);
        $this->choice(70, 'True', true);
        $this->choice(70, 'False', false);

        $this->import();

        self::assertContains(
            'Named by meta alone',
            array_map(
                static fn (array $row): string => (string) $row['prompt'],
                array_map(
                    static fn (array $row): array => (array) json_decode((string) $row['definition'], true),
                    $this->env->database->selectAll(Query::from('questions')),
                ),
            ),
        );
    }

    /**
     * A question group's own children are parented on the group, not on the
     * quiz.
     *
     * Treating "group" as an unmapped type lost every question inside it,
     * reported as one unmapped row and nothing about the questions themselves.
     */
    public function testTheQuestionsInsideAGroupAreFollowedRatherThanLost(): void
    {
        $this->source->post(80, 'llms_question', 'A group', ['menu_order' => 8]);
        $this->source->meta(80, ['_llms_parent_id' => self::QUIZ, '_llms_question_type' => 'group']);

        $this->source->post(81, 'llms_question', 'Inside the group', ['menu_order' => 1]);
        $this->source->meta(81, [
            '_llms_parent_id' => 80,
            '_llms_question_type' => 'true_false',
            '_llms_points' => 1,
        ]);
        $this->choice(81, 'True', true);
        $this->choice(81, 'False', false);

        $this->import();

        $prompts = array_map(
            static fn (array $row): string => (string) ((array) json_decode((string) $row['definition'], true))['prompt'],
            $this->env->database->selectAll(Query::from('questions')),
        );

        self::assertContains('Inside the group', $prompts);
        self::assertNotContains('A group', $prompts, 'A group is a container, not a question.');
    }

    public function testALimitWhoseOwnFlagIsOffIsNotImported(): void
    {
        $this->import();

        $quiz = $this->env->database->selectOne(Query::from('quizzes')->where('title', '=', 'Knots quiz'));

        self::assertNotNull($quiz);
        self::assertNull($quiz['max_attempts'], 'The stale _llms_allowed_attempts of 3 is not a cap.');
        self::assertNull($quiz['time_limit_seconds'], 'The stale _llms_time_limit of 20 is not a limit.');
    }

    public function testEveryLifterTypeWithAFreeHomeArrivesAndTheRestAreNamed(): void
    {
        $job = $this->runImport();
        $questions = $this->questions();

        self::assertSame(
            ['single_choice', 'true_false', 'multiple_choice', 'fill_blank', 'ordering', 'short_answer'],
            array_column($questions, 'type'),
        );

        $single = $this->definitionOf($questions[0]);
        self::assertSame('o2', $single['correct']);
        self::assertSame(2.0, (float) $questions[0]['points'], 'the LifterLMS points meta comes across');

        self::assertFalse($this->definitionOf($questions[1])['correct']);
        self::assertSame(['o1', 'o3'], $this->definitionOf($questions[2])['correct']);

        $blank = $this->definitionOf($questions[3]);
        self::assertStringContainsString('[[b1]]', (string) $blank['prompt']);
        self::assertSame([['key' => 'b1', 'accepted' => ['fixed']]], $blank['blanks']);

        self::assertSame(['o1', 'o2', 'o3'], $this->definitionOf($questions[4])['correct']);

        $long = $this->definitionOf($questions[5]);
        self::assertSame([], $long['accepted']);
        self::assertSame('human', $long['scoring_intent']);

        self::assertStringContainsString(
            'question(s) of type "picture_choice" were not imported',
            (string) $job->log,
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
        self::assertStringContainsString('it marks no correct choice', (string) $job->log);
        self::assertStringContainsString(
            'llms_question: 2 rows, 0 created, 0 updated, 0 unchanged, 1 skipped, 1 failed.',
            (string) $job->log,
        );
        self::assertStringContainsString(
            'lesson: 4 rows, 3 created, 0 updated, 0 unchanged, 1 skipped, 0 failed.',
            (string) $job->log,
            'a lesson with no content is a deliberate skip, not a rejection, and its file counts both sides',
        );
        self::assertStringContainsString(
            '3 row(s) were rejected',
            (string) $job->log,
            'One line for every rejected row, on RowProblem::wasRejected() alone: a skipped row is a row'
                . ' the operator has to fix, so counting only the failed ones left this job saying one where'
                . ' the download offered three.',
        );
        self::assertCount(6, $this->questions(), 'everything readable still arrived');
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
        $course = $this->courseTitled('Sailing');
        $this->kernel->updateCourse->execute($this->admin, (int) $course['id'], ['title' => 'Sailing, corrected']);

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_ONLY]);
        $this->courseTitled('Sailing, corrected');

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_AND_UPDATE]);
        $this->courseTitled('Sailing');
        self::assertSame(1, $this->env->database->count(Query::from('courses')));

        $this->runImport([UpsertPolicy::OPTION_KEY => UpsertPolicy::CREATE_WITH_SUFFIX]);
        self::assertSame(2, $this->env->database->count(Query::from('courses')));
        $this->courseTitled('Sailing (LifterLMS import)');
    }

    public function testOnlyAnEnrolledStatusBecomesAnEntitlement(): void
    {
        $this->runImport();
        self::assertSame(0, $this->env->database->count(Query::from('entitlements')));

        $job = $this->runImport(['enrolments' => true]);

        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_ENROLMENTS], 'the expired learner is not one');

        $entitlement = $this->env->database->selectOne(Query::from('entitlements'));
        self::assertNotNull($entitlement);
        self::assertSame(self::LEARNER_ID, (int) $entitlement['user_id']);

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

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Bowline'));
        self::assertNotNull($lesson);

        $progress = $this->env->database->selectOne(
            Query::from('lesson_progress')->where('lesson_id', '=', (int) $lesson['id']),
        );
        self::assertNotNull($progress);
        self::assertSame(self::LEARNER_ID, (int) $progress['user_id']);
    }

    public function testItRefusesHonestlyOnASiteWithNoLifterLms(): void
    {
        $this->env->database->pdo()->exec('DROP TABLE "lifterlms_user_postmeta"');

        $importer = $this->kernel->registries->importers->get(LifterLmsImporter::KEY);
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
        return $this->kernel->runImportJob->execute($this->admin, LifterLmsImporter::KEY, $source, $options);
    }

    private function import(): int
    {
        $this->runImport();

        return (int) $this->courseTitled('Sailing')['id'];
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
     * A LifterLMS site with one course, one section, two lessons the free build
     * can take and one it cannot, and a quiz holding one question of every
     * LifterLMS type that has a free home plus two that do not.
     */
    private function seed(): void
    {
        $this->source->option('lifterlms_version', '7.5.0');

        $this->source->post(self::COURSE, 'course', 'Sailing', [
            'post_content' => '<p>About sailing.</p>',
            'post_excerpt' => 'A short course.',
        ]);

        $this->source->post(self::SECTION, 'section', 'Knots', ['post_excerpt' => 'The rope part.']);
        $this->source->meta(self::SECTION, ['_llms_parent_course' => self::COURSE, '_llms_order' => 1]);

        $this->source->post(self::LESSON, 'lesson', 'Bowline', ['post_content' => '<p>A fixed loop.</p>']);
        $this->source->meta(self::LESSON, [
            '_llms_parent_course' => self::COURSE,
            '_llms_parent_section' => self::SECTION,
            '_llms_order' => 1,
        ]);

        $this->source->post(self::QUIZ_LESSON, 'lesson', 'Cleat hitch', ['post_content' => '<p>Figure of eight.</p>']);
        $this->source->meta(self::QUIZ_LESSON, [
            '_llms_parent_course' => self::COURSE,
            '_llms_parent_section' => self::SECTION,
            '_llms_order' => 2,
            '_llms_quiz' => self::QUIZ,
        ]);

        $this->source->post(self::EMPTY_LESSON, 'lesson', 'Nothing here');
        $this->source->meta(self::EMPTY_LESSON, [
            '_llms_parent_course' => self::COURSE,
            '_llms_parent_section' => self::SECTION,
            '_llms_order' => 3,
        ]);

        $this->source->post(self::QUIZ, 'llms_quiz', 'Knots quiz');
        $this->source->meta(self::QUIZ, [
            '_llms_lesson_id' => self::QUIZ_LESSON,
            '_llms_passing_percent' => 65,
            // The two numbers are seeded without their flags, which is the
            // shape a quiz whose limits were turned off really has: LifterLMS
            // leaves the number behind. The test that expects them imported
            // turns the flags on for itself.
            '_llms_allowed_attempts' => 3,
            '_llms_time_limit' => 20,
        ]);

        $this->question(50, 1, 'choice', 'Which knot?', ['points' => 2]);
        $this->choice(50, 'Sheet bend', false);
        $this->choice(50, 'Bowline', true);

        $this->question(51, 2, 'true_false', 'A bowline slips.');
        $this->choice(51, 'True', false);
        $this->choice(51, 'False', true);

        $this->question(52, 3, 'choice', 'Pick the ropes.', ['multi' => true]);
        $this->choice(52, 'Sheet', true);
        $this->choice(52, 'Cleat', false);
        $this->choice(52, 'Halyard', true);

        $this->question(53, 4, 'blank', 'Fill the gap.', ['content' => 'A bowline makes a ___ loop.']);
        $this->choice(53, 'fixed', true);

        $this->question(54, 5, 'reorder', 'Order the steps.');
        $this->choice(54, 'Loop', false);
        $this->choice(54, 'Pass', false);
        $this->choice(54, 'Tighten', false);

        $this->question(55, 6, 'long_answer', 'Explain when you would use it.');

        $this->question(56, 7, 'picture_choice', 'Which picture?');
        $this->choice(56, 'One', true);

        $this->question(57, 8, 'choice', 'Which is nothing?');
        $this->choice(57, 'One', false);
        $this->choice(57, 'Two', false);

        $this->userPostMeta(self::LEARNER_ID, self::COURSE, '_status', 'enrolled');
        $this->userPostMeta(self::LEARNER_ID, self::LESSON, '_is_complete', 'yes');
        $this->userPostMeta(self::LAPSED_ID, self::COURSE, '_status', 'expired');
    }

    /**
     * @param array{points?: int, multi?: bool, content?: string} $extra
     */
    private function question(int $id, int $order, string $type, string $title, array $extra = []): void
    {
        $this->source->post($id, 'llms_question', $title, [
            'post_parent' => self::QUIZ,
            'menu_order' => $order,
            'post_content' => $extra['content'] ?? '',
        ]);

        $meta = ['_llms_question_type' => $type, '_llms_points' => $extra['points'] ?? 1];

        if (($extra['multi'] ?? false) === true) {
            $meta['_llms_multi_choices'] = 'yes';
        }

        $this->source->meta($id, $meta);
    }

    private function choice(int $questionId, string $text, bool $correct): void
    {
        $this->source->insert('postmeta', [
            'post_id' => $questionId,
            'meta_key' => '_llms_choice',
            'meta_value' => WordPressLmsFixture::serialised([
                'id' => $questionId . ':' . $text,
                'question_id' => $questionId,
                'choice' => $text,
                'choice_type' => 'text',
                'correct' => $correct ? 'yes' : 'no',
            ]),
        ]);
    }

    private function userPostMeta(int $userId, int $postId, string $key, string $value): void
    {
        $this->source->insert('lifterlms_user_postmeta', [
            'user_id' => $userId,
            'post_id' => $postId,
            'meta_key' => $key,
            'meta_value' => $value,
            'updated_date' => '2024-03-03 10:00:00',
        ]);
    }
}
