<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\OSCampus;

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
 * Moves an OSCampus (Joomlashack) site's courses into VidyaLMS.
 *
 * ## UNVERIFIED AGAINST A REAL INSTALL
 *
 * No OSCampus is installed on either development site and none was installed
 * to write this. Everything below was written from the product's published
 * material, and the label says so on the Import screen because a site owner
 * choosing a migration path deserves to know which of them has been run
 * against the real thing.
 *
 * The schema source is the OSCampus component's own install script,
 * `src/admin/sql/install/mysql/install.sql`, which labels itself "OSCampus
 * Database" version 1.0. It confirms `oscampus_courses`, `oscampus_modules`,
 * `oscampus_lessons` and `oscampus_users_lessons` column by column. It
 * contains no quiz tables at all: `oscampus_lessons_quizzes`,
 * `oscampus_lessons_quiz_questions` and `oscampus_lessons_quiz_answers` are
 * later Pro tables whose shape is published nowhere, so they are read whole
 * and their fields are picked out by name with alternatives.
 *
 * **What a site owner should check first**, in this order:
 *
 * 1. Run it as a dry run and read the log. Every table it could not read is
 *    named there with the columns it wanted.
 * 2. The questions. The quiz tables are the unconfirmed half, and the
 *    question type is inferred from the answers when the source's own type
 *    word is not one this importer knows: one correct answer is a single
 *    choice, several is a multiple choice, and a pair of true and false is a
 *    true or false question. Check a quiz of each kind before trusting the
 *    rest.
 * 3. The lesson durations. `oscampus_lessons.length` carries no comment in the
 *    install script, but its sibling `oscampus_courses`.`length` in the same
 *    script is commented "Time to take course in minutes", so it is read here
 *    as minutes. Check one lesson's duration against the original.
 * 4. The lesson types. Anything this importer does not recognise is refused
 *    by name rather than converted, so the log lists exactly what needs
 *    rebuilding.
 *
 * ## What comes across
 *
 * Courses, their modules and their lessons, the quizzes hanging off a lesson
 * and the questions of those quizzes, and, when the options ask for them, the
 * people who were on a course and the lessons they had finished.
 *
 * Everything is written through the same use cases the authoring UI calls.
 * Nothing writes a table directly.
 *
 * ## What does not
 *
 * Pathways, teachers, tags, certificates, files and Wistia download records
 * have no free equivalent and are not read at all. Quiz scores and attempt
 * history are deliberately left behind: replaying them would date every
 * result to the import.
 */
final class OSCampusImporter extends JoomlaSourceImporter
{
    public const KEY = 'oscampus';

    /**
     * The OSCampus lesson type words this importer knows, and the free lesson
     * type each becomes.
     *
     * OSCampus resolves a lesson's `type` through its own lesson type
     * plugins, so the set is open on a real site and this table is the part of
     * it the published material names. Anything outside it is refused by name
     * rather than guessed at, because a lesson silently turned into an empty
     * reading is worse than a lesson the log tells you to rebuild.
     *
     * @var array<string, string>
     */
    private const LESSON_TYPES = [
        'text' => 'reading',
        'article' => 'reading',
        'html' => 'reading',
        'content' => 'reading',
        'video' => 'video',
        'wistia' => 'video',
        'youtube' => 'video',
        'vimeo' => 'video',
        'quiz' => 'quiz',
    ];

    private readonly OSCampusSource $source;

    public function __construct(
        DatabasePort $database,
        private readonly TranslatorPort $translator,
        CurriculumWriter $writer,
    ) {
        parent::__construct($writer);
        $this->source = new OSCampusSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.oscampus');
    }

    public function supports(string $sourcePath): bool
    {
        return $this->claimsScheme($sourcePath) && $this->source->has(OSCampusSource::T_COURSES);
    }

    protected function translator(): TranslatorPort
    {
        return $this->translator;
    }

    protected function productName(): string
    {
        return 'OSCampus';
    }

    protected function markerTable(): string
    {
        return OSCampusSource::T_COURSES;
    }

    protected function installed(): bool
    {
        return $this->source->has(OSCampusSource::T_COURSES);
    }

