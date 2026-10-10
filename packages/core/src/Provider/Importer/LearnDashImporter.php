<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
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
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\CourseDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\ItemDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\MembershipDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\SectionDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\LearnDashQuestions;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\LearnDashSource;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressCourseImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressImportRun;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressSource;

/**
 * Moves a LearnDash site's courses into VidyaLMS.
 *
 * **This importer was written from LearnDash's published schema documentation
 * and has NOT been verified against a real LearnDash install.** No LearnDash
 * is installed on either development site, none was installed to write it, and
 * every run says so in its own log before it reads a row. What a site owner
 * should check first, in this order:
 *
 * 1. the curriculum order, because LearnDash courses built before the shared
 *    course steps existed can carry a `menu_order` of zero on every step, and
 *    this importer then falls back to post id order;
 * 2. the questions the log lists as skipped, because a course whose
 *    assessments were mostly matrix or essay questions has come across as a
 *    course with mostly empty quizzes;
 * 3. every learner's access before publishing, because an entitlement is what
 *    decides whether somebody can open a course.
 *
 * ## The source
 *
 *   learndash-db:                 every LearnDash course
 *   learndash-db:courses=12,15    those two courses
 *
 * The tables are read through `DatabasePort::fromHostTable()` with the names
 * taken from a closed constant list on `LearnDashSource`, never from the
 * selector or an option, and the adapter resolves them with the host prefix:
 * `posts` is `wp_posts` and `learndash_pro_quiz_question` is
 * `wp_learndash_pro_quiz_question`.
 *
 * ## The shape it maps
 *
 * LearnDash is Course, then Lesson, then Topic; the free build is Course, then
 * Module, then Lesson. So **a LearnDash lesson becomes a module** and its
 * topics become the lessons inside it. A lesson that carries content of its
 * own also contributes the first lesson of its module, under the same title,
 * so nothing an author wrote is lost to the level shift. A quiz becomes a quiz
 * lesson in its lesson's module, or in a final module when it belongs to the
 * course rather than to a lesson.
 *
 * Access comes from `learndash_user_activity`, which is where LearnDash
 * records that a user may take a course and what they have finished. Groups
 * are not read: a LearnDash group is an organisation shaped object and the
 * organisation importers are a different registry.
 */
final class LearnDashImporter extends WordPressCourseImporter
{
    public const KEY = 'learndash';

    /** The source scheme this importer answers to. */
    public const SCHEME = 'learndash-db:';

    private const PRODUCT = 'LearnDash';

    /** The table a question row problem names, which is where the row is. */
    private const QUESTION_TABLE = 'learndash_pro_quiz_question';

    /** The settings key suffix a lesson or topic's video lives under. */
    private const VIDEO_SUFFIX = '_video_url';

    /** The settings key suffix a quiz's pass mark lives under. */
    private const PASS_SUFFIX = 'passingpercentage';

    /** The settings key suffix a quiz's retake allowance lives under. */
    private const REPEATS_SUFFIX = 'repeats';

    /**
     * The settings key suffix that says whether the retake allowance applies
     * at all. LearnDash keeps the switch and the number apart, so the number
     * stays behind when the switch is turned off.
     */
    private const RETRY_SUFFIX = 'retry_restrictions';

    private readonly LearnDashSource $reader;

    public function __construct(
        DatabasePort $database,
        ImportMap $map,
        TranslatorPort $translator,
        CreateCourse $createCourse,
        UpdateCourse $updateCourse,
        AddModule $addModule,
        UpdateModule $updateModule,
        AddLesson $addLesson,
        UpdateLesson $updateLesson,
        CreateQuiz $createQuiz,
        UpdateQuiz $updateQuiz,
        AddQuestion $addQuestion,
        UpdateQuestion $updateQuestion,
        GrantEntitlement $grantEntitlement,
        EnrolLearner $enrolLearner,
        RecordLessonProgress $recordLessonProgress,
    ) {
        parent::__construct(
            $map,
            $translator,
            $createCourse,
            $updateCourse,
            $addModule,
            $updateModule,
            $addLesson,
            $updateLesson,
            $createQuiz,
            $updateQuiz,
            $addQuestion,
            $updateQuestion,
            $grantEntitlement,
            $enrolLearner,
            $recordLessonProgress,
        );

        $this->reader = new LearnDashSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.learndash');
    }

    public function scheme(): string
    {
        return self::SCHEME;
    }

