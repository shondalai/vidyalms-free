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
use Shondalai\VidyaLMS\Core\Application\Curriculum\MoveLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;

/**
 * Imports a course from a JSON document held in private storage.
 *
 * It exists for two reasons that are not "demonstration": it is the
 * interchange format for scripted seeding (fixtures, staging refreshes, a
 * course authored in a spreadsheet and converted), and it is the format
 * another importer can target when the shape of a foreign export is easier to
 * translate than to walk twice.
 *
 * ## The format
 *
 *   {
 *     "key": "intro-to-tea",            optional stable identity of the course
 *     "title": "Foundations of Tea",    required
 *     "summary": "...", "description": "...", "level": "...", "language": "...",
 *     "modules": [
 *       {
 *         "key": "m1", "title": "Module One", "summary": "...",
 *         "lessons": [
 *           {"key": "l1", "title": "Read the leaves", "type": "reading",
 *            "content": {"body": "<p>Steep for four minutes.</p>"},
 *            "summary": "...", "duration_seconds": 240, "free_preview": true},
 *           {"key": "l2", "title": "Prove it", "type": "quiz",
 *            "content": {"quiz": "q1"}}
 *         ]
 *       }
 *     ],
 *     "quizzes": [
 *       {
 *         "key": "q1", "title": "Final Quiz", "pass_mark": 60,
 *         "is_final": true, "max_attempts": 3, "time_limit_seconds": 600,
 *         "show_results": "after_submit",
 *         "questions": [
 *           {"key": "q1a", "type": "single_choice", "points": 5,
 *            "definition": {"prompt": "...", "options": [...], "correct": "a"}}
 *         ]
 *       }
 *     ]
 *   }
 *
 * Lesson "content" is the content shape of the registered lesson type, and
 * question "definition" is the definition shape of the registered question
 * type, both validated by the provider itself. All six free question types
 * work; nothing here knows their shapes, which is the point.
 *
 * A quiz lesson names its quiz by the quiz's key rather than by an id,
 * because ids do not exist until the import runs. Quizzes are created first,
 * then lessons resolve the reference, then the quiz is pointed back at its
 * lesson.
 *
 * ## Keys, and why they matter
 *
 * Every node may carry a "key", the identity this document promises to keep
 * across re-imports, and it is the preferred way to say what a course is.
 * Module, quiz and question keys are namespaced under the course key, so two
 * documents may both call a module "m1". A lesson with a key of its own is
 * namespaced under the course rather than under its module, so moving it to
 * another module of the same document moves the lesson here rather than
 * building a second copy of it; a lesson with no key of its own keeps the
 * module-scoped positional identity, because "the first lesson" only means
 * anything inside one module.
 *
 * A node with no key gets one derived from its position, which is stable only
 * as long as nothing is reordered: add a module in the middle of a keyless
 * document and the re-import updates the wrong rows. Author keys for anything
 * that will be re-imported.
 *
 * ## Re-importing, and the corrected document
 *
 * A course this importer created is re-imported by identity, never by path: a
 * path is unique per upload, so keying by it would make the second upload of
 * one file a second course. The identity is the "course_key" option when the
 * caller passes one, otherwise the document's "key", otherwise a digest of the
 * document's content with object keys sorted so formatting and field order do
 * not count.
 *
 * The digest carries the same file uploaded twice, and no more than that.
 * Correcting a document changes its content, so it changes its digest: the
 * recovery an operator reaches for after a failed run, fix the file and upload
 * it again, needs a key that survives the fix. Give a document that will ever
 * be re-imported a "key" of its own, or pass the course key the log named as
 * the "course_key" option. Every keyless import writes that key into the log
 * as a warning for exactly this reason.
 *
 * ## What a bad node costs
 *
 * A node the use cases refuse is skipped with a warning and counted, and the
 * run carries on: one over-long title does not throw away the eleven lessons
 * already written. Titles longer than the column are shortened rather than
 * refused. The single exception is a course that cannot be created at all,
 * because there is then nowhere to put anything else.
 *
 * Courses land as drafts. A migration is reviewed by a human before learners
 * see it.
 */
