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
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\TutorLmsQuestions;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\TutorLmsSource;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressCourseImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressImportRun;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressSource;

/**
 * Moves a Tutor LMS site's courses into VidyaLMS.
 *
 * **This importer was written from Tutor LMS's published schema and its own
 * source, and has NOT been verified against a real Tutor LMS install.** No
 * Tutor LMS is installed on either development site, none was installed to
 * write it, and every run says so in its own log before it reads a row. What a
 * site owner should check first, in this order:
 *
 * 1. the enrolments, because Tutor records one as a post whose status word is
 *    `completed` and whose author is the student, and a site that has ever
 *    changed that convention will import the wrong people or nobody;
 * 2. the fill in the blank questions, because Tutor's gap marker and its
 *    vertical bar separated answers are the one mapping here that rewrites the
 *    text a learner sees;
 * 3. the questions the log lists as skipped, which is every matching, image
 *    and H5P question.
 *
 * ## The source
 *
 *   tutor-db:                 every Tutor course
 *   tutor-db:courses=12,15    those two courses
 *
 * ## The shape it maps
 *
 * Tutor is Course, then Topic, then Lesson or Quiz, carried in `post_parent`
 * rather than in meta. That is the free build's own shape, so a topic becomes
 * a module and its lessons and quizzes become the lessons inside it, in one
 * ordered list so that a quiz keeps its place in the curriculum rather than
 * being swept to the end.
 *
 * Completion comes from the `_tutor_completed_lesson_id_{lessonId}` user meta.
 * That is a key composed from a database id, which this product refuses for its
 * own data and has to read for Tutor's; it is read with a prefix match and the
 * id is parsed out of the key, never composed into one from a value.
 */
final class TutorLmsImporter extends WordPressCourseImporter
{
    public const KEY = 'tutor-lms';

    /** The source scheme this importer answers to. */
    public const SCHEME = 'tutor-db:';

    private const PRODUCT = 'Tutor LMS';

    /** The table a question row problem names. */
    private const QUESTION_TABLE = 'tutor_quiz_questions';

    /** The quiz option keys Tutor writes, inside its serialised settings bag. */
    private const OPTION_PASS = 'passing_grade';
    private const OPTION_ATTEMPTS = 'attempts_allowed';
    private const OPTION_TIME = 'time_limit';

    /** How Tutor spells the unit of a quiz time limit, and what it is in seconds. */
    private const TIME_UNITS = [
        'seconds' => 1,
        'minutes' => 60,
        'hours' => 3600,
        'days' => 86400,
        'weeks' => 604800,
    ];

    private readonly TutorLmsSource $reader;

    /** @var array<int, list<int>>|null the completion map, read once per run */
    private ?array $completed = null;

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

        $this->reader = new TutorLmsSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.tutor_lms');
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
        $this->completed = null;
        $drafts = [];

        foreach ($this->reader->courses($courseIds) as $course) {
            $courseId = WordPressSource::id($course['ID'] ?? null);
            $title = WordPressSource::text($course['post_title'] ?? null);

            if ($courseId < 1 || $title === '') {
                $run->problem(
                    TutorLmsSource::COURSE,
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
                    TutorLmsSource::COURSE,
                    $courseId,
                    null,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->failed(sprintf(
                    'Tutor LMS course %d could not be read: %s',
                    $courseId,
                    ImportProblem::describe($failure),
                ));
            }
        }

        return $drafts;
    }

