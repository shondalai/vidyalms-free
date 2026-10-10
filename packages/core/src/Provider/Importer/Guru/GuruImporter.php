<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Guru;

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
 * Moves a Guru (iJoomla, com_guru) site's courses into VidyaLMS.
 *
 * ## UNVERIFIED AGAINST A REAL INSTALL, AND THE MOST SPECULATIVE OF THE THREE
 *
 * No Guru is installed on either development site and none was installed to
 * write this. Guru is a commercial component whose install script is not
 * published, and unlike Shika it does not publish a content vocabulary that
 * matches its own column names either. What its documentation does publish is
 * the structure its screens use, Categories then Courses then Modules then
 * Lessons, with quizzes and final exams as assessments and orders as the
 * record of who bought what, and that is the structure this importer maps.
 *
 * The tables carry the product's older words. A course is a `guru_program`, a
 * module is a `guru_days` row, a lesson is a `guru_tasks` row. Every column
 * this importer reads is a documented guess: the reads select every column
 * and the fields are picked out by name with alternatives, so one wrong guess
 * costs a field rather than a table.
 *
 * **What a site owner should check first**, in this order:
 *
 * 1. Run it as a dry run and read the log. Every table it could not read is
 *    named there.
 * 2. The course structure. If `guru_days` cannot be read, every lesson lands
 *    in one module named after the course.
 * 3. The questions, which are the most speculative part of the most
 *    speculative importer. Guru stores a question's answers in the question
 *    row rather than in a table of their own as far as this importer can
 *    tell, so the answers are read out of whichever of several likely columns
 *    carries them, and a question whose answers cannot be read is refused by
 *    name rather than imported empty.
 * 4. The learners. Access in Guru is a purchase, so a `guru_buy` row is what
 *    this importer reads as access, resolving the buyer through
 *    `guru_customer` when the purchase names a customer rather than a Joomla
 *    user.
 *
 * ## What does not come across
 *
 * Guru's own commerce, its subscriptions and its coupons stay where they are:
 * a purchase becomes access, not an order, because the free build's commerce
 * seams belong to whatever sells the course here now. Certificates, final
 * exams as a distinct object, and any learner history beyond access are not
 * read, and there is no progress table in what this importer could confirm,
 * so no completion is claimed for anybody.
 */
final class GuruImporter extends JoomlaSourceImporter
{
    public const KEY = 'guru';

    /**
     * The Guru lesson type words this importer knows, and the free lesson
     * type each becomes.
     *
     * Guru's documentation names the content a lesson may carry as "video,
     * text, document, quiz, URL, file or other type of media", and these are
     * those words as a column is likely to spell them. Anything else is
     * refused by name.
     *
     * @var array<string, string>
     */
    private const LESSON_TYPES = [
        'text' => 'reading',
        'html' => 'reading',
        'article' => 'reading',
        'content' => 'reading',
        'url' => 'reading',
        'document' => 'reading',
        'video' => 'video',
        'media' => 'video',
        'quiz' => 'quiz',
    ];

    /**
     * The columns a question's answers might be in, in the order this mapper
     * prefers them. Guru keeps them with the question rather than in a table
     * of their own as far as the published material shows.
     *
     * @var list<string>
     */
    private const ANSWER_COLUMNS = ['answers', 'answer', 'options', 'params', 'question_answers'];

    private readonly GuruSource $source;

    public function __construct(
        DatabasePort $database,
        private readonly TranslatorPort $translator,
        CurriculumWriter $writer,
    ) {
        parent::__construct($writer);
        $this->source = new GuruSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.guru');
    }

    public function supports(string $sourcePath): bool
    {
        return $this->claimsScheme($sourcePath) && $this->source->has(GuruSource::T_PROGRAM);
    }

    protected function translator(): TranslatorPort
    {
        return $this->translator;
    }

    protected function productName(): string
    {
        return 'Guru';
    }

    protected function markerTable(): string
    {
        return GuruSource::T_PROGRAM;
    }

    protected function installed(): bool
    {
        return $this->source->has(GuruSource::T_PROGRAM);
    }

    /**
     * Which table each level of a course came out of, under the product's own
     * older words: a course is a program, a module is a day, a lesson is a
     * task, and access is a purchase.
     */
    private static function tables(): SourceTables
    {
        return SourceTables::of(
            GuruSource::T_PROGRAM,
            GuruSource::T_DAYS,
            GuruSource::T_TASKS,
            GuruSource::T_QUIZ,
            GuruSource::T_QUESTIONS,
            GuruSource::T_BUY,
        );
    }

