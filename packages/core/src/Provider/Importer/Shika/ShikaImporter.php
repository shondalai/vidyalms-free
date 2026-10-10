<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Shika;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\CourseDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\CurriculumWriter;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\JoomlaSourceImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\LearnerDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\LessonDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\ModuleDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\QuestionDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\QuizDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\SourceRun;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\SourceTables;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\SourceUnreadable;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\SourceValue;

/**
 * Moves a Shika (Techjoomla, com_tjlms) site's courses into VidyaLMS.
 *
 * ## UNVERIFIED AGAINST A REAL INSTALL
 *
 * No Shika is installed on either development site and none was installed to
 * write this. Shika is a commercial component whose install script is not
 * published, so this importer was written from Techjoomla's own public
 * documentation and from the table names the product is known by, and the
 * label says so on the Import screen.
 *
 * The one thing that is confirmed is the lesson format vocabulary.
 * Techjoomla's published documentation of Shika's lesson type plugins quotes
 * the component's own format array verbatim: `'scorm', 'htmlzips',
 * 'tincanlrs', 'video', 'document', 'textmedia', 'externaltool'`. Those seven
 * words are the mapping table below, and four of them are refused by name
 * because the free build has nothing that carries them. Its published
 * documentation also states the content structure this importer assumes,
 * that a course is structured into modules and lessons, and names quizzes,
 * exercises and feedback as further lesson types.
 *
 * Everything else, every column of every table, is a guess written down: the
 * reads select every column and pick their fields out by name with
 * alternatives, so one wrong guess costs a field rather than a table.
 *
 * **What a site owner should check first**, in this order:
 *
 * 1. Run it as a dry run and read the log. It names which of the two table
 *    families it found, and any table it could not read.
 * 2. The course structure. If the install has no module table this importer
 *    can read, every lesson of a course lands in one module named after the
 *    course, and the log says so.
 * 3. The quiz questions, which are the most speculative part: their type is
 *    inferred from the options when Shika's own type word is not one of the
 *    handful this importer knows.
 * 4. Who came across as a learner. Shika's enrolment table could not be
 *    confirmed, so access is inferred from the lesson tracking table: a
 *    person with a tracking row is treated as having been on the course.
 *
 * ## What does not come across
 *
 * SCORM packages, HTML5 zip packages, Tin Can (xAPI) content and LTI external
 * tools have no free equivalent and are refused by name rather than converted
 * into an empty reading. Certificates, coupons, course fields, question
 * categories and the question bank itself are not read at all: a bank is not
 * a course, and importing one without its quizzes would produce questions
 * nothing asks.
 */
final class ShikaImporter extends JoomlaSourceImporter
{
    public const KEY = 'shika';

    /**
     * Shika's own lesson format words, and the free lesson type each becomes.
     *
     * The seven formats are quoted from Techjoomla's published lesson type
     * plugin documentation. `quiz`, `exercise` and `feedback` are named as
     * lesson types by the product documentation rather than by that array,
     * and are here for that reason.
     *
     * @var array<string, string>
     */
    private const LESSON_TYPES = [
        'textmedia' => 'reading',
        'document' => 'reading',
        'video' => 'video',
        'quiz' => 'quiz',
    ];

    /**
     * The formats the free build has nothing for, each with the sentence the
     * log gives for it. Refusing them by name is the point: a SCORM package
     * silently turned into an empty reading is a course that looks imported
     * and teaches nothing.
     *
     * @var array<string, string>
     */
    private const REFUSED_TYPES = [
        'scorm' => 'a SCORM package needs a SCORM player, which the free build does not have',
        'htmlzips' => 'an HTML5 zip package needs a package player, which the free build does not have',
        'tincanlrs' => 'Tin Can (xAPI) content needs a learning record store, which the free build does not have',
        'externaltool' => 'an LTI external tool needs an LTI consumer, which the free build does not have',
        'exercise' => 'a Shika exercise has no free equivalent',
        'feedback' => 'a Shika feedback form has no free equivalent',
    ];

    private readonly ShikaSource $source;

    public function __construct(
        DatabasePort $database,
        private readonly TranslatorPort $translator,
        CurriculumWriter $writer,
    ) {
        parent::__construct($writer);
        $this->source = new ShikaSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.shika');
    }

