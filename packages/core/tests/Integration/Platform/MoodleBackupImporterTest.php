<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\MoodleBackupImporter;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\MoodleBackupBuilder;
use Shondalai\VidyaLMS\Core\Tests\Support\TarWriter;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * A Moodle backup end to end: the archive in private storage becomes a real
 * course whose lessons render and whose quiz grades, all of it written
 * through the same use cases the authoring UI calls.
 *
 * The fixtures are built in code against the documented Moodle 4.x backup
 * layout, so they prove the importer's reading of that layout, not that any
 * particular Moodle installation writes exactly this.
 */
final class MoodleBackupImporterTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const SOURCE = 'imports/9/backup.mbz';

    private const HANDBOOK_HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678';
    private const MISSING_HASH = 'ff00112233445566778899aabbccddeeff001122';

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $admin;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $this->env->users->add(self::ADMIN_ID, 'Ada Admin');
        $this->kernel = new CoreKernel($this->env->ports);
        $this->admin = Principal::user(self::ADMIN_ID);
    }

    public function testTheImporterIsRegisteredAndClaimsOnlyArchivePaths(): void
    {
        self::assertContains(MoodleBackupImporter::KEY, $this->kernel->registries->importers->keys());

        $importer = $this->kernel->registries->importers->get(MoodleBackupImporter::KEY);

        self::assertTrue($importer->supports('imports/9/backup.mbz'));
        self::assertFalse($importer->supports('imports/9/course.json'));
        self::assertFalse($importer->supports('cms-db:'));
    }

    public function testAMinimalBackupBecomesADraftCourseWithOneModuleAndOneLesson(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101', '<p>Leaf, water, time.</p>')
                ->section(1, 0, 'Leaves')
                ->page(410, 1, 'Read the leaves', '<p>Steep for four minutes.</p>')
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_COURSES]);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_MODULES]);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_LESSONS]);

        $course = $this->course();
        self::assertSame('Foundations of Tea', $course['title']);
        self::assertSame('tea-101', $course['slug'], 'the shortname is the slug');
        self::assertSame(CourseState::Draft->value, $course['state']);

        $lessons = $this->lessons((int) $course['id']);
        self::assertCount(1, $lessons);
        self::assertSame('Read the leaves', $lessons[0]['title']);
        self::assertSame('reading', $lessons[0]['type']);
        self::assertSame(
            ['body' => '<p>Steep for four minutes.</p>'],
            $this->lessonContent((int) $lessons[0]['id']),
        );
    }

    public function testARichBackupBecomesTheWholeCourseAndNamesEverythingItCouldNotBring(): void
    {
        $this->store($this->richBackup());

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertNotNull($job->totals);
        self::assertSame(2, $job->totals[ImportResult::TOTAL_MODULES]);
        self::assertSame(1, $job->totals[ImportResult::TOTAL_QUIZZES]);

        $courseId = (int) $this->course()['id'];
        $lessons = $this->lessons($courseId);
        $byTitle = [];

        foreach ($lessons as $lesson) {
            $byTitle[(string) $lesson['title']] = $lesson;
        }

        self::assertArrayHasKey('Read the leaves', $byTitle);
        self::assertSame('reading', $byTitle['Read the leaves']['type']);

        self::assertArrayHasKey('Watch the pour', $byTitle);
        self::assertSame('video', $byTitle['Watch the pour']['type'], 'a url activity is a video lesson');

        self::assertArrayHasKey('The handbook', $byTitle);
        self::assertSame('resource', $byTitle['The handbook']['type']);

        self::assertArrayHasKey('Tasting notes', $byTitle);
        self::assertSame('assignment', $byTitle['Tasting notes']['type']);

        self::assertArrayHasKey('Final Quiz', $byTitle);
        self::assertSame('quiz', $byTitle['Final Quiz']['type']);

        // The forum has no equivalent, so it is named and skipped rather than
        // failing the run.
        self::assertArrayNotHasKey('Common room', $byTitle);
        self::assertStringContainsString('Common room', (string) $job->log);
        self::assertGreaterThan(0, $job->totals[ImportResult::TOTAL_SKIPPED]);
    }

    public function testTheQuizCarriesItsQuestionsAndTheirMarks(): void
    {
        $this->store($this->richBackup());
        $this->runImport();

        $quiz = $this->quiz();
        self::assertSame('Final Quiz', $quiz['title']);

        $questions = $this->questions((int) $quiz['id']);
        self::assertCount(3, $questions);
        self::assertSame(
            ['single_choice', 'true_false', 'short_answer'],
            array_column($questions, 'type'),
        );
        self::assertSame(2.0, (float) $questions[0]['points'], 'the slot maxmark is the question points');

        $single = $this->definitionOf($questions[0]);
        self::assertStringContainsString('oolong', (string) $single['prompt']);
        self::assertCount(2, $single['options']);

        $type = $this->kernel->registries->questionTypes->get('single_choice');
        self::assertSame(1.0, $type->grade($single, (string) $single['correct'])->fraction);
    }

    public function testAnUnmappableQuestionTypeIsNamedAndSkipped(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->matching(910, 810, 'Match the leaves', '<p>Pair them.</p>')
                ->multipleChoice(911, 811, 'Which leaf', '<p>Which leaf is oolong?</p>', [
                    ['id' => 9111, 'text' => 'The green one', 'fraction' => 0.0],
                    ['id' => 9112, 'text' => 'The half oxidised one', 'fraction' => 1.0],
                ], true)
                ->quiz(430, 1, 'Final Quiz', [
                    ['question' => 910, 'entry' => 810, 'maxmark' => 1.0],
                    ['question' => 911, 'entry' => 811, 'maxmark' => 2.0],
                ])
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertCount(
            1,
            $this->questions((int) $this->quiz()['id']),
            'a question that cannot be mapped is left out rather than guessed at',
        );
        self::assertStringContainsString('match', (string) $job->log);
    }

    public function testAResourceLessonCarriesItsAttachmentIntoStorage(): void
    {
        $this->store($this->richBackup());
        $this->runImport();

        $lesson = $this->lessonNamed('The handbook');
        $content = $this->lessonContent((int) $lesson['id']);

        self::assertArrayHasKey('files', $content);
        self::assertIsArray($content['files']);
        self::assertCount(1, $content['files']);

        $file = $content['files'][0];
        self::assertIsArray($file);
        self::assertSame('handbook.pdf', $file['name']);
        self::assertIsString($file['path']);
        self::assertTrue(
            $this->env->storage->exists($file['path']),
            'the bytes are written through the storage port, never extracted to a path',
        );
    }

    public function testAnAttachmentTheBackupExcludedIsNamedRatherThanInvented(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->resource(420, 1, 'The handbook')
                ->file(
                    MoodleBackupBuilder::contextOf(420),
                    'mod_resource',
                    'content',
                    self::MISSING_HASH,
                    'handbook.pdf',
                    null,
                )
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertStringContainsString('handbook.pdf', (string) $job->log);
    }

    public function testUnsafeArchiveNamesAndExecutableBytesNeverBecomeStoredAttachments(): void
    {
        $builder = (new MoodleBackupBuilder())->course('Files', 'FILES')->section(1, 0, 'Files')->folder(442, 1, 'Attachments');
        foreach (
            [
            ['shell.php', '<?php echo 1;'], ['web.config', '<configuration/>'],
            ['.htaccess', 'AddHandler application/x-httpd-php .txt'],
            ['looks.pdf', '<?php echo 2;'], ['page.html', '<html><script>alert(1)</script></html>'],
            ['../../shell.php.pdf', '%PDF-1.4 safe document'],
            ['C:\\temp\\report.pdf', '%PDF-1.4 safe document'],
            ] as $index => [$name, $bytes]
        ) {
            $builder->file(MoodleBackupBuilder::contextOf(442), 'mod_folder', 'content', sha1((string) $index), $name, $bytes);
        }
        $this->store($builder->build());
        $job = $this->runImport();
        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $content = $this->lessonContent((int) $this->lessonNamed('Attachments')['id']);
        self::assertCount(2, $content['files']);
        foreach ($content['files'] as $file) {
            self::assertMatchesRegularExpression('#^imports/moodle/[a-f0-9]{64}/attachment\.pdf$#D', $file['path']);
            self::assertStringNotContainsString('/', $file['name']);
            self::assertStringNotContainsString('\\', $file['name']);
            self::assertSame('%PDF-1.4 safe document', $this->env->storage->readPrivate($file['path']));
        }
        self::assertStringContainsString('unsupported attachment', (string) $job->log);
        self::assertCount(2, $this->env->storage->privateFiles, 'Only the source and one content-addressed attachment are stored.');
    }

    public function testADryRunWritesNothingAtAll(): void
    {
        $this->store($this->richBackup());

        $job = $this->runImport(['dry_run' => true]);

        self::assertSame(ImportJob::STATUS_PREVIEWED, $job->status);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
        self::assertSame(0, $this->env->database->count(Query::from('quizzes')));
        self::assertSame([], $this->env->storage->privateFiles === [] ? [] : array_diff(
            array_keys($this->env->storage->privateFiles),
            [self::SOURCE],
        ), 'a preview writes no attachments either');
    }

    public function testASecondRunUpdatesWhatTheFirstCreated(): void
    {
        $this->store($this->richBackup());
        $this->runImport();

        $courseId = (int) $this->course()['id'];
        $lessonsBefore = \count($this->lessons($courseId));

        $second = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $second->status);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));
        self::assertSame($lessonsBefore, \count($this->lessons($courseId)));
    }

    public function testTheSlugSurvivesARerunEvenWhenTheShortnameChanged(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->page(410, 1, 'Read the leaves', '<p>Steep.</p>')
                ->build(),
        );
        $this->runImport();

        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 102')
                ->section(1, 0, 'Leaves')
                ->page(410, 1, 'Read the leaves', '<p>Steep.</p>')
                ->build(),
        );
        $this->runImport();

        self::assertSame(
            'tea-101',
            $this->course()['slug'],
            'a slug that moves breaks every link anyone made to the course',
        );
    }

    public function testTheLegacyQuestionBankLayoutIsReadToo(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->legacyQuestionBank()
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->trueFalse(920, 820, 'Boiling', '<p>Boiling water suits green tea.</p>', false)
                ->quiz(430, 1, 'Final Quiz', [['question' => 920, 'entry' => 820, 'maxmark' => 1.0]])
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertCount(1, $this->questions((int) $this->quiz()['id']));
    }

    public function testAnArchiveThatIsNotAnArchiveFailsTheJobWithTheReason(): void
    {
        $this->env->storage->putPrivate(self::SOURCE, 'not a tar at all');

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertNotSame('', (string) $job->log);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testABackupWithNoCourseXmlFallsBackToTheManifest(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->withoutCourseXml()
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->page(410, 1, 'Read the leaves', '<p>Steep.</p>')
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(
            ImportJob::STATUS_COMPLETED,
            $job->status,
            'moodle_backup.xml names the course too, and a course nine tenths importable arrives',
        );
        self::assertSame('Foundations of Tea', $this->course()['title']);
        self::assertCount(1, $this->lessons((int) $this->course()['id']));
    }

    public function testABookALabelAndAFolderEachBecomeALesson(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->book(440, 1, 'The leaf book', [
                    ['title' => 'Second', 'content' => '<p>Then oxidise.</p>', 'pagenum' => 2],
                    ['title' => 'First', 'content' => '<p>Pick it.</p>', 'pagenum' => 1],
                ])
                ->label(441, 1, 'A note in passing', '<p>Mind the water.</p>')
                ->folder(442, 1, 'Everything else')
                ->file(
                    MoodleBackupBuilder::contextOf(442),
                    'mod_folder',
                    'content',
                    self::HANDBOOK_HASH,
                    'notes.txt',
                    'Steep for four minutes.',
                )
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);

        $book = $this->lessonContent((int) $this->lessonNamed('The leaf book')['id']);
        self::assertIsString($book['body']);
        self::assertStringContainsString('Pick it.', $book['body']);
        self::assertLessThan(
            strpos($book['body'], 'Then oxidise.'),
            strpos($book['body'], 'Pick it.'),
            'a book is one reading lesson whose chapters keep their page order',
        );

        self::assertSame('reading', $this->lessonNamed('A note in passing')['type']);

        $folder = $this->lessonNamed('Everything else');
        self::assertSame('resource', $folder['type']);
        $content = $this->lessonContent((int) $folder['id']);
        self::assertIsArray($content['files']);
        self::assertCount(1, $content['files']);
    }

    public function testAnAttachmentTooLargeForTheLimitIsLeftBehindBeforeItsBytesAreRead(): void
    {
        $this->store(
            (new MoodleBackupBuilder())
                ->course('Foundations of Tea', 'TEA 101')
                ->section(1, 0, 'Leaves')
                ->resource(420, 1, 'The handbook')
                ->file(
                    MoodleBackupBuilder::contextOf(420),
                    'mod_resource',
                    'content',
                    self::HANDBOOK_HASH,
                    'handbook.pdf',
                    null,
                    40000000,
                )
                ->build(),
        );

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        self::assertStringContainsString('handbook.pdf', (string) $job->log);
        self::assertStringContainsString(
            'byte limit',
            (string) $job->log,
            'the declared size refuses the file before a single byte of it is held',
        );
    }

    public function testAnEntryCarryingADoctypeIsRefusedRatherThanParsed(): void
    {
        $builder = (new MoodleBackupBuilder())
            ->course('Foundations of Tea', 'TEA 101')
            ->section(1, 0, 'Leaves')
            ->page(410, 1, 'Read the leaves', '<p>Steep.</p>');

        $entries = $builder->entries();
        $manifest = $entries['moodle_backup.xml'];
        $declaration = strpos($manifest, '?>');
        self::assertIsInt($declaration, 'the fixture is only a test of the fix if it is otherwise a real manifest');

        $entries['moodle_backup.xml'] = substr($manifest, 0, $declaration + 2)
            . '<!DOCTYPE moodle_backup [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . substr($manifest, $declaration + 2);

        $this->store(TarWriter::gzip($entries));

        $job = $this->runImport();

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('doctype', (string) $job->log);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testTheByteCeilingIsLoweredByTheCallerButNeverRaised(): void
    {
        $this->store($this->richBackup());

        $job = $this->runImport(['max_bytes' => 512]);

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertStringContainsString('512 byte limit', (string) $job->log);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testTheEntryCeilingIsLoweredByTheCallerButNeverRaised(): void
    {
        $this->store($this->richBackup());

        $job = $this->runImport(['max_entries' => 2]);

        self::assertSame(ImportJob::STATUS_FAILED, $job->status, 'a ceiling the caller set is enforced');
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testAGuestMayNotImport(): void
    {
        $this->store($this->richBackup());

        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException::class);

        $this->kernel->runImportJob->execute(Principal::guest(), MoodleBackupImporter::KEY, self::SOURCE, []);
    }

    /**
     * A course that uses most of what the importer maps, plus one module it
     * refuses.
     */
    private function richBackup(): string
    {
        return (new MoodleBackupBuilder())
            ->course('Foundations of Tea', 'TEA 101', '<p>Leaf, water, time.</p>')
            ->section(1, 0, 'Leaves')
            ->section(2, 1, 'Water')
            ->page(410, 1, 'Read the leaves', '<p>Steep for four minutes.</p>')
            ->url(415, 1, 'Watch the pour', 'https://www.youtube.com/watch?v=abc')
            ->resource(420, 2, 'The handbook')
            ->file(
                MoodleBackupBuilder::contextOf(420),
                'mod_resource',
                'content',
                self::HANDBOOK_HASH,
                'handbook.pdf',
                '%PDF-1.4 handbook',
            )
            ->assign(425, 2, 'Tasting notes', '<p>Write up three teas.</p>')
            ->forum(428, 2, 'Common room')
            ->multipleChoice(911, 811, 'Which leaf', '<p>Which leaf is oolong?</p>', [
                ['id' => 9111, 'text' => 'The green one', 'fraction' => 0.0],
                ['id' => 9112, 'text' => 'The half oxidised one', 'fraction' => 1.0],
            ], true)
            ->trueFalse(912, 812, 'Boiling', '<p>Boiling water suits green tea.</p>', false)
            ->shortAnswer(913, 813, 'Name one', '<p>Name an oolong.</p>', ['Tieguanyin'])
            ->quiz(430, 2, 'Final Quiz', [
                ['question' => 911, 'entry' => 811, 'maxmark' => 2.0],
                ['question' => 912, 'entry' => 812, 'maxmark' => 1.0],
                ['question' => 913, 'entry' => 813, 'maxmark' => 1.0],
            ], timeLimit: 600, attempts: 3)
            ->build();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runImport(array $options = []): ImportJob
    {
        return $this->kernel->runImportJob->execute(
            $this->admin,
            MoodleBackupImporter::KEY,
            self::SOURCE,
            $options,
        );
    }

    private function store(string $archive): void
    {
        $this->env->storage->putPrivate(self::SOURCE, $archive);
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
    private function lessonNamed(string $title): array
    {
        $row = $this->env->database->selectOne(Query::from('lessons')->where('title', '=', $title));
        self::assertNotNull($row);

        return $row;
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
}
