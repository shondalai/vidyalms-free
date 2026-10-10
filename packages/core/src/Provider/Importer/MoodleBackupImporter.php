<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Upload\UploadIntake;
use Shondalai\VidyaLMS\Core\Upload\UploadPolicy;
use Shondalai\VidyaLMS\Core\Provider\Importer\Moodle\MoodleActivity;
use Shondalai\VidyaLMS\Core\Provider\Importer\Moodle\MoodleBackup;
use Shondalai\VidyaLMS\Core\Provider\Importer\Moodle\MoodleQuestionMapper;
use Shondalai\VidyaLMS\Core\Provider\Importer\Moodle\MoodleSection;
use Shondalai\VidyaLMS\Core\Infrastructure\Archive\TarArchive;
use Shondalai\VidyaLMS\Core\Infrastructure\Xml\XmlNode;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;

/**
 * Imports a Moodle course backup (.mbz) held in private storage.
 *
 * This is the migration path that matters most: a university or a Joomdle
 * site has years of courses in Moodle, and the only question that decides
 * whether they move is whether their courses come with them.
 *
 * ## What the archive becomes
 *
 * | Moodle                     | VidyaLMS                            |
 * |----------------------------|-------------------------------------|
 * | course fullname, shortname | a draft course, shortname as slug   |
 * | section                    | a module, in the course's own order  |
 * | page, book, label          | a reading lesson                     |
 * | url                        | a video lesson, or a reading         |
 * | resource, folder           | a resource lesson with its files     |
 * | assign                     | an assignment lesson                 |
 * | quiz                       | a quiz lesson plus the quiz          |
 *
 * Question types map through MoodleQuestionMapper, which documents its own
 * table. Anything with no equivalent (forum, scorm, glossary, choice, wiki,
 * lti, workshop, feedback, chat, h5pactivity, lesson) is skipped with a
 * warning naming the module, never a failure: a course that is nine tenths
 * importable should arrive nine tenths imported, with the tenth listed.
 *
 * ## What it does not do
 *
 * No user, enrolment, grade, attempt or submission is read. A migration
 * brings the course; who took it is a separate decision, and importing
 * learner data behind an admin's back is not one this importer makes.
 *
 * Files embedded inside HTML keep Moodle's "@@PLUGINFILE@@" placeholder,
 * which resolves to nothing here: an image dropped into a page body or a
 * question prompt arrives broken and needs re-uploading. Only the files a
 * resource or folder activity attached are carried over, because those are
 * the ones the file table names without parsing the HTML.
 *
 * Courses land as drafts, because a migrated course is reviewed before
 * learners see it.
 *
 * ## Safety
 *
 * The archive is read as bytes, never extracted to a path: entry names from
 * an untrusted upload never reach the filesystem. TarArchive refuses
 * absolute or traversing names and caps both the decompressed size and the
 * entry count. The only files written are the attachments of resource and
 * folder lessons, under a content addressed path, and those are deleted
 * again when the lesson they belong to cannot be created.
 *
 * ## Re-running
 *
 * Every object is remembered against the Moodle identity that survives a
 * re-export: the site identifier and course id for the course, section ids
 * for modules, course module ids for lessons and quizzes, question or bank
 * entry ids for questions. Importing the same backup twice updates what the
 * first run created.
 *
 * The course slug is derived from the Moodle shortname on the first run only.
 * A re-import never rewrites it, because a slug that moves breaks every link
 * anyone made to the course in between.
 */
final class MoodleBackupImporter extends AbstractImporter implements ConfigurableImporterInterface
{
    public const KEY = 'moodle-backup';

    private const TYPE_COURSE = 'course';
    private const TYPE_MODULE = 'module';
    private const TYPE_LESSON = 'lesson';
    private const TYPE_QUIZ = 'quiz';
    private const TYPE_QUESTION = 'question';

    private const LESSON_READING = 'reading';
    private const LESSON_VIDEO = 'video';
    private const LESSON_RESOURCE = 'resource';
    private const LESSON_ASSIGNMENT = 'assignment';
    private const LESSON_QUIZ = 'quiz';

    /** Moodle modules with a free equivalent here. */
    private const SUPPORTED = ['page', 'book', 'label', 'url', 'resource', 'folder', 'assign', 'quiz'];