    /**
     * Which table each level of a course came out of, so a row problem on a
     * question names the question table and not the course one.
     *
     * OSCampus has no enrolment table in its published schema, so a learner
     * problem is reported against `oscampus_users_lessons`, which is where the
     * evidence that somebody was on the course actually lives.
     */
    private static function tables(): SourceTables
    {
        return SourceTables::of(
            OSCampusSource::T_COURSES,
            OSCampusSource::T_MODULES,
            OSCampusSource::T_LESSONS,
            OSCampusSource::T_QUIZZES,
            OSCampusSource::T_QUIZ_QUESTIONS,
            OSCampusSource::T_USERS_LESSONS,
        );
    }

    protected function unverifiedNote(): string
    {
        return 'This OSCampus importer was written from the component\'s published install script, which'
            . ' documents the course, module, lesson and progress tables and carries no quiz tables at all.'
            . ' It has not been verified against a real OSCampus install. Read the log for any table it could'
            . ' not read, and check one quiz of each question type before trusting the rest.';
    }

    protected function importCourses(SourceRun $run, array $courseIds): void
    {
        $courses = $this->source->courses($courseIds);

        if ($courses === []) {
            $run->note('No OSCampus courses matched this source.');

            return;
        }

        foreach ($courses as $row) {
            $courseId = SourceValue::integer($row['id'] ?? null);

            if ($courseId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    OSCampusSource::T_COURSES,
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
                    OSCampusSource::T_COURSES,
                    sprintf('OSCampus course %d: %s', $courseId, $unreadable->explanation()),
                );
                continue;
            } catch (\Throwable $failure) {
                $run->failed(OSCampusSource::T_COURSES, sprintf(
                    'OSCampus course %d could not be read: %s',
                    $courseId,
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            $this->writer->write(self::KEY, self::tables(), $run, $draft);
        }
    }

    /**
     * @param array<string, mixed> $row an oscampus_courses row
     */
    private function course(SourceRun $run, array $row, int $courseId): CourseDraft
    {
        $title = SourceValue::text($row['title'] ?? null);

        if ($title === '') {
            $title = sprintf('OSCampus course %d', $courseId);
        }

        $modules = [];
        $lessonKeysById = [];

        foreach ($this->source->modules($courseId) as $moduleRow) {
            $moduleId = SourceValue::integer($moduleRow['id'] ?? null);

            if ($moduleId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    OSCampusSource::T_MODULES,
                    'module:?',
                    'id',
                    SourceValue::text($moduleRow['title'] ?? null),
                    'the module row carries no id',
                );
                continue;
            }

            $moduleTitle = SourceValue::text($moduleRow['title'] ?? null);
            $modules[] = ModuleDraft::of(
                sprintf('module:%d', $moduleId),
                $moduleTitle === '' ? sprintf('Module %d', $moduleId) : $moduleTitle,
                null,
                $this->lessons($run, $moduleId, $lessonKeysById),
            );
        }

        return CourseDraft::of(
            sprintf('course:%d', $courseId),
            $title,
            SourceValue::nullable($row['introtext'] ?? null),
            SourceValue::nullable($row['description'] ?? null),
            SourceValue::nullable($row['difficulty'] ?? null),
            SourceValue::integer($row['access'] ?? null) > 1 ? CourseVisibility::Unlisted : CourseVisibility::Public,
            SourceValue::integer($row['published'] ?? null) === 1,
            $modules,
            $this->learners($run, $lessonKeysById),
        );
    }

    /**
     * @param array<int, string> $lessonKeysById filled with every lesson this course carries
     *
     * @return list<LessonDraft>
     */
    private function lessons(SourceRun $run, int $moduleId, array &$lessonKeysById): array
    {
        $lessons = [];

        foreach ($this->source->lessons($moduleId) as $row) {
            $lessonId = SourceValue::integer($row['id'] ?? null);

            if ($lessonId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    OSCampusSource::T_LESSONS,
                    'lesson:?',
                    'id',
                    SourceValue::text($row['title'] ?? null),
                    'the lesson row carries no id',
                );
                continue;
            }

            $key = sprintf('lesson:%d', $lessonId);
            $lessonKeysById[$lessonId] = $key;
            $title = SourceValue::text($row['title'] ?? null);
            $title = $title === '' ? sprintf('Lesson %d', $lessonId) : $title;
            $sourceType = strtolower(SourceValue::text($row['type'] ?? null));
            $free = self::LESSON_TYPES[$sourceType] ?? null;

            if ($free === null) {
                $lessons[] = LessonDraft::refused(
                    $key,
                    $title,
                    $sourceType === '' ? 'unnamed' : $sourceType,
                    'the OSCampus lesson type has no free equivalent and was not converted into one',
                );
                continue;
            }

            $lessons[] = $free === 'quiz'
                ? $this->quizLesson($run, $row, $key, $title, $lessonId)
                : $this->contentLesson($row, $key, $title, $sourceType, $free);
        }

        return $lessons;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function contentLesson(
        array $row,
        string $key,
        string $title,
        string $sourceType,
        string $free,
    ): LessonDraft {
        $content = SourceValue::text($row['content'] ?? null);
        // Minutes, on the product's own word. The install script this importer
        // was written from declares `oscampus_courses`.`length` as
        // "Time to take course in minutes" and gives `oscampus_lessons`.`length`
        // no comment at all, and a sibling column of the same name in the same
        // product settles the unit better than a guess does. Read as seconds, a
        // thirty minute lesson was written as thirty seconds.
        $duration = SourceValue::minutesAsSeconds($row['length'] ?? null, Lesson::MAX_DURATION_SECONDS);

        if ($free === 'video') {
            $url = $this->firstUrl($content);

            if ($url === null) {
                return LessonDraft::refused(
                    $key,
                    $title,
                    $sourceType,
                    'the lesson carries no http URL to play, so there is nothing for a video lesson to point at',
                );
            }

            return LessonDraft::of($key, $title, $sourceType, 'video', [
                'provider' => $this->provider($url),
                'url' => $url,
            ], $duration);
        }

        if ($content === '') {
            $content = SourceValue::text($row['description'] ?? null);
        }

        if ($content === '') {
            return LessonDraft::refused($key, $title, $sourceType, 'the lesson has no body');
        }

        return LessonDraft::of($key, $title, $sourceType, 'reading', ['body' => $content], $duration);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quizLesson(SourceRun $run, array $row, string $key, string $title, int $lessonId): LessonDraft
    {
        try {
            $quizzes = $this->source->quizzes($lessonId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return LessonDraft::refused(
                $key,
                $title,
                'quiz',
                'its quiz could not be read from the OSCampus quiz tables, whose shape this importer could'
                    . ' not confirm from any published source',
            );
        }

        $quizRow = $quizzes[0] ?? null;

        if ($quizRow === null) {
            return LessonDraft::refused($key, $title, 'quiz', 'the lesson is a quiz with no quiz row behind it');
        }

        $quizId = SourceValue::integer($quizRow['id'] ?? null);
        $quizTitle = SourceValue::text(SourceValue::firstOf($quizRow, ['title', 'name']));
        $questions = $quizId > 0 ? $this->questions($run, $quizId) : [];

        return LessonDraft::of($key, $title, 'quiz', 'quiz', [], null, false, QuizDraft::of(
            sprintf('quiz:%d', $quizId),
            $quizTitle === '' ? $title : $quizTitle,
            SourceValue::nullable(SourceValue::firstOf($quizRow, ['description', 'introtext', 'intro'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['pass', 'pass_score', 'passing_score', 'pass_mark'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['attempts', 'max_attempts', 'attempts_allowed'])),
            SourceValue::minutesAsSeconds(
                SourceValue::firstOf($quizRow, ['time_limit', 'timelimit', 'duration']),
                Lesson::MAX_DURATION_SECONDS,
            ),
            false,
            $questions,
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
            $prompt = SourceValue::text(SourceValue::firstOf($row, ['title', 'question', 'text', 'name']));
            $sourceType = strtolower(SourceValue::text(SourceValue::firstOf($row, ['type', 'question_type']) ?? ''));

            if ($prompt === '') {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    OSCampusSource::T_QUIZ_QUESTIONS,
                    $key,
                    'title',
                    '',
                    'the question has no text to ask',
                );
                continue;
            }

            try {
                $answers = $this->source->answers($questionId);
            } catch (SourceUnreadable $unreadable) {
                $run->warn($unreadable->explanation());
                $answers = [];
            }

            $questions[] = $this->question(
                $key,
                $prompt,
                $sourceType,
                $answers,
                SourceValue::decimal(SourceValue::firstOf($row, ['points', 'score', 'weight'])),
            );
        }

        return $questions;
    }

    /**
     * One question, from its own type word when this importer knows it and
     * from the shape of its answers when it does not.
     *
     * The inference is the honest answer to an unconfirmed schema. OSCampus's
     * own type vocabulary is not published, so a question with one correct
     * answer among several is read as a single choice, one with several as a
     * multiple choice, and a pair of true and false as a boolean. It is
     * written down here so a maintainer with a real install can correct it in
     * one place.
     *
     * @param list<array<string, mixed>> $answers
     */
    private function question(
        string $key,
        string $prompt,
        string $sourceType,
        array $answers,
        float $points,
    ): QuestionDraft {
        $options = [];
        $correct = [];
        $index = 0;

        foreach ($answers as $answer) {
            $label = SourceValue::text(SourceValue::firstOf($answer, ['title', 'answer', 'text', 'name']));

            if ($label === '') {
                continue;
            }

            $index++;
            $optionKey = 'a' . $index;
            $options[] = ['key' => $optionKey, 'label' => $label];

            if (SourceValue::boolean(SourceValue::firstOf($answer, ['correct', 'is_correct', 'iscorrect', 'right']))) {
                $correct[] = $optionKey;
            }
        }

        $named = $sourceType === '' ? 'unnamed' : $sourceType;

        if ($options === []) {
            return QuestionDraft::refused($key, $named, 'the question offers no answers to choose from');
        }

        if ($correct === []) {
            return QuestionDraft::refused($key, $named, 'the question marks none of its answers correct');
        }

        if ($this->isBoolean($sourceType, $options)) {
            return QuestionDraft::of($key, $named, 'true_false', [
                'prompt' => $prompt,
                'correct' => strtolower($options[0]['label']) === 'true'
                    ? \in_array($options[0]['key'], $correct, true)
                    : \in_array($options[1]['key'], $correct, true),
            ], $points, 'its true and false answers become the free build\'s own boolean question');
        }

        if (\count($correct) > 1) {
            return QuestionDraft::of($key, $named, 'multiple_choice', [
                'prompt' => $prompt,
                'options' => $options,
                'correct' => $correct,
            ], $points);
        }

        return QuestionDraft::of($key, $named, 'single_choice', [
            'prompt' => $prompt,
            'options' => $options,
            'correct' => $correct[0],
        ], $points);
    }

    /**
     * @param list<array{key: string, label: string}> $options
     */
    private function isBoolean(string $sourceType, array $options): bool
    {
        if (\in_array($sourceType, ['boolean', 'truefalse', 'true_false', 'tf'], true)) {
            return \count($options) === 2;
        }

        if (\count($options) !== 2) {
            return false;
        }

        $labels = [strtolower($options[0]['label']), strtolower($options[1]['label'])];
        sort($labels);

        return $labels === ['false', 'true'];
    }

    /**
     * The people OSCampus recorded on these lessons, and what they finished.
     *
     * There is no enrolment table in the published schema, so a row in
     * `oscampus_users_lessons` is the only evidence that somebody was on the
     * course, and it is treated as exactly that. A person who opened one
     * lesson had the course; whether they finished it is the `completed`
     * column's business.
     *
     * @param array<int, string> $lessonKeysById
     *
     * @return list<LearnerDraft>
     */
    private function learners(SourceRun $run, array $lessonKeysById): array
    {
        if ($lessonKeysById === []) {
            return [];
        }

        try {
            $rows = $this->source->usersLessons(array_keys($lessonKeysById));
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return [];
        }

        $completedByUser = [];

        foreach ($rows as $row) {
            $userId = SourceValue::integer($row['users_id'] ?? null);

            if ($userId < 1) {
                continue;
            }

            $completedByUser[$userId] ??= [];
            $lessonKey = $lessonKeysById[SourceValue::integer($row['lessons_id'] ?? null)] ?? null;

            if ($lessonKey !== null && SourceValue::completed($row['completed'] ?? null)) {
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
     * The first http URL in a lesson's content, which is where OSCampus's
     * video lesson types keep the thing to play.
     */
    private function firstUrl(string $content): ?string
    {
        if (preg_match('#https?://[^\s"\'<>]+#i', $content, $matches) !== 1) {
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