final class JsonCourseImporter extends AbstractImporter implements ConfigurableImporterInterface
{
    public const KEY = 'json-course';

    private const TYPE_COURSE = 'course';
    private const TYPE_MODULE = 'module';
    private const TYPE_LESSON = 'lesson';
    private const TYPE_QUIZ = 'quiz';
    private const TYPE_QUESTION = 'question';

    /** What the title columns take, so a longer one is shortened, not refused. */
    private const TITLE_LIMIT = 255;

    /** How much of a source key a note quotes. */
    private const KEY_EXCERPT = 80;

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
        private readonly MoveLesson $moveLesson,
        private readonly CreateQuiz $createQuiz,
        private readonly UpdateQuiz $updateQuiz,
        private readonly AddQuestion $addQuestion,
        private readonly UpdateQuestion $updateQuestion,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.json_course');
    }

    /**
     * @return list<ImportOption>
     */
    public function importOptions(): array
    {
        return [
            new ImportOption(
                'course_key',
                $this->translator->translate('vidyalms.importer.option.course_key'),
                ImportOption::KIND_TEXT,
                $this->translator->translate('vidyalms.importer.option.course_key.help'),
            ),
        ];
    }

    public function supports(string $sourcePath): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $sourcePath) === 1) {
            return false;
        }

        return str_ends_with(strtolower(trim($sourcePath)), '.json');
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $document = $this->readDocument($sourcePath);
        $courseKey = $this->courseKey($document, $options, $progress);

        $courseId = $this->course($document, $courseKey, $principal, $progress);

        $quizLessons = [];
        $quizIds = $this->quizzes($document, $courseKey, $courseId, $principal, $progress);
        $lessonKeys = [];
        $this->modules($document, $courseKey, $courseId, $quizIds, $quizLessons, $lessonKeys, $principal, $progress);
        $this->bindQuizLessons($document, $quizIds, $quizLessons, $principal, $progress);
    }

    /**
     * @return array<string, mixed>
     */
    private function readDocument(string $sourcePath): array
    {
        if (!$this->supports($sourcePath)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('"%s" is not a JSON course file.', $sourcePath));
        }

        $raw = $this->storage->readPrivate($sourcePath);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s is not valid JSON: %s', $sourcePath, $failure->getMessage()), 0, $failure);
        }

        if (!\is_array($decoded)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s must contain a JSON object.', $sourcePath));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The identity of the course this document describes, in the order the
     * three answers are trusted: the "course_key" option, the document's own
     * "key", and a digest of the document itself.
     *
     * The storage path is deliberately not it. A path is unique per upload, so
     * keying by it makes re-uploading the same file after a failure build a
     * second course, which is exactly what the admin screens tell an operator
     * to do when a run dies halfway.
     *
     * The digest is exact, and that is its limit: a corrected document is a
     * different document, so the recovery the screens describe (fix the file,
     * upload it again) would build a second course out of the corrected copy
     * while the broken half-import stayed. So a keyless document says so, in
     * the log, naming the key to carry: the note is the one thing that makes
     * the recovery safe, which is why it is a warning and not a comment.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $document
     */
    private function courseKey(array $document, array $options, ImportProgress $progress): string
    {
        $given = $options['course_key'] ?? null;

        if (\is_string($given) && trim($given) !== '') {
            return trim($given);
        }

        $key = $this->string($document, 'key');

        if ($key !== null) {
            return $key;
        }

        $digest = 'sha1:' . sha1((string) json_encode($this->sorted($document), JSON_THROW_ON_ERROR));

        $progress->note(ImportNote::WARNING, sprintf(
            'This document carries no "key", so it is identified by a digest of its content: "%s". Editing'
                . ' it changes that digest, so a corrected copy would import as a second course. To fix this'
                . ' document and import it again into the same course, give it "key": "%s", or run the import'
                . ' with that as the course key.',
            $digest,
            $digest,
        ));

        return $digest;
    }

    /**
     * The document with every object's keys in one order, so two uploads that
     * differ only in field order or whitespace digest the same.
     */
    private function sorted(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $sorted = [];

        /** @var mixed $item */
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sorted($item);
        }

        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function course(array $document, string $courseKey, Principal $principal, ImportProgress $progress): int
    {
        $title = $this->string($document, 'title');

        if ($title === null) {
            throw new \RuntimeException('The document needs a course title.');
        }

        $title = $this->capped($title, 'The course title', $progress);
        $existing = $this->map->lookup(self::KEY, self::TYPE_COURSE, $courseKey);

        if ($progress->isDryRun()) {
            $progress->counted(ImportResult::TOTAL_COURSES);
            $progress->note(ImportNote::INFO, sprintf(
                'Course "%s" would be %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));

            return $existing ?? 0;
        }

        if ($existing !== null) {
            try {
                $this->updateCourse->execute($principal, $existing, [
                    'title' => $title,
                    'summary' => $this->string($document, 'summary'),
                    'description' => $this->string($document, 'description'),
                    'level' => $this->string($document, 'level'),
                    'language' => $this->string($document, 'language'),
                ]);
                $progress->note(ImportNote::INFO, sprintf('Course "%s" updated.', $title));
            } catch (\Throwable $failure) {
                // The course is already there, so its own fields are the only
                // thing lost: the rest of the document still imports into it.
                $progress->note(ImportNote::WARNING, sprintf(
                    'Course "%s" kept its previous details: %s',
                    $title,
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
            }

            $courseId = $existing;
        } else {
            try {
                $courseId = $this->createCourse->execute(
                    $principal,
                    $title,
                    summary: $this->string($document, 'summary'),
                    description: $this->string($document, 'description'),
                    level: $this->string($document, 'level'),
                    language: $this->string($document, 'language'),
                )->id;
            } catch (\Throwable $failure) {
                // The one node that cannot be skipped past: with no course
                // there is nowhere to put the modules. It fails naming the
                // field and the problem rather than "Validation failed.".
                throw new \RuntimeException(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('Course "%s" could not be created: %s', $title, ImportProblem::describe($failure)),
                    0,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $failure,
                );
            }

            $progress->note(ImportNote::INFO, sprintf('Course "%s" created.', $title));
        }

        $this->remember(self::TYPE_COURSE, $courseKey, self::TYPE_COURSE, $courseId, $progress);
        $progress->counted(ImportResult::TOTAL_COURSES);

        return $courseId;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, int> quiz key to quiz id
     */
    private function quizzes(
        array $document,
        string $courseKey,
        int $courseId,
        Principal $principal,
        ImportProgress $progress,
    ): array {
        $ids = [];

        foreach ($this->nodes($document, 'quizzes') as $index => $quiz) {
            $title = $this->string($quiz, 'title');

            if ($title === null) {
                $progress->note(ImportNote::WARNING, sprintf('Quiz %d has no title, skipped.', $index + 1));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $title = $this->capped($title, sprintf('The title of quiz %d', $index + 1), $progress);
            $sourceKey = $this->childKey($courseKey, $quiz, 'quiz', $index);
            $existing = $this->map->lookup(self::KEY, self::TYPE_QUIZ, $sourceKey);

            if ($progress->isDryRun()) {
                $progress->counted(ImportResult::TOTAL_QUIZZES);
                $progress->note(ImportNote::INFO, sprintf(
                    'Quiz "%s" would be %s.',
                    $title,
                    $existing === null ? 'created' : 'updated',
                ));
                $this->questions($quiz, $sourceKey, 0, $principal, $progress);
                // A preview must reach the same conclusions the real run
                // does, so a quiz lesson can find the quiz it names here too.
                $ids[$this->nodeKey($quiz, 'quiz', $index)] = 0;
                continue;
            }

            try {
                $quizId = $this->saveQuiz($quiz, $title, $courseId, $existing, $principal);
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Quiz "%s" skipped: %s',
                    $title,
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $this->remember(self::TYPE_QUIZ, $sourceKey, self::TYPE_QUIZ, $quizId, $progress);
            $progress->counted(ImportResult::TOTAL_QUIZZES);
            $progress->note(ImportNote::INFO, sprintf(
                'Quiz "%s" %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));

            $ids[$this->nodeKey($quiz, 'quiz', $index)] = $quizId;
            $this->questions($quiz, $sourceKey, $quizId, $principal, $progress);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $quiz
     */
    private function saveQuiz(array $quiz, string $title, int $courseId, ?int $existing, Principal $principal): int
    {
        $description = $this->string($quiz, 'description');
        $passMark = $this->int($quiz, 'pass_mark') ?? 0;
        $maxAttempts = $this->int($quiz, 'max_attempts');
        $timeLimit = $this->int($quiz, 'time_limit_seconds');
        $isFinal = ($quiz['is_final'] ?? false) === true;
        $showResults = $this->string($quiz, 'show_results') ?? Quiz::SHOW_RESULTS_AFTER_SUBMIT;

        if ($existing !== null) {
            $this->updateQuiz->execute(
                $principal,
                $existing,
                $title,
                description: $description,
                passMark: $passMark,
                maxAttempts: $maxAttempts,
                timeLimitSeconds: $timeLimit,
                isFinal: $isFinal,
                showResults: $showResults,
            );

            return $existing;
        }

        $created = $this->createQuiz->execute(
            $principal,
            $courseId,
            $title,
            description: $description,
            passMark: $passMark,
            maxAttempts: $maxAttempts,
            timeLimitSeconds: $timeLimit,
            isFinal: $isFinal,
            showResults: $showResults,
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $created->id ?? throw new \RuntimeException(sprintf('Quiz "%s" was stored without an id.', $title));
    }

    /**
     * @param array<string, mixed> $quiz
     */
    private function questions(
        array $quiz,
        string $quizKey,
        int $quizId,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        foreach ($this->nodes($quiz, 'questions') as $index => $question) {
            $type = $this->string($question, 'type');
            $definition = $question['definition'] ?? null;

            if ($type === null || !\is_array($definition)) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question %d of quiz "%s" has no type or definition, skipped.',
                    $index + 1,
                    $quizKey,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            /** @var array<string, mixed> $definition */
            $points = $this->float($question, 'points') ?? 1.0;
            $sourceKey = $this->childKey($quizKey, $question, 'question', $index);
            $existing = $this->map->lookup(self::KEY, self::TYPE_QUESTION, $sourceKey);

            if ($progress->isDryRun()) {
                $progress->counted(ImportResult::TOTAL_QUESTIONS);
                continue;
            }

            try {
                $questionId = $existing !== null
                    ? $this->updateQuestion->execute($principal, $existing, $definition, $points)->id
                    : $this->addQuestion->execute($principal, $quizId, $type, $definition, $points)->id;
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Question %d of quiz "%s" skipped: %s',
                    $index + 1,
                    $quizKey,
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
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, int>   $quizIds
     * @param array<string, int>   $quizLessons quiz key to the lesson id that hosts it
     * @param array<string, true>  $lessonKeys  the lesson identities this document has already claimed
     */
    private function modules(
        array $document,
        string $courseKey,
        int $courseId,
        array $quizIds,
        array &$quizLessons,
        array &$lessonKeys,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        foreach ($this->nodes($document, 'modules') as $index => $module) {
            $title = $this->string($module, 'title');

            if ($title === null) {
                $progress->note(ImportNote::WARNING, sprintf('Module %d has no title, skipped.', $index + 1));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $title = $this->capped($title, sprintf('The title of module %d', $index + 1), $progress);
            $sourceKey = $this->childKey($courseKey, $module, 'module', $index);
            $existing = $this->map->lookup(self::KEY, self::TYPE_MODULE, $sourceKey);

            if ($progress->isDryRun()) {
                $progress->counted(ImportResult::TOTAL_MODULES);
                $this->lessons($module, $courseKey, $sourceKey, 0, $quizIds, $quizLessons, $lessonKeys, $principal, $progress);
                continue;
            }

            try {
                $moduleId = $existing !== null
                    ? $this->updateModule->execute($principal, $existing, [
                        'title' => $title,
                        'summary' => $this->string($module, 'summary'),
                    ])->id
                    : $this->addModule->execute($principal, $courseId, $title, $this->string($module, 'summary'))->id;
            } catch (\Throwable $failure) {
                $lessons = \count($this->nodes($module, 'lessons'));
                $progress->note(ImportNote::WARNING, sprintf(
                    'Module "%s" skipped: %s%s',
                    $title,
                    ImportProblem::describe($failure),
                    $lessons === 0 ? '' : sprintf(' Its %d lesson(s) went with it.', $lessons),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED, 1 + $lessons);
                continue;
            }

            $this->remember(self::TYPE_MODULE, $sourceKey, self::TYPE_MODULE, $moduleId, $progress);
            $progress->counted(ImportResult::TOTAL_MODULES);
            $progress->note(ImportNote::INFO, sprintf(
                'Module "%s" %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));

            $this->lessons($module, $courseKey, $sourceKey, $moduleId, $quizIds, $quizLessons, $lessonKeys, $principal, $progress);
        }
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, int>  $quizIds
     * @param array<string, int>  $quizLessons
     * @param array<string, true> $lessonKeys the lesson identities this document has already claimed
     */
    private function lessons(
        array $module,
        string $courseKey,
        string $moduleKey,
        int $moduleId,
        array $quizIds,
        array &$quizLessons,
        array &$lessonKeys,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        foreach ($this->nodes($module, 'lessons') as $index => $lesson) {
            $title = $this->string($lesson, 'title');
            $type = $this->string($lesson, 'type');

            if ($title === null || $type === null) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Lesson %d of "%s" has no title or type, skipped.',
                    $index + 1,
                    $moduleKey,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            /** @var mixed $authored */
            $authored = $lesson['content'] ?? null;
            /** @var array<string, mixed> $content */
            $content = \is_array($authored) ? $authored : [];
            $quizReference = $type === self::TYPE_QUIZ ? $this->string($content, 'quiz') : null;

            if ($type === self::TYPE_QUIZ && $quizReference === null) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Quiz lesson "%s" names no quiz, skipped.',
                    $title,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            // Decided before the dry run stops, so a preview counts the same
            // lesson the real run writes and skips the same one it skips.
            if ($quizReference !== null && !isset($quizIds[$quizReference])) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Quiz lesson "%s" names unknown quiz "%s", skipped.',
                    $title,
                    $quizReference,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $duration = $this->int($lesson, 'duration_seconds');

            // AddLesson refuses this too, and that is what makes the answer
            // the same everywhere: passed through, a duration past what the
            // column holds is a skip on a strict MySQL and a silent
            // 4294967295 on a host whose sql_mode has no STRICT_TRANS_TABLES.
            // Decided before the dry run stops, so a preview skips it too.
            if ($duration !== null && ($duration < 0 || $duration > Lesson::MAX_DURATION_SECONDS)) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Lesson "%s" gives a duration of %d seconds, which is not a duration VidyaLMS stores'
                        . ' (0 to %d), skipped.',
                    $title,
                    $duration,
                    Lesson::MAX_DURATION_SECONDS,
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $positional = $this->childKey($moduleKey, $lesson, 'lesson', $index);
            $sourceKey = $this->lessonKey($courseKey, $moduleKey, $lesson, $index);

            // Decided before the dry run stops, like everything else here, so
            // a preview reports the document's key problems too.
            if ($sourceKey !== $positional && isset($lessonKeys[$sourceKey])) {
                // Two lessons of one document cannot both be the same lesson.
                // The second keeps its place instead, and the note says which
                // key needs fixing.
                $progress->note(ImportNote::WARNING, sprintf(
                    'Lesson "%s" repeats the key "%s", which another lesson of this document already'
                        . ' carries, so it was imported under its position instead. Give it a key of its own.',
                    $title,
                    ImportNote::excerpt((string) $this->string($lesson, 'key'), self::KEY_EXCERPT),
                ));
                $sourceKey = $positional;
            }

            $lessonKeys[$sourceKey] = true;

            if ($progress->isDryRun()) {
                $progress->counted(ImportResult::TOTAL_LESSONS);
                continue;
            }

            if ($quizReference !== null) {
                $content = ['quiz_id' => $quizIds[$quizReference]];
            }

            $title = $this->capped($title, sprintf('The title of lesson %d', $index + 1), $progress);
            $existing = $this->map->lookup(self::KEY, self::TYPE_LESSON, $sourceKey);

            if ($existing === null && $sourceKey !== $positional) {
                // Documents imported when a lesson key was scoped to its
                // module keep the identity they were given then.
                $existing = $this->map->lookup(self::KEY, self::TYPE_LESSON, $positional);
            }

            try {
                if ($existing !== null) {
                    $updated = $this->updateLesson->execute($principal, $existing, [
                        'title' => $title,
                        'summary' => $this->string($lesson, 'summary'),
                        'duration_seconds' => $duration,
                        'free_preview' => ($lesson['free_preview'] ?? false) === true,
                        'type' => $type,
                        'content' => $content,
                    ] + ($type === 'video' && ($duration ?? 0) < 1 ? ['state' => 'draft'] : []));

                    // UpdateLesson leaves the module alone, so a lesson the
                    // document has moved still names the module of the last
                    // run and has to be moved rather than copied.
                    $moved = $updated->moduleId === $moduleId
                        || $this->move($principal, $updated->id, $moduleId, $title, $progress);

                    if (!$moved) {
                        continue;
                    }

                    $lessonId = $updated->id;
                } else {
                    $lessonId = $this->addLesson->execute(
                        $principal,
                        $moduleId,
                        $title,
                        $type,
                        $content,
                        summary: $this->string($lesson, 'summary'),
                        durationSeconds: $duration,
                        freePreview: ($lesson['free_preview'] ?? false) === true,
                        state: $type === 'video' && ($duration ?? 0) < 1 ? 'draft' : 'published',
                    )->id;
                }
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Lesson "%s" skipped: %s',
                    $title,
                    ImportProblem::describe($failure),
                ));
                $progress->counted(ImportResult::TOTAL_SKIPPED);
                continue;
            }

            $this->remember(self::TYPE_LESSON, $sourceKey, self::TYPE_LESSON, $lessonId, $progress);
            $progress->counted(ImportResult::TOTAL_LESSONS);
            $progress->note(ImportNote::INFO, sprintf(
                'Lesson "%s" %s.',
                $title,
                $existing === null ? 'created' : 'updated',
            ));

            if ($quizReference !== null) {
                $quizLessons[$quizReference] = $lessonId;
            }
        }
    }

    /**
     * Puts an already imported lesson under the module the document keeps it
     * in now.
     *
     * A document gets reorganised between imports, and a keyed lesson that
     * moved to another module is the same lesson: left where it was, the
     * re-import would build a second copy under the new module and leave the
     * first one in the old one, unreported. MoveLesson is the only way to say
     * that in the core, and when it refuses (a lesson row deleted here, a
     * module of another course) the note says so rather than a copy appearing.
     *
     * @return bool false when the lesson stayed where it was, which is when
     *              the caller must leave this node alone
     */
    private function move(
        Principal $principal,
        int $lessonId,
        int $moduleId,
        string $title,
        ImportProgress $progress,
    ): bool {
        try {
            $this->moveLesson->execute($principal, $lessonId, $moduleId);
        } catch (\Throwable $failure) {
            $progress->note(ImportNote::WARNING, sprintf(
                'Lesson "%s" belongs to another module in this document now and was left where it is: %s'
                    . ' Delete it here and import again to have it built under its current module.',
                $title,
                ImportProblem::describe($failure),
            ));
            $progress->counted(ImportResult::TOTAL_SKIPPED);

            return false;
        }

        $progress->note(ImportNote::INFO, sprintf(
            'Lesson "%s" moved to the module this document keeps it in.',
            $title,
        ));

        return true;
    }

    /**
     * Points each quiz at the lesson that hosts it, which cannot happen
     * earlier: the lesson does not exist until the quiz has an id.
     *
     * @param array<string, mixed> $document
     * @param array<string, int>   $quizIds
     * @param array<string, int>   $quizLessons
     */
    private function bindQuizLessons(
        array $document,
        array $quizIds,
        array $quizLessons,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        if ($progress->isDryRun() || $quizLessons === []) {
            return;
        }

        foreach ($this->nodes($document, 'quizzes') as $index => $quiz) {
            $reference = $this->nodeKey($quiz, 'quiz', $index);
            $title = $this->string($quiz, 'title');

            if ($title === null || !isset($quizIds[$reference], $quizLessons[$reference])) {
                continue;
            }

            try {
                $this->saveQuizBinding($quiz, $title, $quizIds[$reference], $quizLessons[$reference], $principal);
            } catch (\Throwable $failure) {
                $progress->note(ImportNote::WARNING, sprintf(
                    'Quiz "%s" could not be attached to its lesson: %s',
                    $title,
                    ImportProblem::describe($failure),
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $quiz
     */
    private function saveQuizBinding(array $quiz, string $title, int $quizId, int $lessonId, Principal $principal): void
    {
        $this->updateQuiz->execute(
            $principal,
            $quizId,
            $title,
            lessonId: $lessonId,
            description: $this->string($quiz, 'description'),
            passMark: $this->int($quiz, 'pass_mark') ?? 0,
            maxAttempts: $this->int($quiz, 'max_attempts'),
            timeLimitSeconds: $this->int($quiz, 'time_limit_seconds'),
            isFinal: ($quiz['is_final'] ?? false) === true,
            showResults: $this->string($quiz, 'show_results') ?? Quiz::SHOW_RESULTS_AFTER_SUBMIT,
        );
    }

    private function remember(string $sourceType, string $sourceKey, string $localType, int $localId, ImportProgress $progress): void
    {
        $this->map->remember(self::KEY, $sourceType, $sourceKey, $localType, $localId, $progress->jobId());
    }

    /**
     * A title the columns will take. A document written by hand or converted
     * from a spreadsheet carries the odd over-long title, and losing the node
     * it belongs to teaches an operator nothing; the note says what happened.
     */
    private function capped(string $value, string $what, ImportProgress $progress): string
    {
        if (\strlen($value) <= self::TITLE_LIMIT) {
            return $value;
        }

        $progress->note(ImportNote::WARNING, sprintf(
            '%s was longer than %d bytes and was shortened.',
            $what,
            self::TITLE_LIMIT,
        ));

        // Cut on a byte length without leaving half a UTF-8 character behind,
        // because the use cases measure titles in bytes.
        $cut = substr($value, 0, self::TITLE_LIMIT);

        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return rtrim($cut);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<array<string, mixed>>
     */
    private function nodes(array $node, string $field): array
    {
        $values = $node[$field] ?? null;

        if (!\is_array($values)) {
            return [];
        }

        $nodes = [];

        /** @var mixed $value */
        foreach ($values as $value) {
            if (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $nodes[] = $value;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function nodeKey(array $node, string $kind, int $index): string
    {
        return $this->string($node, 'key') ?? $kind . '/' . $index;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function childKey(string $parentKey, array $node, string $kind, int $index): string
    {
        return $parentKey . '/' . $this->nodeKey($node, $kind, $index);
    }

    /**
     * The identity of one lesson node.
     *
     * A lesson that says what it is belongs to the course rather than to the
     * module it happens to sit in, which is what lets it move between modules
     * and stay the same lesson. One that says nothing is identified by its
     * place in a module, and a place only means something inside the module
     * that holds it.
     *
     * @param array<string, mixed> $node
     */
    private function lessonKey(string $courseKey, string $moduleKey, array $node, int $index): string
    {
        $key = $this->string($node, 'key');

        return $key === null
            ? $this->childKey($moduleKey, $node, 'lesson', $index)
            : $courseKey . '/lesson/' . $key;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function string(array $node, string $field): ?string
    {
        $value = $node[$field] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function int(array $node, string $field): ?int
    {
        $value = $node[$field] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function float(array $node, string $field): ?float
    {
        $value = $node[$field] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