    /** An attachment larger than this is left behind with a note. */
    private const MAX_FILE_BYTES = 33554432;

    /** Ceilings the caller's options may lower but not raise. */
    private const CEILING_BYTES = 536870912;
    private const CEILING_ENTRIES = 50000;

    private const FILE_PREFIX = 'imports/moodle/';

    private readonly MoodleQuestionMapper $questions;

    public function __construct(
        private readonly StoragePort $storage,
        private readonly ImportMap $map,
        private readonly TranslatorPort $translator,
        private readonly CreateCourse $createCourse,
        private readonly UpdateCourse $updateCourse,
        private readonly AddModule $addModule,
        private readonly UpdateModule $updateModule,
        private readonly AddLesson $addLesson,
        private readonly UpdateLesson $updateLesson,
        private readonly CreateQuiz $createQuiz,
        private readonly UpdateQuiz $updateQuiz,
        private readonly AddQuestion $addQuestion,
        private readonly UpdateQuestion $updateQuestion,
    ) {
        $this->questions = new MoodleQuestionMapper();
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * The two ceilings the operator may lower.
     *
     * They are declared rather than hidden because an archive refused for
     * being too large tells the operator to raise memory_limit or split the
     * backup, and the honest third answer is to lower the cap for a run: a
     * number reachable only from an API client is a number nobody uses.
     * Raising them past CEILING_BYTES and CEILING_ENTRIES does nothing, which
     * is what limit() enforces and what the help text says.
     *
     * @return list<ImportOption>
     */
    public function importOptions(): array
    {
        return [
            new ImportOption(
                'max_bytes',
                $this->translator->translate('vidyalms.importer.option.max_bytes'),
                ImportOption::KIND_NUMBER,
                $this->translator->translate('vidyalms.importer.option.max_bytes.help'),
            ),
            new ImportOption(
                'max_entries',
                $this->translator->translate('vidyalms.importer.option.max_entries'),
                ImportOption::KIND_NUMBER,
                $this->translator->translate('vidyalms.importer.option.max_entries.help'),
            ),
        ];
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.moodle_backup');
    }

    public function supports(string $sourcePath): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $sourcePath) === 1) {
            return false;
        }

        return str_ends_with(strtolower(trim($sourcePath)), '.mbz');
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        if (!$this->supports($sourcePath)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('"%s" is not a Moodle backup file.', $sourcePath));
        }

        $backup = MoodleBackup::open(TarArchive::open(
            $this->storage->readPrivate($sourcePath),
            $this->limit($options, 'max_bytes', TarArchive::DEFAULT_MAX_BYTES, self::CEILING_BYTES),
            $this->limit($options, 'max_entries', TarArchive::DEFAULT_MAX_ENTRIES, self::CEILING_ENTRIES),
        ));

        $scope = $backup->scope($sourcePath);
        $courseId = $this->course($backup, $scope, $principal, $progress);

        foreach ($backup->sections() as $section) {
            try {
                $this->section($backup, $section, $scope, $courseId, $principal, $progress);
            } catch (\Throwable $failure) {
                // A backup is foreign data and a broken one is not a reason
                // to lose the sections that read cleanly. Everything inside
                // has its own boundary, so arriving here is the archive
                // itself refusing to be walked.
                $progress->note(ImportNote::ERROR, sprintf(
                    'Section %d of this backup could not be imported: %s',
                    $section->number,
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
            }
        }
    }

    private function course(MoodleBackup $backup, string $scope, Principal $principal, ImportProgress $progress): int
    {
        $title = $backup->courseTitle();

        if ($title === null) {
            throw new \RuntimeException('The backup names no course, so there is nothing to import.');
        }

        $title = $this->capped($title, 255, 'The course title', $progress);
        $existing = $this->map->lookup(self::KEY, self::TYPE_COURSE, $scope);
        $description = $backup->courseSummary();
        $language = $backup->courseLanguage();
        $language = $language !== null && \strlen($language) <= 20 ? $language : null;

        if ($progress->isDryRun()) {
            $progress->counted(ImportResult::TOTAL_COURSES);
            $progress->note(ImportNote::INFO, sprintf(
                'Course "%s" would be %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));

            return 0;
        }

        if ($existing !== null) {
            $this->updateCourse->execute($principal, $existing, [
                'title' => $title,
                'summary' => $this->excerpt($description),
                'description' => $description,
                'language' => $language,
            ]);
            $courseId = $existing;
        } else {
            $courseId = $this->createCourse->execute(
                $principal,
                $title,
                slug: $this->slug($backup->courseShortName()),
                summary: $this->excerpt($description),
                description: $description,
                language: $language,
            )->id;
        }

        $this->remember(self::TYPE_COURSE, $scope, self::TYPE_COURSE, $courseId, $progress);
        $progress->counted(ImportResult::TOTAL_COURSES);
        $progress->note(ImportNote::INFO, sprintf(
            'Course "%s" %s.',
            $title,
            $existing === null ? 'created' : 'updated',
        ));

        return $courseId;
    }

    private function section(
        MoodleBackup $backup,
        MoodleSection $section,
        string $scope,
        int $courseId,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $title = $this->capped($this->sectionTitle($backup, $section), 255, 'A section title', $progress);
        $activities = $backup->activitiesOf($section);

        if ($activities === []) {
            $progress->note(ImportNote::INFO, sprintf('Section "%s" holds no activities and was skipped.', $title));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        $sourceKey = $scope . '/section/' . $section->id;
        $existing = $this->map->lookup(self::KEY, self::TYPE_MODULE, $sourceKey);
        $moduleId = 0;

        if (!$progress->isDryRun()) {
            try {
                if ($existing !== null) {
                    $this->updateModule->execute($principal, $existing, [
                        'title' => $title,
                        'summary' => $section->summary,
                    ]);
                    $moduleId = $existing;
                } else {
                    $moduleId = $this->addModule->execute($principal, $courseId, $title, $section->summary)->id;
                }
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf('Module "%s" skipped: %s', $title, ImportProblem::describe($failure)));
                $progress->counted(ImportResult::TOTAL_SKIPPED);

                return;
            }

            $this->remember(self::TYPE_MODULE, $sourceKey, self::TYPE_MODULE, $moduleId, $progress);
        }

        $progress->counted(ImportResult::TOTAL_MODULES);
        $progress->note(ImportNote::INFO, sprintf(
            $progress->isDryRun() ? 'Module "%s" would be %s.' : 'Module "%s" %s.',
            $title,
            $existing === null ? 'created' : 'updated',
        ));

        foreach ($activities as $activity) {
            try {
                $this->activity($backup, $activity, $scope, $courseId, $moduleId, $principal, $progress);
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Activity "%s" was not imported: %s',
                    ImportNote::excerpt($activity->title ?? $activity->modname),
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
            }
        }
    }

    private function activity(
        MoodleBackup $backup,
        MoodleActivity $activity,
        string $scope,
        int $courseId,
        int $moduleId,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $label = ImportNote::excerpt($activity->title ?? $activity->modname);

        if (!\in_array($activity->modname, self::SUPPORTED, true)) {
            $progress->note(ImportNote::WARNING, sprintf(
                'Activity "%s" is a Moodle %s, which has no VidyaLMS equivalent, skipped.',
                $label,
                $activity->modname,
            ));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        try {
            $node = $backup->activityXml($activity);
        } catch (\Throwable $failure) {
            $progress->note(ImportNote::WARNING, sprintf('Activity "%s" skipped: %s', $label, ImportProblem::describe($failure)));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        if ($node === null) {
            $progress->note(ImportNote::WARNING, sprintf(
                'Activity "%s" is missing "%s" in the archive, skipped.',
                $label,
                $activity->xmlPath(),
            ));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        $instance = $node->child($activity->modname);

        if ($instance === null) {
            $progress->note(ImportNote::WARNING, sprintf(
                'Activity "%s" is missing its %s record in the archive, skipped.',
                $label,
                $activity->modname,
            ));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        $title = $this->capped(
            $instance->text('name') ?? $activity->title ?? $activity->modname,
            255,
            sprintf('The title of activity "%s"', $label),
            $progress,
        );

        if ($activity->modname === self::LESSON_QUIZ) {
            $this->quiz($backup, $activity, $node, $instance, $title, $scope, $courseId, $moduleId, $principal, $progress);

            return;
        }

        $plan = $this->lessonPlan($backup, $activity, $node, $instance, $title, $progress);

        if ($plan === null) {
            return;
        }

        $this->lesson(
            $scope . '/cm/' . $activity->id,
            $moduleId,
            $title,
            $plan['type'],
            $plan['content'],
            $plan['staged'],
            $principal,
            $progress,
        );
    }

    /**
     * The lesson type and content one Moodle activity becomes, or null when
     * it carried nothing importable (the skip is already noted and counted).
     *
     * @return array{type: string, content: array<string, mixed>, staged: list<string>}|null
     */
    private function lessonPlan(
        MoodleBackup $backup,
        MoodleActivity $activity,
        XmlNode $node,
        XmlNode $instance,
        string $title,
        ImportProgress $progress,
    ): ?array {
        $contextId = $node->attributeInt('contextid');

        return match ($activity->modname) {
            'page' => $this->readingPlan($instance->text('content') ?? $instance->text('intro'), $title, $progress),
            'label' => $this->readingPlan($instance->text('intro'), $title, $progress),
            'book' => $this->readingPlan($this->bookBody($instance) ?? $instance->text('intro'), $title, $progress),
            'url' => $this->urlPlan($instance, $title, $progress),
            'resource' => $this->filesPlan($backup, $contextId, 'mod_resource', $title, $progress),
            'folder' => $this->filesPlan($backup, $contextId, 'mod_folder', $title, $progress),
            'assign' => [
                'type' => self::LESSON_ASSIGNMENT,
                'content' => ['instructions' => $instance->text('intro')],
                'staged' => [],
            ],
            default => null,
        };
    }

    /**
     * @return array{type: string, content: array<string, mixed>, staged: list<string>}|null
     */
    private function readingPlan(?string $body, string $title, ImportProgress $progress): ?array
    {
        if ($body === null) {
            $progress->note(ImportNote::WARNING, sprintf('Activity "%s" has no content, skipped.', $title));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return null;
        }

        return ['type' => self::LESSON_READING, 'content' => ['body' => $body], 'staged' => []];
    }

    /**
     * A Moodle url pointing at a video host becomes a video lesson; anything
     * else becomes a reading holding the link, which is what the learner had
     * in Moodle anyway.
     *
     * @return array{type: string, content: array<string, mixed>, staged: list<string>}|null
     */
    private function urlPlan(XmlNode $instance, string $title, ImportProgress $progress): ?array
    {
        $url = $instance->text('externalurl');

        if ($url === null) {
            $progress->note(ImportNote::WARNING, sprintf('Activity "%s" carries no URL, skipped.', $title));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return null;
        }

        $provider = $this->videoProvider($url);

        if ($provider !== null) {
            return [
                'type' => self::LESSON_VIDEO,
                'content' => ['provider' => $provider, 'url' => $url],
                'staged' => [],
            ];
        }

        $body = sprintf(
            '%s<p><a href="%s" rel="noopener noreferrer" target="_blank">%s</a></p>',
            $instance->text('intro') ?? '',
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );

        return ['type' => self::LESSON_READING, 'content' => ['body' => $body], 'staged' => []];
    }

    private function videoProvider(string $url): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (preg_match('/(^|\.)(youtube\.com|youtu\.be|youtube-nocookie\.com)$/', $host) === 1) {
            return 'youtube';
        }

        if (preg_match('/(^|\.)vimeo\.com$/', $host) === 1) {
            return 'vimeo';
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(mp4|m4v|webm|ogv|mov)$/', $path) === 1 ? 'url' : null;
    }

    /**
     * @return array{type: string, content: array<string, mixed>, staged: list<string>}|null
     */
    private function filesPlan(
        MoodleBackup $backup,
        ?int $contextId,
        string $component,
        string $title,
        ImportProgress $progress,
    ): ?array {
        $files = [];
        $staged = [];

        foreach ($backup->filesFor($contextId, $component, 'content') as $record) {
            $reason = null;
            $bytes = null;

            if ($record['size'] > self::MAX_FILE_BYTES) {
                $reason = sprintf('larger than the %d byte limit', self::MAX_FILE_BYTES);
            } else {
                $bytes = $backup->fileBytes($record['hash']);
                $reason = match (true) {
                    $bytes === null => 'not in the archive',
                    \strlen($bytes) > self::MAX_FILE_BYTES => sprintf('larger than the %d byte limit', self::MAX_FILE_BYTES),
                    default => null,
                };
            }

            if ($reason !== null || $bytes === null) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'File "%s" of activity "%s" was left behind: it is %s.',
                    ImportNote::excerpt($record['name']),
                    $title,
                    $reason ?? 'not in the archive',
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            try {
                $purpose = UploadPolicy::free()->purpose(UploadPolicy::PURPOSE_ATTEMPT_ARTIFACT);
                if ($purpose === null) {
                    throw new \LogicException('Resource upload policy is unavailable.');
                }
                $inspection = UploadIntake::inspect($purpose, $bytes, $record['name'], self::MAX_FILE_BYTES);
            } catch (\Shondalai\VidyaLMS\Core\Http\ApiProblem) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'File "%s" was left behind: unsupported attachment type, size or content.',
                    ImportNote::excerpt($record['name']),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }
            // Both identity and suffix come from validated bytes, never archive names or hashes.
            $path = self::FILE_PREFIX . hash('sha256', $bytes) . '/attachment.' . $inspection['extension'];

            if (!$progress->isDryRun()) {
                // Only a file this run created may be cleaned up again: the
                // path is content addressed, so an existing one may belong to
                // a lesson that already succeeded.
                if (!$this->storage->exists($path)) {
                    $staged[] = $path;
                }

                try {
                    $this->storage->putPrivate($path, $bytes);
                } catch (\Throwable $failure) {
                    $this->discard($staged);
                    throw $failure;
                }
            }

            $files[] = [
                'path' => $path,
                'name' => UploadIntake::displayName($record['name'], $inspection['extension']),
                'size_bytes' => \strlen($bytes),
            ];
        }

        if ($files === []) {
            $progress->note(ImportNote::WARNING, sprintf('Activity "%s" has no importable files, skipped.', $title));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return null;
        }

        return ['type' => self::LESSON_RESOURCE, 'content' => ['files' => $files], 'staged' => $staged];
    }

    /**
     * A Moodle book is one lesson, its chapters becoming headed sections of
     * the same reading. Splitting them into a lesson each would change the
     * course structure the author built.
     */
    private function bookBody(XmlNode $instance): ?string
    {
        $chapters = $instance->child('chapters')?->children('chapter') ?? [];

        usort($chapters, static fn (XmlNode $a, XmlNode $b): int => ($a->int('pagenum') ?? 0) <=> ($b->int('pagenum') ?? 0));

        $body = '';

        foreach ($chapters as $chapter) {
            if (($chapter->int('hidden') ?? 0) === 1) {
                continue;
            }

            $chapterTitle = $chapter->text('title');
            $content = $chapter->text('content');

            if ($chapterTitle !== null) {
                $body .= sprintf('<h2>%s</h2>', htmlspecialchars($chapterTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            $body .= $content ?? '';
        }

        return $body === '' ? null : $body;
    }

    /**
     * @param array<string, mixed> $content
     * @param list<string>         $staged  files already written for this lesson
     */
    private function lesson(
        string $sourceKey,
        int $moduleId,
        string $title,
        string $type,
        array $content,
        array $staged,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $existing = $this->map->lookup(self::KEY, self::TYPE_LESSON, $sourceKey);

        if ($progress->isDryRun()) {
            $progress->counted(ImportResult::TOTAL_LESSONS);

            return;
        }

        try {
            $lessonId = $existing !== null
                ? $this->updateLesson->execute($principal, $existing, [
                    'title' => $title,
                    'type' => $type,
                    'content' => $content,
                ] + ($type === 'video' ? ['state' => 'draft'] : []))->id
                : $this->addLesson->execute($principal, $moduleId, $title, $type, $content,
                    state: $type === 'video' ? 'draft' : 'published')->id;
        } catch (\Throwable $failure) {
            $this->discard($staged);
            $progress->note(ImportNote::WARNING, sprintf('Lesson "%s" skipped: %s', $title, ImportProblem::describe($failure)));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        $this->remember(self::TYPE_LESSON, $sourceKey, self::TYPE_LESSON, $lessonId, $progress);
        $progress->counted(ImportResult::TOTAL_LESSONS);
        $progress->note(ImportNote::INFO, sprintf(
            'Lesson "%s" %s.',
            $title,
            $existing === null ? 'created' : 'updated',
        ));
    }

    /**
     * A Moodle quiz is two objects here: the lesson a learner opens and the
     * quiz it runs. The quiz is created first because the lesson content
     * names its id, then pointed back at the lesson once that exists.
     */
    private function quiz(
        MoodleBackup $backup,
        MoodleActivity $activity,
        XmlNode $node,
        XmlNode $instance,
        string $title,
        string $scope,
        int $courseId,
        int $moduleId,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $quizKey = $scope . '/cm/' . $activity->id . '/quiz';
        $lessonKey = $scope . '/cm/' . $activity->id;
        $existing = $this->map->lookup(self::KEY, self::TYPE_QUIZ, $quizKey);
        $description = $instance->text('intro');
        $passMark = $backup->passMarkOf('quiz', $instance->attributeInt('id') ?? $node->attributeInt('id'));
        $timeLimit = $this->positive($instance->int('timelimit'));
        $attempts = $this->positive($instance->int('attempts_number') ?? $instance->int('attempts'));

        if ($progress->isDryRun()) {
            $progress->counted(ImportResult::TOTAL_QUIZZES);
            $progress->counted(ImportResult::TOTAL_LESSONS);
            $progress->note(ImportNote::INFO, sprintf(
                'Quiz "%s" would be %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));
            $this->quizQuestions($backup, $instance, $quizKey, 0, $title, $principal, $progress);

            return;
        }

        try {
            if ($existing !== null) {
                $this->updateQuiz->execute(
                    $principal,
                    $existing,
                    $title,
                    description: $description,
                    passMark: $passMark,
                    maxAttempts: $attempts,
                    timeLimitSeconds: $timeLimit,
                );
                $quizId = $existing;
            } else {
                $quizId = $this->createQuiz->execute(
                    $principal,
                    $courseId,
                    $title,
                    description: $description,
                    passMark: $passMark,
                    maxAttempts: $attempts,
                    timeLimitSeconds: $timeLimit,
                )->id ?? throw new \RuntimeException(sprintf('Quiz "%s" was stored without an id.', $title));
            }
        } catch (\Throwable $failure) {
            $progress->note(ImportNote::WARNING, sprintf('Quiz "%s" skipped: %s', $title, ImportProblem::describe($failure)));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return;
        }

        $this->remember(self::TYPE_QUIZ, $quizKey, self::TYPE_QUIZ, $quizId, $progress);
        $progress->counted(ImportResult::TOTAL_QUIZZES);
        $progress->note(ImportNote::INFO, sprintf(
            'Quiz "%s" %s.',
            $title,
            $existing === null ? 'created' : 'updated',
        ));

        $this->lesson($lessonKey, $moduleId, $title, self::LESSON_QUIZ, ['quiz_id' => $quizId], [], $principal, $progress);
        $lessonId = $this->map->lookup(self::KEY, self::TYPE_LESSON, $lessonKey);

        if ($lessonId !== null) {
            $this->bindQuiz($quizId, $lessonId, $title, $description, $passMark, $attempts, $timeLimit, $principal, $progress);
        }

        $this->quizQuestions($backup, $instance, $quizKey, $quizId, $title, $principal, $progress);
    }

    private function bindQuiz(
        int $quizId,
        int $lessonId,
        string $title,
        ?string $description,
        int $passMark,
        ?int $attempts,
        ?int $timeLimit,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        try {
            $this->updateQuiz->execute(
                $principal,
                $quizId,
                $title,
                lessonId: $lessonId,
                description: $description,
                passMark: $passMark,
                maxAttempts: $attempts,
                timeLimitSeconds: $timeLimit,
            );
        } catch (\Throwable $failure) {
            $progress->note(ImportNote::WARNING, sprintf(
                'Quiz "%s" could not be attached to its lesson: %s',
                $title,
                ImportProblem::describe($failure),
            ));
        }
    }

    private function quizQuestions(
        MoodleBackup $backup,
        XmlNode $quiz,
        string $quizKey,
        int $quizId,
        string $quizTitle,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $slots = $quiz->child('question_instances')?->children('question_instance') ?? [];

        usort($slots, static fn (XmlNode $a, XmlNode $b): int => ($a->int('slot') ?? 0) <=> ($b->int('slot') ?? 0));

        foreach ($slots as $position => $slot) {
            $resolved = $backup->questionOf($slot);

            if ($resolved === null) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question %d of quiz "%s" is not in the archive\'s question bank, skipped.',
                    $position + 1,
                    $quizTitle,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $question = $resolved['question'];
            $name = ImportNote::excerpt($question->text('name') ?? sprintf('question %d', $position + 1));
            $mapping = $this->questions->map($question);

            if ($mapping->isSkipped()) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question "%s" of quiz "%s" skipped: %s.',
                    $name,
                    $quizTitle,
                    (string) $mapping->skipped,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            if ($progress->isDryRun()) {
                $progress->counted(ImportResult::TOTAL_QUESTIONS);
                continue;
            }

            $points = $slot->float('maxmark') ?? $this->questions->defaultMark($question) ?? 1.0;
            $points = $points > 0.0 ? $points : 1.0;
            $sourceKey = $quizKey . '/' . $resolved['key'];
            $existing = $this->map->lookup(self::KEY, self::TYPE_QUESTION, $sourceKey);

            try {
                $questionId = $existing !== null
                    ? $this->updateQuestion->execute($principal, $existing, $mapping->definition, $points)->id
                    : $this->addQuestion->execute($principal, $quizId, $mapping->type, $mapping->definition, $points)->id;
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question "%s" of quiz "%s" skipped: %s',
                    $name,
                    $quizTitle,
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            if ($questionId === null) {
                continue;
            }

            $this->remember(self::TYPE_QUESTION, $sourceKey, self::TYPE_QUESTION, $questionId, $progress);
            $progress->counted(ImportResult::TOTAL_QUESTIONS);

            if ($mapping->note !== null) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question "%s" of quiz "%s": %s.',
                    $name,
                    $quizTitle,
                    $mapping->note,
                ));
            }
        }
    }

    private function sectionTitle(MoodleBackup $backup, MoodleSection $section): string
    {
        if ($section->title !== null) {
            return $section->title;
        }

        if ($section->number === 0) {
            return $this->translator->translate('vidyalms.importer.moodle.general_section');
        }

        $key = $backup->courseFormat() === 'weeks'
            ? 'vidyalms.importer.moodle.week'
            : 'vidyalms.importer.moodle.topic';

        return $this->translator->translate($key, ['number' => $section->number]);
    }

    private function slug(?string $shortName): ?string
    {
        if ($shortName === null) {
            return null;
        }

        try {
            return Slug::fromTitle($shortName)->value;
        } catch (\Throwable) {
            return null;
        }
    }

    private function positive(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }

    /**
     * @param list<string> $paths
     */
    private function discard(array $paths): void
    {
        foreach ($paths as $path) {
            $this->storage->delete($path);
        }
    }

    private function remember(string $sourceType, string $sourceKey, string $localType, int $localId, ImportProgress $progress): void
    {
        $this->map->remember(self::KEY, $sourceType, $sourceKey, $localType, $localId, $progress->jobId());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function limit(array $options, string $key, int $default, int $ceiling): int
    {
        $value = $options[$key] ?? null;

        return \is_int($value) && $value > 0 ? min($value, $ceiling) : $default;
    }

    private function capped(string $value, int $limit, string $what, ImportProgress $progress): string
    {
        if (\strlen($value) <= $limit) {
            return $value;
        }

        $progress->note(ImportNote::WARNING, sprintf('%s was longer than %d bytes and was shortened.', $what, $limit));

        return $this->cut($value, $limit);
    }

    private function excerpt(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return $text === '' ? null : $this->cut($text, 500);
    }

    /**
     * Cuts to a byte length without leaving half a UTF-8 character behind,
     * which matters because the use cases measure titles in bytes.
     */
    private function cut(string $value, int $limit): string
    {
        $cut = substr($value, 0, $limit);

        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut);
    }
}