    protected function unverifiedNote(): string
    {
        return 'This Guru importer was written from iJoomla\'s public documentation, which publishes the'
            . ' product\'s content structure and no schema at all. It has not been verified against a real'
            . ' Guru install, and it is the most speculative of the three Joomla importers: every column it'
            . ' reads is a documented guess. Read the log for any table it could not read, and check the'
            . ' questions and the learners before trusting anything else.';
    }

    protected function importCourses(SourceRun $run, array $courseIds): void
    {
        $programs = $this->source->programs($courseIds);

        if ($programs === []) {
            $run->note('No Guru courses matched this source.');

            return;
        }

        foreach ($programs as $row) {
            $programId = SourceValue::integer($row['id'] ?? null);

            if ($programId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    GuruSource::T_PROGRAM,
                    'course:?',
                    'id',
                    '',
                    'the course row carries no id',
                );
                continue;
            }

            try {
                $draft = $this->course($run, $row, $programId);
            } catch (SourceUnreadable $unreadable) {
                $run->failed(
                    GuruSource::T_PROGRAM,
                    sprintf('Guru course %d: %s', $programId, $unreadable->explanation()),
                );
                continue;
            } catch (\Throwable $failure) {
                $run->failed(GuruSource::T_PROGRAM, sprintf(
                    'Guru course %d could not be read: %s',
                    $programId,
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            $this->writer->write(self::KEY, self::tables(), $run, $draft);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function course(SourceRun $run, array $row, int $programId): CourseDraft
    {
        $title = SourceValue::text(SourceValue::firstOf($row, ['name', 'title', 'program_name']));
        $title = $title === '' ? sprintf('Guru course %d', $programId) : $title;

        return CourseDraft::of(
            sprintf('course:%d', $programId),
            $title,
            SourceValue::nullable(SourceValue::firstOf($row, ['intro', 'introtext', 'short_description'])),
            SourceValue::nullable(SourceValue::firstOf($row, ['description', 'fulltext', 'body'])),
            SourceValue::nullable(SourceValue::firstOf($row, ['difficulty', 'level'])),
            SourceValue::integer(SourceValue::firstOf($row, ['access'])) > 1
                ? CourseVisibility::Unlisted
                : CourseVisibility::Public,
            SourceValue::integer(SourceValue::firstOf($row, ['published', 'state'])) === 1,
            $this->modules($run, $programId, $title),
            $this->learners($run, $programId),
        );
    }

    /**
     * @return list<ModuleDraft>
     */
    private function modules(SourceRun $run, int $programId, string $courseTitle): array
    {
        try {
            $days = $this->source->days($programId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());
            $days = [];
        }

        if ($days === []) {
            $run->warn(sprintf(
                'Course "%s": no Guru module rows could be read for it, so it was imported with one empty'
                    . ' module named after the course. Its lessons hang off its modules in Guru, so an'
                    . ' unreadable module table means an empty course here rather than a flattened one.',
                ImportNote::excerpt($courseTitle),
            ));

            return [ModuleDraft::of(sprintf('module:course:%d', $programId), $courseTitle, null, [])];
        }

        $modules = [];

        foreach ($days as $day) {
            $dayId = SourceValue::integer($day['id'] ?? null);

            if ($dayId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    GuruSource::T_DAYS,
                    'module:?',
                    'id',
                    SourceValue::text(SourceValue::firstOf($day, ['name', 'title'])),
                    'the module row carries no id',
                );
                continue;
            }

            $title = SourceValue::text(SourceValue::firstOf($day, ['name', 'title']));
            $modules[] = ModuleDraft::of(
                sprintf('module:%d', $dayId),
                $title === '' ? sprintf('Module %d', $dayId) : $title,
                SourceValue::nullable(SourceValue::firstOf($day, ['description', 'intro'])),
                $this->lessons($run, $dayId),
            );
        }

        return $modules;
    }

    /**
     * @return list<LessonDraft>
     */
    private function lessons(SourceRun $run, int $dayId): array
    {
        try {
            $tasks = $this->source->tasks($dayId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return [];
        }

        $lessons = [];

        foreach ($tasks as $row) {
            $taskId = SourceValue::integer($row['id'] ?? null);

            if ($taskId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    GuruSource::T_TASKS,
                    'lesson:?',
                    'id',
                    SourceValue::text(SourceValue::firstOf($row, ['name', 'title'])),
                    'the lesson row carries no id',
                );
                continue;
            }

            $key = sprintf('lesson:%d', $taskId);
            $title = SourceValue::text(SourceValue::firstOf($row, ['name', 'title']));
            $title = $title === '' ? sprintf('Lesson %d', $taskId) : $title;
            $type = strtolower(SourceValue::text(SourceValue::firstOf($row, ['type', 'task_type', 'media_type'])));
            $free = self::LESSON_TYPES[$type] ?? null;

            if ($free === null) {
                $lessons[] = LessonDraft::refused(
                    $key,
                    $title,
                    $type === '' ? 'unnamed' : $type,
                    'the Guru lesson type is not one this importer knows, and it was not converted into one',
                );
                continue;
            }

            $lessons[] = $free === 'quiz'
                ? $this->quizLesson($run, $key, $title, $taskId)
                : $this->contentLesson($row, $key, $title, $type, $free);
        }

        return $lessons;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function contentLesson(array $row, string $key, string $title, string $type, string $free): LessonDraft
    {
        $duration = SourceValue::minutesAsSeconds(
            SourceValue::firstOf($row, ['duration', 'length', 'time']),
            Lesson::MAX_DURATION_SECONDS,
        );
        $body = SourceValue::text(SourceValue::firstOf($row, ['description', 'content', 'body', 'text']));
        $link = SourceValue::text(SourceValue::firstOf($row, ['url', 'link', 'media', 'file', 'path']));

        if ($free === 'video') {
            $playable = $this->httpUrl($link) ?? $this->httpUrl($body);

            if ($playable === null) {
                return LessonDraft::refused(
                    $key,
                    $title,
                    $type,
                    'the lesson names no http URL to play, so there is nothing for a video lesson to point at',
                );
            }

            return LessonDraft::of($key, $title, $type, 'video', [
                'provider' => $this->provider($playable),
                'url' => $playable,
            ], $duration);
        }

        if ($type === 'url' || $type === 'document') {
            $href = $this->httpUrl($link) ?? $this->httpUrl($body);

            if ($href === null) {
                return LessonDraft::refused(
                    $key,
                    $title,
                    $type,
                    'the lesson names no http URL. The free build serves files from its own private storage,'
                        . ' which an import cannot copy into',
                );
            }

            return LessonDraft::of($key, $title, $type, 'reading', ['body' => sprintf(
                '<p><a href="%s" rel="noopener">%s</a></p>',
                htmlspecialchars($href, \ENT_QUOTES),
                htmlspecialchars($title, \ENT_QUOTES),
            )], $duration, false, null, 'it becomes a reading lesson linking to the original');
        }

        if ($body === '') {
            return LessonDraft::refused($key, $title, $type, 'the lesson has no body');
        }

        return LessonDraft::of($key, $title, $type, 'reading', ['body' => $body], $duration);
    }

    private function quizLesson(SourceRun $run, string $key, string $title, int $taskId): LessonDraft
    {
        try {
            $quizzes = $this->source->quizzes($taskId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return LessonDraft::refused(
                $key,
                $title,
                'quiz',
                'its quiz could not be read from the Guru quiz tables, whose shape this importer could not'
                    . ' confirm from any published source',
            );
        }

        $quizRow = $quizzes[0] ?? null;

        if ($quizRow === null) {
            return LessonDraft::refused($key, $title, 'quiz', 'the lesson is a quiz with no quiz row behind it');
        }

        $quizId = SourceValue::integer($quizRow['id'] ?? null);
        $quizTitle = SourceValue::text(SourceValue::firstOf($quizRow, ['name', 'title']));
        $isFinal = SourceValue::boolean(SourceValue::firstOf($quizRow, ['is_final', 'final', 'final_exam']));

        return LessonDraft::of($key, $title, 'quiz', 'quiz', [], null, false, QuizDraft::of(
            sprintf('quiz:%d', $quizId),
            $quizTitle === '' ? $title : $quizTitle,
            SourceValue::nullable(SourceValue::firstOf($quizRow, ['description', 'intro', 'introtext'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['pass_score', 'passing_score', 'pass', 'minimum_score'])),
            SourceValue::integer(SourceValue::firstOf($quizRow, ['attempts', 'retakes', 'max_attempts'])),
            SourceValue::minutesAsSeconds(
                SourceValue::firstOf($quizRow, ['time_limit', 'duration', 'timer']),
                Lesson::MAX_DURATION_SECONDS,
            ),
            $isFinal,
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
            $prompt = SourceValue::text(SourceValue::firstOf($row, ['question', 'name', 'title', 'text']));
            $type = strtolower(SourceValue::text(SourceValue::firstOf($row, ['type', 'question_type'])));
            $named = $type === '' ? 'unnamed' : $type;

            if ($prompt === '') {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    GuruSource::T_QUESTIONS,
                    $key,
                    'question',
                    '',
                    'the question has no text to ask',
                );
                continue;
            }

            $questions[] = $this->question(
                $key,
                $prompt,
                $named,
                $this->answers($row),
                SourceValue::decimal(SourceValue::firstOf($row, ['points', 'score', 'weight'])),
            );
        }

        return $questions;
    }

    /**
     * The answers of one question, out of whichever column carries them.
     *
     * Guru keeps them beside the question rather than in a table of their own
     * as far as the published material shows, and it has stored structured
     * columns as JSON and as serialised Joomla registry data at different
     * times. Only JSON is read: unserialising a foreign string is a remote
     * code execution waiting for a badly configured site, and no migration is
     * worth that. A question whose answers cannot be read is refused by name,
     * never imported empty.
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{text: string, correct: bool}>
     */
    private function answers(array $row): array
    {
        $decoded = SourceValue::decode(SourceValue::firstOf($row, self::ANSWER_COLUMNS));
        $answers = [];

        /** @var mixed $entry */
        foreach ($decoded as $key => $entry) {
            if (\is_array($entry)) {
                $text = SourceValue::text(SourceValue::firstOf($entry, ['text', 'answer', 'title', 'label', 'value']));
                $correct = SourceValue::boolean(
                    SourceValue::firstOf($entry, ['correct', 'is_correct', 'iscorrect', 'right']),
                );
            } else {
                // The flat shape: {"Paris": 1, "Rome": 0}, which is how a
                // component that grew its answers out of a form post writes
                // them.
                $text = SourceValue::text($key);
                $correct = SourceValue::boolean($entry);
            }

            if ($text !== '') {
                $answers[] = ['text' => $text, 'correct' => $correct];
            }
        }

        return $answers;
    }

    /**
     * @param list<array{text: string, correct: bool}> $answers
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
            $index++;
            $optionKey = 'a' . $index;
            $options[] = ['key' => $optionKey, 'label' => $answer['text']];

            if ($answer['correct']) {
                $correct[] = $optionKey;
            }
        }

        if ($options === []) {
            return QuestionDraft::refused(
                $key,
                $sourceType,
                'its answers could not be read out of the Guru question row, so the question was not imported'
                    . ' rather than imported without them',
            );
        }

        if ($correct === []) {
            return QuestionDraft::refused($key, $sourceType, 'the question marks none of its answers correct');
        }

        if (\count($options) === 2 && $this->looksBoolean($options)) {
            return QuestionDraft::of($key, $sourceType, 'true_false', [
                'prompt' => $prompt,
                'correct' => strtolower($options[0]['label']) === 'true' || strtolower($options[0]['label']) === 'yes'
                    ? \in_array($options[0]['key'], $correct, true)
                    : \in_array($options[1]['key'], $correct, true),
            ], $points, 'its two answers become the free build\'s own boolean question');
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
     * Who had this course in Guru, which is who bought it.
     *
     * No completion is claimed for anybody: there is no progress table in
     * what this importer could confirm, so every learner comes across with
     * access and no history rather than with an invented one.
     *
     * @return list<LearnerDraft>
     */
    private function learners(SourceRun $run, int $programId): array
    {
        try {
            $rows = $this->source->purchases($programId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return [];
        }

        $learners = [];
        $seen = [];

        foreach ($rows as $row) {
            $purchaseId = SourceValue::integer($row['id'] ?? null);
            $userId = SourceValue::integer(SourceValue::firstOf($row, ['user_id', 'userid', 'juser_id']));

            if ($userId < 1) {
                $userId = $this->buyer($run, SourceValue::integer(
                    SourceValue::firstOf($row, ['customer_id', 'customerid', 'cid']),
                ));
            }

            if ($userId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    GuruSource::T_BUY,
                    sprintf('purchase:%d', $purchaseId),
                    'user_id',
                    '',
                    'the purchase names no Joomla account, so there is nobody to give access to',
                );
                continue;
            }

            if (isset($seen[$userId])) {
                // Guru sells a course more than once to one person, through a
                // renewal or a second order. Access is access: the second row
                // is not a second learner, and creating one would be a
                // duplicate entitlement rather than a fact.
                $run->skippedQuietly('repeat-purchase', GuruSource::T_BUY);
                continue;
            }

            $seen[$userId] = true;
            $learners[] = LearnerDraft::of(
                'p' . ($purchaseId > 0 ? $purchaseId : $userId),
                $userId,
                SourceValue::datetime(SourceValue::firstOf($row, ['expires', 'expiry_date', 'expires_at'])),
            );
        }

        return $learners;
    }

    /**
     * The Joomla account behind one Guru customer row, or 0 when there is
     * none this importer can find.
     */
    private function buyer(SourceRun $run, int $customerId): int
    {
        if ($customerId < 1) {
            return 0;
        }

        try {
            $customer = $this->source->customer($customerId);
        } catch (SourceUnreadable $unreadable) {
            $run->warn($unreadable->explanation());

            return 0;
        }

        return $customer === null
            ? 0
            : SourceValue::integer(SourceValue::firstOf($customer, ['user_id', 'userid', 'juser_id']));
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