    /**
     * @param array<string, mixed> $course
     */
    private function draft(WordPressImportRun $run, int $courseId, string $title, array $course): CourseDraft
    {
        $sections = [];

        foreach ($this->reader->children(TutorLmsSource::TOPIC, $courseId) as $topic) {
            $topicId = WordPressSource::id($topic['ID'] ?? null);
            $children = [
                ...$this->reader->children(TutorLmsSource::LESSON, $topicId),
                ...$this->reader->children(TutorLmsSource::QUIZ, $topicId),
            ];

            usort($children, static function (array $left, array $right): int {
                return [WordPressSource::id($left['menu_order'] ?? null), WordPressSource::id($left['ID'] ?? null)]
                    <=> [WordPressSource::id($right['menu_order'] ?? null), WordPressSource::id($right['ID'] ?? null)];
            });

            $meta = $this->reader->postMeta(array_map(
                static fn (array $row): int => WordPressSource::id($row['ID'] ?? null),
                $children,
            ));

            $items = [];

            foreach ($children as $child) {
                $childId = WordPressSource::id($child['ID'] ?? null);
                $item = WordPressSource::text($child['post_type'] ?? null) === TutorLmsSource::QUIZ
                    ? $this->quiz($run, $child, $meta[$childId] ?? [])
                    : $this->lesson($run, $child, $meta[$childId] ?? []);

                if ($item !== null) {
                    $items[] = $item;
                }
            }

            $sections[] = SectionDraft::of(
                (string) $topicId,
                WordPressSource::text($topic['post_title'] ?? null) ?: sprintf('Topic %d', $topicId),
                $this->nullable($topic['post_content'] ?? null),
                $items,
            );
        }

        return CourseDraft::of(
            (string) $courseId,
            $title,
            $this->nullable($course['post_name'] ?? null),
            $this->nullable($course['post_excerpt'] ?? null),
            $this->nullable($course['post_content'] ?? null),
            $sections,
            $this->memberships($courseId, $sections),
        );
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     */
    private function lesson(WordPressImportRun $run, array $row, array $meta): ?ItemDraft
    {
        $id = WordPressSource::id($row['ID'] ?? null);
        $title = WordPressSource::text($row['post_title'] ?? null);
        $body = WordPressSource::text($row['post_content'] ?? null);
        $video = $this->videoUrl($meta);

        if ($title === '') {
            $run->problem(
                TutorLmsSource::LESSON,
                $id,
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                'A lesson with no title cannot be imported.',
                $body,
            );

            return null;
        }

        if ($video !== null) {
            return ItemDraft::lesson((string) $id, $title, 'video', ['provider' => self::provider($video), 'url' => $video]);
        }

        if ($body === '') {
            $run->problem(
                TutorLmsSource::LESSON,
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
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     */
    private function quiz(WordPressImportRun $run, array $row, array $meta): ItemDraft
    {
        $id = WordPressSource::id($row['ID'] ?? null);
        $title = WordPressSource::text($row['post_title'] ?? null);
        $settings = WordPressSource::decode($meta[TutorLmsSource::META_QUIZ_OPTION] ?? '');
        $flat = WordPressSource::flatten($settings);
        $attempts = WordPressSource::id($flat[self::OPTION_ATTEMPTS] ?? null);

        return ItemDraft::quiz(
            (string) $id,
            $title === '' ? sprintf('Quiz %d', $id) : $title,
            $this->questions($run, $id),
            passMark: min(100, WordPressSource::id($flat[self::OPTION_PASS] ?? null)),
            maxAttempts: $attempts > 0 ? $attempts : null,
            timeLimit: $this->timeLimit($settings),
        );
    }

    /**
     * The questions of one quiz, with every type the free build cannot carry
     * recorded as a row problem naming the Tutor type word.
     *
     * @return list<QuestionDraft>
     */
    private function questions(WordPressImportRun $run, int $quizId): array
    {
        $mapped = [];

        foreach ($this->reader->questions($quizId) as $row) {
            $type = WordPressSource::text($row['question_type'] ?? null);
            $id = WordPressSource::id($row['question_id'] ?? null);
            $title = WordPressSource::text($row['question_title'] ?? null);

            if (!TutorLmsQuestions::supports($type)) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    'question_type',
                    RowProblem::CODE_UNMAPPED_ROLE,
                    sprintf('The free build has no question type that carries a Tutor "%s" question.', $type),
                    $title,
                );
                $run->skippedQuietly('question:' . $type);

                continue;
            }

            try {
                $mapped[] = TutorLmsQuestions::map($row, $this->reader->answers($id));
            } catch (\Throwable $failure) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    'answer_title',
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->skipped(sprintf(
                    'Tutor question %d ("%s") was not imported: %s.',
                    $id,
                    ImportNote::excerpt($title),
                    ImportProblem::describe($failure),
                ));
            }
        }

        return $mapped;
    }

    /**
     * Who Tutor says is enrolled on this course, and what they have finished
     * **in this course**.
     *
     * The intersection is the whole point. Tutor records a completion as one
     * user meta row per finished lesson, keyed `_tutor_completed_lesson_id_{id}`
     * with nothing in it naming the course, so a prefix match over usermeta
     * answers with everything that learner has ever finished anywhere on the
     * site. Handing that whole list to a membership draft attached one course's
     * enrolment to another course's history: the writer then either reported a
     * referential miss naming a lesson this run really did import, or, when the
     * lesson had been imported under a different course, failed the row. Both
     * of those were seen in the R4 review, on the same fixture, from opposite
     * ends.
     *
     * LearnDash is not affected, because its activity rows carry course_id;
     * LifterLMS is not, because it reads `_is_complete` per lesson post id.
     *
     * @param list<SectionDraft> $sections
     *
     * @return list<MembershipDraft>
     */
    private function memberships(int $courseId, array $sections): array
    {
        $memberships = [];
        $ours = self::itemIdsOf($sections);

        foreach ($this->reader->enrolments($courseId) as $row) {
            if (WordPressSource::text($row['post_status'] ?? null) !== TutorLmsSource::ENROLLED) {
                continue;
            }

            $userId = WordPressSource::id($row['post_author'] ?? null);
            $memberships[] = MembershipDraft::of(
                (string) WordPressSource::id($row['ID'] ?? null),
                $userId,
                null,
                array_values(array_intersect(
                    array_map(
                        static fn (int $lessonId): string => (string) $lessonId,
                        $this->completedFor($userId),
                    ),
                    $ours,
                )),
            );
        }

        return $memberships;
    }

    /**
     * The source ids of every item this course carries, which is the only set
     * a completion of this course may name.
     *
     * @param list<SectionDraft> $sections
     *
     * @return list<string>
     */
    private static function itemIdsOf(array $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $ids[] = $item->sourceId;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function completedFor(int $userId): array
    {
        if ($this->completed === null) {
            $this->completed = $this->reader->completedLessons();
        }

        return array_values(array_unique($this->completed[$userId] ?? []));
    }

    /**
     * Tutor writes a quiz time limit as a value and a unit word.
     */
    private function timeLimit(mixed $settings): ?int
    {
        if (!\is_array($settings)) {
            return null;
        }

        /** @var mixed $limit */
        $limit = $settings[self::OPTION_TIME] ?? null;
        $flat = WordPressSource::flatten($limit);
        $value = WordPressSource::id($flat['time_value'] ?? null);
        $unit = strtolower(WordPressSource::text($flat['time_type'] ?? null));
        $seconds = self::TIME_UNITS[$unit] ?? 0;

        return $value > 0 && $seconds > 0 ? $value * $seconds : null;
    }

    /**
     * Tutor keeps a lesson's video in one serialised bag whose `source` word
     * names which of the sibling keys holds the address.
     *
     * @param array<string, string> $meta
     */
    private function videoUrl(array $meta): ?string
    {
        $video = WordPressSource::flatten(WordPressSource::decode($meta[TutorLmsSource::META_VIDEO] ?? ''));

        foreach ($video as $key => $value) {
            if (str_starts_with($key, 'source') && preg_match('#^https?://#i', $value) === 1) {
                return $value;
            }
        }

        return null;
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