    public function supports(string $sourcePath): bool
    {
        return $this->claimsScheme($sourcePath) && $this->source->resolve();
    }

    protected function translator(): TranslatorPort
    {
        return $this->translator;
    }

    protected function productName(): string
    {
        return 'Shika';
    }

    protected function markerTable(): string
    {
        return ShikaSource::T_COURSES;
    }

    protected function installed(): bool
    {
        return $this->source->resolve();
    }

    /**
     * Which table each level of a course came out of, in the family this run
     * resolved, so a row problem names the table an operator would open.
     *
     * Shika's enrolment table could not be confirmed, so a learner problem is
     * reported against the lesson tracking table, which is where this importer
     * actually reads its learners from.
     */
    private function tables(): SourceTables
    {
        return SourceTables::of(
            $this->source->coursesTable(),
            $this->source->modulesTable(),
            $this->source->lessonsTable(),
            $this->source->quizzesTable(),
            $this->source->questionsTable(),
            $this->source->trackTable(),
        );
    }

    protected function unverifiedNote(): string
    {
        return 'This Shika importer was written from Techjoomla\'s public documentation, which confirms the'
            . ' lesson format vocabulary and the course structure and publishes no schema. It has not been'
            . ' verified against a real Shika install, so every column it reads is a documented guess. Read'
            . ' the log for any table it could not read, and check the quiz questions and the learners first.';
    }

