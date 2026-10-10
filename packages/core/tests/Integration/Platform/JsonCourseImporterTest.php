<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\JsonCourseImporter;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The reference importer end to end: a JSON document in private storage
 * becomes a real course whose lessons render and whose questions grade, all
 * of it built through the same use cases the authoring UI calls.
 */
final class JsonCourseImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const LEARNER_ID = 4;
    private const SOURCE = 'imports/9/tea.json';

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $admin;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $this->env->users->add(self::ADMIN_ID, 'Ada Admin');
        $this->env->users->add(self::LEARNER_ID, 'Lena Learner', 'lena@example.test');
        $this->kernel = new CoreKernel($this->env->ports);
        $this->admin = Principal::user(self::ADMIN_ID);
    }

    public function testTheImporterIsRegisteredAndClaimsOnlyJsonPaths(): void
    {
        self::assertContains(JsonCourseImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(JsonCourseImporter::KEY);

        self::assertTrue($importer->supports('imports/9/course.json'));
        self::assertTrue($importer->supports('IMPORTS/9/COURSE.JSON'));
        self::assertFalse($importer->supports('imports/9/course.mbz'));
        self::assertFalse($importer->supports('cms-db:'), 'a scheme is never a stored file');
    }

    public function testARoundTripBuildsACourseWhoseLessonsAndQuestionsAreReal(): void
    {
        $this->store($this->document());

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_MODULES]);
        self::assertSame(3, $job->totals[ImportResult::TOTAL_LESSONS]);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_QUIZZES]);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_QUESTIONS]);

        $course = $this->course();

        self::assertSame('Foundations of Tea', $course['title']);
        self::assertSame(
            CourseState::Draft->value,
            $course['state'],
            'a migration is reviewed by a person before learners see it',
        );

        $modules = $this->modules((int) $course['id']);
        self::assertCount(2, $modules);
        self::assertSame(['Leaves', 'Water'], array_column($modules, 'title'));
        self::assertSame([1, 2], array_map('intval', array_column($modules, 'position')));

        $lessons = $this->lessons((int) $course['id']);
        self::assertSame(['Read the leaves', 'Prove it', 'Boil it'], array_column($lessons, 'title'));

        // The content is the provider's own shape, stored and read back
        // through the repository, so the lesson type validated it.
        self::assertSame(
            ['body' => '<p>Steep for four minutes.</p>'],
            $this->lessonContent((int) $lessons[0]['id']),
        );
        self::assertTrue((bool) $lessons[0]['free_preview']);
        self::assertSame(240, (int) $lessons[0]['duration_seconds']);

        $quiz = $this->quiz();
        self::assertSame('Final Quiz', $quiz['title']);
        self::assertSame(60, (int) $quiz['pass_mark']);
        self::assertSame(1, (int) $quiz['is_final']);
        self::assertSame(
            (int) $lessons[1]['id'],
            (int) $quiz['lesson_id'],
            'the quiz lesson is bound back to its quiz once both exist',
        );

        $questions = $this->questions((int) $quiz['id']);
        self::assertCount(2, $questions);
        self::assertSame(['single_choice', 'true_false'], array_column($questions, 'type'));
        self::assertSame(
            'Which leaf is oolong?',
            $this->definitionOf($questions[0])['prompt'],
        );
    }

    public function testTheQuestionsGradeThroughTheirRegisteredType(): void
    {
        $this->store($this->document());
        $this->runImport();

        $quiz = $this->quiz();
        $questions = $this->questions((int) $quiz['id']);
        $type = $this->kernel->registries->questionTypes->get((string) $questions[0]['type']);

        $correct = $type->grade($this->definitionOf($questions[0]), 'b');
        $wrong = $type->grade($this->definitionOf($questions[0]), 'a');

        self::assertSame(1.0, $correct->fraction);
        self::assertSame(0.0, $wrong->fraction);
    }

    public function testADryRunWritesNothingAndSaysSo(): void
    {
        $this->store($this->document());

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertSame(0, $this->env->database->count(Query::from('lessons')));
        self::assertSame(0, $this->env->database->count(Query::from('quizzes')));
    }

    public function testASecondRunUpdatesTheSameCourseRatherThanBuildingASecond(): void
    {
        $this->store($this->document());
        $this->runImport();

        $document = $this->document();
        $document['title'] = 'Foundations of Tea, Revised';
        $document['modules'][0]['lessons'][0]['title'] = 'Read the leaves again';
        $this->store($document);

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(1, $this->env->database->count(Query::from('courses')), 'identity is the key, never the path');
        self::assertSame(3, $this->env->database->count(Query::from('lessons')));
        self::assertSame('Foundations of Tea, Revised', $this->course()['title']);
        self::assertSame('Read the leaves again', $this->lessons((int) $this->course()['id'])[0]['title']);
    }

    public function testTheSameFileUploadedTwiceUnderADifferentPathIsStillOneCourse(): void
    {
        $keyless = $this->document();
        unset($keyless['key']);

        $this->store($keyless);
        $this->runImport();

        $this->env->storage->putPrivate(
            'imports/9/tea-again.json',
            (string) json_encode($keyless, JSON_THROW_ON_ERROR),
        );

        $second = $this->kernel->runImportJob->execute(
            $this->admin,
            JsonCourseImporter::KEY,
            'imports/9/tea-again.json',
            [],
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('courses')),
            'a keyless document is identified by a digest of its content, not by where it was uploaded',
        );
    }

    public function testAKeylessDocumentWarnsWithTheKeyToUseNextTime(): void
    {
        $keyless = $this->document();
        unset($keyless['key']);
        $this->store($keyless);

        $job = $this->runImport();

        self::assertNotNull($job->log);
        self::assertStringContainsString(
            'course key',
            (string) $job->log,
            'the log names the key an operator needs to correct and re-import the file',
        );
    }

    public function testTheCourseKeyOptionOverridesTheDocumentsOwnKey(): void
    {
        $this->store($this->document());
        $this->runImport(['course_key' => 'tea-a']);

        $document = $this->document();
        $document['title'] = 'A Different Course Entirely';
        $this->store($document);
        $this->runImport(['course_key' => 'tea-b']);

        self::assertSame(
            2,
            $this->env->database->count(Query::from('courses')),
            'two identities are two courses, whatever the document says',
        );
    }

    public function testABadLessonIsSkippedAndTheRestOfTheRunSurvives(): void
    {
        $document = $this->document();
        $document['modules'][0]['lessons'][] = [
            'key' => 'broken',
            'title' => 'Broken video',
            'type' => 'video',
            'content' => ['provider' => 'youtube'],
        ];
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(3, $job->totals[ImportResult::TOTAL_LESSONS], 'the three good lessons still landed');
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
        self::assertStringContainsString('Broken video', (string) $job->log);
    }

    public function testAnUnknownLessonTypeIsSkippedRatherThanFailingTheRun(): void
    {
        $document = $this->document();
        $document['modules'][1]['lessons'][0]['type'] = 'scorm';
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_LESSONS]);
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
    }

    public function testAnOverLongTitleIsShortenedRatherThanRefused(): void
    {
        $document = $this->document();
        $document['modules'][0]['lessons'][0]['title'] = str_repeat('a', 400);
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(
            255,
            \strlen((string) $this->lessons((int) $this->course()['id'])[0]['title']),
        );
    }

    public function testALessonDurationPastWhatTheColumnHoldsIsRefusedHereAndNotByTheDatabase(): void
    {
        $document = $this->document();
        $document['modules'][0]['lessons'][0]['duration_seconds'] = Lesson::MAX_DURATION_SECONDS + 1;
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_LESSONS], 'the rest of the course still lands');
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
        self::assertStringContainsString(
            (string) Lesson::MAX_DURATION_SECONDS,
            (string) $job->log,
            'the note names the value and the range it is outside',
        );
        self::assertNull(
            $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Read the leaves')),
            'left to the column this is a refusal on a strict MySQL and a silent 4294967295 without it',
        );
    }

    public function testANegativeDurationIsRefusedTheSameWay(): void
    {
        $document = $this->document();
        $document['modules'][0]['lessons'][0]['duration_seconds'] = -1;
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_LESSONS]);
        self::assertNull(
            $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Read the leaves')),
        );
    }

    public function testAPreviewSkipsTheLessonTheRealRunSkips(): void
    {
        $document = $this->document();
        $document['modules'][0]['lessons'][0]['duration_seconds'] = Lesson::MAX_DURATION_SECONDS + 1;
        $this->store($document);

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(
            2,
            $job->totals[ImportResult::TOTAL_LESSONS],
            'a preview an operator reads must reach the conclusions the run will',
        );
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
    }

    public function testAKeyedLessonThatMovedBetweenModulesMovesRatherThanDuplicating(): void
    {
        $this->store($this->document());
        $this->runImport();

        $document = $this->document();
        $moved = $document['modules'][0]['lessons'][0];
        $document['modules'][0]['lessons'] = [$document['modules'][0]['lessons'][1]];
        $document['modules'][1]['lessons'][] = $moved;
        $this->store($document);

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(
            3,
            $this->env->database->count(Query::from('lessons')),
            'a lesson carrying a key is the same lesson wherever the document keeps it',
        );

        $lesson = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', 'Read the leaves'));
        self::assertNotNull($lesson);
        self::assertSame(
            (int) $this->moduleTitled('Water')['id'],
            (int) $lesson['module_id'],
            'left where it was, the re-import would build a second copy under the new module',
        );
        self::assertStringContainsString('moved to the module', (string) $second->log);
    }

    public function testTwoLessonsSharingOneKeyStayTwoLessons(): void
    {
        $document = $this->document();
        $document['modules'][1]['lessons'][0]['key'] = 'l1';
        $this->store($document);

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertSame(
            3,
            $this->env->database->count(Query::from('lessons')),
            'two lessons of one document cannot both be the same lesson',
        );
        self::assertStringContainsString(
            'repeats the key',
            (string) $job->log,
            'the note says which key needs fixing',
        );
    }

    public function testAReorderedButUnchangedKeylessDocumentIsStillTheSameCourse(): void
    {
        $keyless = $this->document();
        unset($keyless['key']);

        $this->store($keyless);
        $this->runImport();

        $this->env->storage->putPrivate(
            'imports/9/tea-reordered.json',
            (string) json_encode(array_reverse($keyless, true), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $second = $this->kernel->runImportJob->execute(
            $this->admin,
            JsonCourseImporter::KEY,
            'imports/9/tea-reordered.json',
            [],
        );

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('courses')),
            'the digest sorts object keys, so field order and whitespace do not make a second course',
        );
    }

    public function testADocumentThatIsNotJsonFailsTheJobWithTheReason(): void
    {
        $this->env->storage->putPrivate(self::SOURCE, '{ this is not json');

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('not valid JSON', (string) $job->log);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testAGuestMayNotImport(): void
    {
        $this->store($this->document());

        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);

        $this->kernel->runImportJob->execute(Principal::guest(), JsonCourseImporter::KEY, self::SOURCE, []);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute(
            $this->admin,
            JsonCourseImporter::KEY,
            self::SOURCE,
            $options,
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    private function store(array $document): void
    {
        $this->env->storage->putPrivate(self::SOURCE, (string) json_encode($document, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function course(): array
    {
        $row = $this->env->database->selectOne(Query::from('courses')->orderBy('id'));
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function modules(int $courseId): array
    {
        return $this->env->database->selectAll(
            Query::from('modules')->where('course_id', '=', $courseId)->orderBy('position'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function moduleTitled(string $title): array
    {
        $row = $this->env->database->selectOne(Query::from('modules')->where('title', '=', $title));
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
     * @return array<string, mixed>
     */
    private function quiz(): array
    {
        $row = $this->env->database->selectOne(Query::from('quizzes')->orderBy('id'));
        self::assertNotNull($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questions(int $quizId): array
    {
        return $this->env->database->selectAll(
            Query::from('questions')->where('quiz_id', '=', $quizId)->orderBy('position'),
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
        $definition = json_decode($question['definition'], true, 32, JSON_THROW_ON_ERROR);

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
        $content = json_decode($row['content'], true, 32, JSON_THROW_ON_ERROR);

        return StoredShape::bodyOf($content);
    }

    /**
     * A document exercising both ways a lesson carries content, a quiz bound
     * to its lesson by key, and two of the free question types.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'key' => 'intro-to-tea',
            'title' => 'Foundations of Tea',
            'summary' => 'Leaves, water, time.',
            'description' => '<p>A short course.</p>',
            'level' => 'beginner',
            'language' => 'en-GB',
            'modules' => [
                [
                    'key' => 'm1',
                    'title' => 'Leaves',
                    'summary' => 'What the plant gives you.',
                    'lessons' => [
                        [
                            'key' => 'l1',
                            'title' => 'Read the leaves',
                            'type' => 'reading',
                            'content' => ['body' => '<p>Steep for four minutes.</p>'],
                            'duration_seconds' => 240,
                            'free_preview' => true,
                        ],
                        [
                            'key' => 'l2',
                            'title' => 'Prove it',
                            'type' => 'quiz',
                            'content' => ['quiz' => 'q1'],
                        ],
                    ],
                ],
                [
                    'key' => 'm2',
                    'title' => 'Water',
                    'lessons' => [
                        [
                            'key' => 'l3',
                            'title' => 'Boil it',
                            'type' => 'video',
                            'content' => ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
                        ],
                    ],
                ],
            ],
            'quizzes' => [
                [
                    'key' => 'q1',
                    'title' => 'Final Quiz',
                    'pass_mark' => 60,
                    'is_final' => true,
                    'max_attempts' => 3,
                    'time_limit_seconds' => 600,
                    'show_results' => 'after_submit',
                    'questions' => [
                        [
                            'key' => 'q1a',
                            'type' => 'single_choice',
                            'points' => 5,
                            'definition' => [
                                'prompt' => 'Which leaf is oolong?',
                                'options' => [
                                    ['key' => 'a', 'label' => 'The green one'],
                                    ['key' => 'b', 'label' => 'The half oxidised one'],
                                ],
                                'correct' => 'b',
                            ],
                        ],
                        [
                            'key' => 'q1b',
                            'type' => 'true_false',
                            'points' => 1,
                            'definition' => [
                                'prompt' => 'Boiling water suits green tea.',
                                'correct' => false,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