    protected function productName(): string
    {
        return self::PRODUCT;
    }

    protected function source(): WordPressSource
    {
        return $this->reader;
    }

    protected function absenceReason(): string
    {
        return $this->reader->absenceReason();
    }

    /**
     * @param list<int> $courseIds
     *
     * @return list<CourseDraft>
     */
    protected function read(array $courseIds, WordPressImportRun $run): array
    {
        $this->reader->beginRun();
        $drafts = [];

        foreach ($this->reader->courses($courseIds) as $course) {
            $courseId = WordPressSource::id($course['ID'] ?? null);
            $title = WordPressSource::text($course['post_title'] ?? null);

            if ($courseId < 1 || $title === '') {
                $run->problem(
                    LearnDashSource::COURSE,
                    $courseId,
                    'post_title',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'A course with no title cannot be imported.',
                    WordPressSource::text($course['post_name'] ?? null),
                );

                continue;
            }

            try {
                $drafts[] = $this->draft($run, $courseId, $title, $course);
            } catch (\Throwable $failure) {
                $run->problem(
                    LearnDashSource::COURSE,
                    $courseId,
                    null,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->failed(sprintf('LearnDash course %d could not be read: %s', $courseId, ImportProblem::describe($failure)));
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed> $course
     */
    private function draft(WordPressImportRun $run, int $courseId, string $title, array $course): CourseDraft
    {
        $lessons = $this->reader->steps(LearnDashSource::LESSON, $courseId);
        $topics = $this->reader->steps(LearnDashSource::TOPIC, $courseId);
        $quizzes = $this->reader->steps(LearnDashSource::QUIZ, $courseId);

        $meta = $this->reader->stepMeta([
            ...array_map(static fn (array $row): int => WordPressSource::id($row['ID'] ?? null), $lessons),
            ...array_map(static fn (array $row): int => WordPressSource::id($row['ID'] ?? null), $topics),
            ...array_map(static fn (array $row): int => WordPressSource::id($row['ID'] ?? null), $quizzes),
        ]);

        $sections = [];

        foreach ($lessons as $lesson) {
            $lessonId = WordPressSource::id($lesson['ID'] ?? null);
            $items = [];
            $own = $this->item($run, $lesson, LearnDashSource::LESSON, $meta[$lessonId] ?? []);

            if ($own !== null) {
                $items[] = $own;
            }

            foreach ($this->under($topics, $meta, $lessonId) as $topic) {
                $item = $this->item($run, $topic, LearnDashSource::TOPIC, $meta[WordPressSource::id($topic['ID'] ?? null)] ?? []);

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            foreach ($this->under($quizzes, $meta, $lessonId) as $quiz) {
                $items[] = $this->quiz($run, $quiz, $meta[WordPressSource::id($quiz['ID'] ?? null)] ?? []);
            }

            $sections[] = SectionDraft::of(
                (string) $lessonId,
                WordPressSource::text($lesson['post_title'] ?? null) ?: sprintf('Lesson %d', $lessonId),
                $this->nullable($lesson['post_excerpt'] ?? null),
                $items,
            );
        }

        $loose = [];

        foreach ($this->under($quizzes, $meta, 0) as $quiz) {
            $loose[] = $this->quiz($run, $quiz, $meta[WordPressSource::id($quiz['ID'] ?? null)] ?? []);
        }

        if ($loose !== []) {
            $sections[] = SectionDraft::of(
                'course:' . $courseId,
                $this->translator->translate('vidyalms.importer.wordpress.final_section'),
                null,
                $loose,
            );
        }

        return CourseDraft::of(
            (string) $courseId,
            $title,
            $this->nullable($course['post_name'] ?? null),
            $this->nullable($course['post_excerpt'] ?? null),
            $this->nullable($course['post_content'] ?? null),
            $sections,
            $this->memberships($courseId),
        );
    }

    /**
     * The steps whose `lesson_id` names this lesson, or none of them when it
     * names nothing, which is how a course level quiz is found.
     *
     * @param list<array<string, mixed>>        $rows
     * @param array<int, array<string, string>> $meta
     *
     * @return list<array<string, mixed>>
     */
    private function under(array $rows, array $meta, int $lessonId): array
    {
        $found = [];

        foreach ($rows as $row) {
            $id = WordPressSource::id($row['ID'] ?? null);
            $parent = WordPressSource::id($meta[$id][LearnDashSource::META_LESSON] ?? null);

            if ($parent === $lessonId) {
                $found[] = $row;
            }
        }

        return $found;
    }

    /**
     * One lesson or topic as a free lesson, or null when there is nothing in
     * it to carry.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     */
    private function item(WordPressImportRun $run, array $row, string $postType, array $meta): ?ItemDraft
    {
        $id = WordPressSource::id($row['ID'] ?? null);
        $title = WordPressSource::text($row['post_title'] ?? null);
        $settings = $this->reader->settings($meta, $postType);
        $video = $this->videoUrl($settings);
        $body = WordPressSource::text($row['post_content'] ?? null);

        if ($title === '') {
            $run->problem(
                $postType,
                $id,
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                'A lesson with no title cannot be imported.',
                $body,
            );

            return null;
        }

        if ($video !== null) {
            // LearnDash's video URL setting is video progression added to a
            // lesson that keeps its own post_content; it is not a replacement
            // for it. The free video lesson type carries a provider and a URL
            // and nothing else, so the body cannot travel with it, and
            // dropping it in silence made the loss invisible in the log and in
            // the row report alike. It is reported as a warned row that was
            // still written, which is what a RowProblem on a created row is
            // for.
            if ($body !== '') {
                $run->problem(
                    $postType,
                    $id,
                    'post_content',
                    RowProblem::CODE_MISSING_REQUIRED,
                    'The lesson has video progression and its own written content. The free video lesson type'
                        . ' carries the video only, so the written content was not imported: copy it across by'
                        . ' hand if the learner needs it.',
                    $body,
                    RowProblem::OUTCOME_CREATED,
                );
            }

            return ItemDraft::lesson((string) $id, $title, 'video', ['provider' => self::provider($video), 'url' => $video]);
        }

        if ($body === '') {
            $run->problem(
                $postType,
                $id,
                'post_content',
                RowProblem::CODE_MISSING_REQUIRED,
                'The lesson has neither content nor a video, so there is nothing to carry across.',
                $title,
            );

            return null;
        }

        return ItemDraft::lesson((string) $id, $title, 'reading', ['body' => $body]);
    }

    /**
     * One quiz with its questions.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     */
    private function quiz(WordPressImportRun $run, array $row, array $meta): ItemDraft
    {
        $id = WordPressSource::id($row['ID'] ?? null);
        $title = WordPressSource::text($row['post_title'] ?? null);
        $settings = $this->reader->settings($meta, LearnDashSource::QUIZ);
        $proQuizId = WordPressSource::id($meta[LearnDashSource::META_QUIZ_PRO] ?? null);
        $master = $this->reader->quizMaster($proQuizId);
        $timeLimit = WordPressSource::id($master['time_limit'] ?? null);

        return ItemDraft::quiz(
            (string) $id,
            $title === '' ? sprintf('Quiz %d', $id) : $title,
            $this->questions($run, $proQuizId),
            passMark: min(100, WordPressSource::id($this->settingEndingIn($settings, self::PASS_SUFFIX))),
            maxAttempts: $this->attemptCap($settings, $master ?? []),
            timeLimit: $timeLimit > 0 ? $timeLimit : null,
        );
    }

    /**
     * The number of attempts LearnDash actually enforces, or null for
     * unlimited.
     *
     * `sfwd-quiz_repeats` is the retake allowance and it only applies when
     * `sfwd-quiz_retry_restrictions` says the quiz restricts retakes. The
     * number stays behind when the restriction is turned off, so reading it
     * alone imported a cap the source does not enforce: a quiz with unlimited
     * attempts and a stale repeats of 3 came across as four attempts. The
     * ProQuiz master's `quiz_run_once` is the second half of the same
     * question, and it was in the SELECT list and read nowhere: when it is on,
     * the quiz is once only whatever the retake allowance says.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed>  $master
     */
    private function attemptCap(array $settings, array $master): ?int
    {
        if (WordPressSource::id($master['quiz_run_once'] ?? null) > 0) {
            return 1;
        }

        $restricted = strtolower(trim($this->settingEndingIn($settings, self::RETRY_SUFFIX)));

        if (!\in_array($restricted, ['on', 'yes', '1', 'true'], true)) {
            return null;
        }

        $repeats = $this->settingEndingIn($settings, self::REPEATS_SUFFIX);

        return is_numeric($repeats) && (int) $repeats > 0 ? (int) $repeats + 1 : null;
    }

    /**
     * The questions of one quiz, with every type the free build cannot carry
     * recorded as a row problem naming the LearnDash type word.
     *
     * @return list<QuestionDraft>
     */
    private function questions(WordPressImportRun $run, int $proQuizId): array
    {
        $mapped = [];

        foreach ($this->reader->quizQuestions($proQuizId) as $row) {
            $type = WordPressSource::text($row['answer_type'] ?? null);
            $id = WordPressSource::id($row['id'] ?? null);
            $title = WordPressSource::text($row['title'] ?? null);

            if (!LearnDashQuestions::supports($type)) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    'answer_type',
                    RowProblem::CODE_UNMAPPED_ROLE,
                    sprintf('The free build has no question type that carries a LearnDash "%s" question.', $type),
                    $title,
                );
                $run->skippedQuietly('question:' . $type);

                continue;
            }

            try {
                $mapped[] = LearnDashQuestions::map($row);
            } catch (\Throwable $failure) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    'answer_data',
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->skipped(sprintf(
                    'LearnDash question %d ("%s") was not imported: %s.',
                    $id,
                    ImportNote::excerpt($title),
                    ImportProblem::describe($failure),
                ));
            }
        }

        return $mapped;
    }

    /**
     * Who LearnDash says may take this course, and what they have finished.
     *
     * @return list<MembershipDraft>
     */
    private function memberships(int $courseId): array
    {
        $access = [];
        $finished = [];

        foreach ($this->reader->activity($courseId) as $row) {
            $userId = WordPressSource::id($row['user_id'] ?? null);
            $type = WordPressSource::text($row['activity_type'] ?? null);

            if ($userId < 1) {
                continue;
            }

            if ($type === LearnDashSource::ACTIVITY_COURSE || $type === LearnDashSource::ACTIVITY_ACCESS) {
                $access[$userId] = WordPressSource::id($row['activity_id'] ?? null);

                continue;
            }

            if (
                ($type === LearnDashSource::ACTIVITY_LESSON || $type === LearnDashSource::ACTIVITY_TOPIC)
                && WordPressSource::id($row['activity_status'] ?? null) === 1
            ) {
                $finished[$userId][] = (string) WordPressSource::id($row['post_id'] ?? null);
            }
        }

        // The published enrolment record, beside the activity table.
        // learndash_get_course_users_access_from_meta() reads
        // course_{id}_access_from, and reading the activity table alone
        // answered a different question: who has done something. A learner
        // enrolled last week who has not opened the course yet has an access
        // row and no activity row, and was left behind in silence.
        foreach (array_keys($this->reader->accessFrom($courseId)) as $userId) {
            if ($userId > 0 && !isset($access[$userId])) {
                // No activity row, so the source ref is the access meta rather
                // than an activity id. It is a stable key for this course and
                // this learner, which is what the import map needs.
                $access[$userId] = 0;
            }
        }

        $memberships = [];

        foreach ($access as $userId => $activityId) {
            $memberships[] = MembershipDraft::of(
                $activityId > 0
                    ? (string) $activityId
                    : sprintf('access:%d:%d', $courseId, $userId),
                $userId,
                null,
                array_values(array_unique($finished[$userId] ?? [])),
            );
        }

        return $memberships;
    }

    /**
     * The first settings value whose key ends with this suffix. LearnDash
     * writes its settings under keys prefixed with the post type, so a reader
     * that hardcoded the whole key would break on the type it did not expect.
     *
     * @param array<string, string> $settings
     */
    private function settingEndingIn(array $settings, string $suffix): string
    {
        foreach ($settings as $key => $value) {
            if (str_ends_with(strtolower($key), strtolower($suffix))) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $settings
     */
    private function videoUrl(array $settings): ?string
    {
        $url = $this->settingEndingIn($settings, self::VIDEO_SUFFIX);

        return preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }

    private static function provider(string $url): string
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = strtolower((string) (parse_url($url, \PHP_URL_HOST) ?? ''));

        if (str_contains($host, 'youtube.') || str_contains($host, 'youtu.be')) {
            return 'youtube';
        }

        return str_contains($host, 'vimeo.') ? 'vimeo' : 'url';
    }

    private function nullable(mixed $value): ?string
    {
        $text = WordPressSource::text($value);

        return $text === '' ? null : $text;
    }
}