    protected function importCourses(SourceRun $run, array $courseIds): void
    {
        $run->note(sprintf('Reading the Shika tables named "%s" and its family.', $this->source->coursesTable()));
        $courses = $this->source->courses($courseIds);

        if ($courses === []) {
            $run->note('No Shika courses matched this source.');

            return;
        }

        foreach ($courses as $row) {
            $courseId = SourceValue::integer($row['id'] ?? null);

            if ($courseId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $this->source->coursesTable(),
                    'course:?',
                    'id',
                    '',
                    'the course row carries no id',
                );
                continue;
            }

            try {
                $draft = $this->course($run, $row, $courseId);
            } catch (SourceUnreadable $unreadable) {
                $run->failed(
                    $this->source->coursesTable(),
                    sprintf('Shika course %d: %s', $courseId, $unreadable->explanation()),
                );
                continue;
            } catch (\Throwable $failure) {
                $run->failed($this->source->coursesTable(), sprintf(
                    'Shika course %d could not be read: %s',
                    $courseId,
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            $this->writer->write(self::KEY, $this->tables(), $run, $draft);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function course(SourceRun $run, array $row, int $courseId): CourseDraft
    {
        $title = SourceValue::text(SourceValue::firstOf($row, ['title', 'name', 'course_name']));
        $title = $title === '' ? sprintf('Shika course %d', $courseId) : $title;
        $lessonKeysById = [];
        $modules = $this->modules($run, $courseId, $title, $lessonKeysById);

        return CourseDraft::of(
            sprintf('course:%d', $courseId),
            $title,
            SourceValue::nullable(SourceValue::firstOf($row, ['short_description', 'introtext', 'summary'])),
            SourceValue::nullable(SourceValue::firstOf($row, ['description', 'fulltext'])),
            SourceValue::nullable(SourceValue::firstOf($row, ['difficulty_level', 'level'])),
            SourceValue::integer(SourceValue::firstOf($row, ['access'])) > 1
                ? CourseVisibility::Unlisted
                : CourseVisibility::Public,
            SourceValue::integer(SourceValue::firstOf($row, ['state', 'published'])) === 1,
            $modules,
            $this->learners($run, $courseId, $lessonKeysById),
        );
    }

    /**
     * The course's modules and their lessons.
     *
     * Shika groups lessons into modules, and this importer could not confirm
     * the module table's name against a published source. When it is not
     * there, every lesson of the course lands in one module named after the
     * course rather than being lost, and the log says which happened, because
     * a flattened curriculum is a decision a site owner has to be told about.
     *
     * @param array<int, string> $lessonKeysById
     *
     * @return list<ModuleDraft>
     */
    private function modules(SourceRun $run, int $courseId, string $courseTitle, array &$lessonKeysById): array
    {
        $lessons = $this->source->lessons($courseId);
        $moduleRows = $this->source->modules($courseId);

        if ($moduleRows === []) {
            $run->warn(sprintf(
                'Course "%s": no Shika module table could be read, so its %d lesson(s) were imported into one'
                    . ' module named after the course. Regroup them here after the import.',
                ImportNote::excerpt($courseTitle),
                \count($lessons),
            ));

            return [ModuleDraft::of(
                sprintf('module:course:%d', $courseId),
                $courseTitle,
                null,
                $this->lessons($run, $lessons, $lessonKeysById),
            )];
        }

        $byModule = [];

        foreach ($lessons as $lesson) {
            $byModule[SourceValue::integer(SourceValue::firstOf($lesson, ['module_id', 'moduleid']))][] = $lesson;
        }

        $modules = [];

        foreach ($moduleRows as $moduleRow) {
            $moduleId = SourceValue::integer($moduleRow['id'] ?? null);

            if ($moduleId < 1) {
                continue;
            }

            $title = SourceValue::text(SourceValue::firstOf($moduleRow, ['title', 'name']));
            $modules[] = ModuleDraft::of(
                sprintf('module:%d', $moduleId),
                $title === '' ? sprintf('Module %d', $moduleId) : $title,
                SourceValue::nullable(SourceValue::firstOf($moduleRow, ['description', 'summary'])),
                $this->lessons($run, $byModule[$moduleId] ?? [], $lessonKeysById),
            );
        }

        return $modules;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string>         $lessonKeysById
     *
     * @return list<LessonDraft>
     */
    private function lessons(SourceRun $run, array $rows, array &$lessonKeysById): array
    {
        $lessons = [];

        foreach ($rows as $row) {
            $lessonId = SourceValue::integer($row['id'] ?? null);

            if ($lessonId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $this->source->lessonsTable(),
                    'lesson:?',
                    'id',
                    SourceValue::text(SourceValue::firstOf($row, ['title', 'name'])),
                    'the lesson row carries no id',
                );
                continue;
            }

            $key = sprintf('lesson:%d', $lessonId);
            $lessonKeysById[$lessonId] = $key;
            $title = SourceValue::text(SourceValue::firstOf($row, ['title', 'name']));
            $title = $title === '' ? sprintf('Lesson %d', $lessonId) : $title;
            $format = strtolower(SourceValue::text(SourceValue::firstOf($row, ['format', 'type', 'lesson_type'])));

            if (isset(self::REFUSED_TYPES[$format])) {
                $lessons[] = LessonDraft::refused($key, $title, $format, self::REFUSED_TYPES[$format]);
                continue;
            }

            $free = self::LESSON_TYPES[$format] ?? null;

            if ($free === null) {
                $lessons[] = LessonDraft::refused(
                    $key,
                    $title,
                    $format === '' ? 'unnamed' : $format,
                    'the Shika lesson format is not one this importer knows, and it was not converted into one',
                );
                continue;
            }

            $lessons[] = $free === 'quiz'
                ? $this->quizLesson($run, $key, $title, $lessonId)
                : $this->contentLesson($row, $key, $title, $format, $free);
        }

        return $lessons;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function contentLesson(array $row, string $key, string $title, string $format, string $free): LessonDraft
    {
        $duration = SourceValue::minutesAsSeconds(
            SourceValue::firstOf($row, ['duration', 'length', 'time']),
            Lesson::MAX_DURATION_SECONDS,
        );
        $body = SourceValue::text(SourceValue::firstOf($row, ['content', 'description', 'introtext', 'text']));
        $url = SourceValue::text(SourceValue::firstOf($row, ['url', 'link', 'path', 'file', 'source']));

        if ($free === 'video') {
            $playable = $this->httpUrl($url) ?? $this->httpUrl($body);

            if ($playable === null) {
                return LessonDraft::refused(
                    $key,
                    $title,
                    $format,
                    'the lesson names no http URL to play. Shika video lessons served from the site\'s own'
                        . ' media store cannot be carried across, because an import cannot copy the file',
                );
            }

            return LessonDraft::of($key, $title, $format, 'video', [
                'provider' => $this->provider($playable),
                'url' => $playable,
            ], $duration);
        }

        if ($format === 'document') {
            $link = $this->httpUrl($url);

            if ($link === null) {
                return LessonDraft::refused(
                    $key,
                    $title,
                    $format,
                    'the document has no http URL. The free build serves documents from its own private'
                        . ' storage, which an import cannot copy into',
                );
            }

            return LessonDraft::of($key, $title, $format, 'reading', ['body' => sprintf(
                '<p><a href="%s" rel="noopener">%s</a></p>',
                htmlspecialchars($link, \ENT_QUOTES),
                htmlspecialchars($title, \ENT_QUOTES),
            )], $duration, false, null, 'it becomes a reading lesson linking to the original document');
        }

        if ($body === '') {
            return LessonDraft::refused($key, $title, $format, 'the lesson has no body');
        }

        return LessonDraft::of($key, $title, $format, 'reading', ['body' => $body], $duration);
    }

    private function quizLesson(SourceRun $run, string $key, string $title, int $lessonId): LessonDraft
    {
        try {
            $quizzes = $this->source->quizzes($lessonId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return LessonDraft::refused(
                $key,
                $title,
                'quiz',
                'its quiz could not be read from the Shika quiz tables, whose shape this importer could not'
                    . ' confirm from any published source',
            );
        }

        $quizRow = $quizzes[0] ?? null;

        if ($quizRow === null) {
            return LessonDraft::refused($key, $title, 'quiz', 'the lesson is a quiz with no quiz row behind it');
        }

        $quizId = SourceValue::integer($quizRow['id'] ?? null);
        $quizTitle = SourceValue::text(SourceValue::firstOf($quizRow, ['title', 'name']));

        return LessonDraft::of($key, $title, 'quiz', 'quiz', [], null, false, QuizDraft::of(
            sprintf('quiz:%d', $quizId),
            $quizTitle === '' ? $title : $quizTitle,
            SourceValue::nullable(SourceValue::firstOf($quizRow, ['description', 'intro', 'introtext'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['pass_percentage', 'passing_grade', 'pass_mark', 'pass'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['attempts', 'max_attempts', 'no_of_attempts'])),
            SourceValue::minutesAsSeconds(
                SourceValue::firstOf($quizRow, ['duration', 'time_limit', 'timelimit']),
                Lesson::MAX_DURATION_SECONDS,
            ),
            false,
            $quizId > 0 ? $this->questions($run, $quizId) : [],
        ));
    }

    /**
     * @return list<QuestionDraft>
     */
    private function questions(SourceRun $run, int $quizId): array
    {
        try {
            $rows = $this->source->questions($quizId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return [];
        }

        $questions = [];

        foreach ($rows as $row) {
            $questionId = SourceValue::integer($row['id'] ?? null);

            if ($questionId < 1) {
                continue;
            }

            $key = sprintf('question:%d', $questionId);
            $prompt = SourceValue::text(SourceValue::firstOf($row, ['question', 'title', 'text', 'name']));
            $type = strtolower(SourceValue::text(SourceValue::firstOf($row, ['type', 'question_type', 'qtype'])));
            $named = $type === '' ? 'unnamed' : $type;

            if ($prompt === '') {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $this->source->questionsTable(),
                    $key,
                    'question',
                    '',
                    'the question has no text to ask',
                );
                continue;
            }

            try {
                $options = $this->source->options($questionId);
            } catch (SourceUnreadable $unreadable) {
                $run->warn($unreadable->explanation());
                $options = [];
            }

            $questions[] = $this->question(
                $key,
                $prompt,
                $named,
                $options,
                SourceValue::decimal(SourceValue::firstOf($row, ['points', 'marks', 'score', 'weight'])),
            );
        }

        return $questions;
    }

    /**
     * @param list<array<string, mixed>> $optionRows
     */
    private function question(
        string $key,
        string $prompt,
        string $sourceType,
        array $optionRows,
        float $points,
    ): QuestionDraft {
        $options = [];
        $correct = [];
        $index = 0;

        foreach ($optionRows as $optionRow) {
            $label = SourceValue::text(SourceValue::firstOf($optionRow, ['option', 'title', 'text', 'answer', 'name']));

            if ($label === '') {
                continue;
            }

            $index++;
            $optionKey = 'o' . $index;
            $options[] = ['key' => $optionKey, 'label' => $label];

            if (SourceValue::boolean(SourceValue::firstOf($optionRow, ['correct', 'is_correct', 'iscorrect', 'answer_correct']))) {
                $correct[] = $optionKey;
            }
        }

        if ($options === []) {
            return QuestionDraft::refused($key, $sourceType, 'the question offers no options to choose from');
        }

        if ($correct === []) {
            return QuestionDraft::refused($key, $sourceType, 'the question marks none of its options correct');
        }

        if (\count($options) === 2 && $this->looksBoolean($options)) {
            return QuestionDraft::of($key, $sourceType, 'true_false', [
                'prompt' => $prompt,
                'correct' => strtolower($options[0]['label']) === 'true'
                    ? \in_array($options[0]['key'], $correct, true)
                    : \in_array($options[1]['key'], $correct, true),
            ], $points, 'its true and false options become the free build\'s own boolean question');
        }

        if (\count($correct) > 1) {
            return QuestionDraft::of($key, $sourceType, 'multiple_choice', [
                'prompt' => $prompt,
                'options' => $options,
                'correct' => $correct,
            ], $points);
        }

        return QuestionDraft::of($key, $sourceType, 'single_choice', [
            'prompt' => $prompt,
            'options' => $options,
            'correct' => $correct[0],
        ], $points);
    }

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private function looksBoolean(array $options): bool
    {
        $labels = [strtolower($options[0]['label']), strtolower($options[1]['label'])];
        sort($labels);

        return $labels === ['false', 'true'] || $labels === ['no', 'yes'];
    }

    /**
     * @param array<int, string> $lessonKeysById
     *
     * @return list<LearnerDraft>
     */
    private function learners(SourceRun $run, int $courseId, array $lessonKeysById): array
    {
        try {
            $rows = $this->source->lessonTrack($courseId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return [];
        }

        if ($rows === []) {
            return [];
        }

        $run->warn(sprintf(
            'Shika course %d: its learners were read from "%s" rather than from an enrolment table, whose name'
                . ' this importer could not confirm. A person with a tracking row is treated as having been on'
                . ' the course, which is an inference rather than a record.',
            $courseId,
            $this->source->trackTable(),
        ));

        $completedByUser = [];

        foreach ($rows as $row) {
            $userId = SourceValue::integer(SourceValue::firstOf($row, ['user_id', 'userid']));

            if ($userId < 1) {
                continue;
            }

            $completedByUser[$userId] ??= [];
            $lessonKey = $lessonKeysById[SourceValue::integer(SourceValue::firstOf($row, ['lesson_id', 'lessonid']))] ?? null;

            if ($lessonKey !== null && $this->finished($row)) {
                $completedByUser[$userId][] = $lessonKey;
            }
        }

        $learners = [];

        foreach ($completedByUser as $userId => $completed) {
            $learners[] = LearnerDraft::of('u' . $userId, $userId, null, $completed);
        }

        return $learners;
    }

    /**
     * Whether one tracking row says the lesson was finished. Shika spells it
     * as a status word and as a completion date depending on the release, so
     * both are read.
     *
     * @param array<string, mixed> $row
     */
    private function finished(array $row): bool
    {
        $status = strtolower(SourceValue::text(SourceValue::firstOf($row, ['status', 'lesson_status'])));

        if (\in_array($status, ['completed', 'complete', 'passed', 'finished'], true)) {
            return true;
        }

        return SourceValue::completed(SourceValue::firstOf($row, ['completed', 'completed_date', 'completion_date']));
    }

    private function httpUrl(string $value): ?string
    {
        if (preg_match('#https?://[^\s"\'<>]+#i', $value, $matches) !== 1) {
            return null;
        }

        return ImportNote::excerpt($matches[0], 1000);
    }

    private function provider(string $url): string
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = strtolower((string) (parse_url($url, \PHP_URL_HOST) ?? ''));

        if (str_contains($host, 'youtube.') || str_contains($host, 'youtu.be')) {
            return 'youtube';
        }

        return str_contains($host, 'vimeo.') ? 'vimeo' : 'url';
    }
}
