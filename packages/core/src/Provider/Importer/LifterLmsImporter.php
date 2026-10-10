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
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\LifterLmsQuestions;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\LifterLmsSource;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressCourseImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressImportRun;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressSource;

/**
 * Moves a LifterLMS site's courses into VidyaLMS.
 *
 * **This importer was written from LifterLMS's published schema and its own
 * source, and has NOT been verified against a real LifterLMS install.** No
 * LifterLMS is installed on either development site, none was installed to
 * write it, and every run says so in its own log before it reads a row. What a
 * site owner should check first, in this order:
 *
 * 1. the fill in the blank questions, because where LifterLMS puts the gap has
 *    changed across versions and this importer marks it from the sentence
 *    itself;
 * 2. the enrolments, because LifterLMS keeps them in its own
 *    `lifterlms_user_postmeta` table rather than in `usermeta`, and only rows
 *    whose latest `_status` is `enrolled` come across;
 * 3. the questions the log lists as skipped, which is every picture choice,
 *    scale, upload and code question.
 *
 * ## The source
 *
 *   lifterlms-db:                 every LifterLMS course
 *   lifterlms-db:courses=12,15    those two courses
 *
 * ## The shape it maps
 *
 * LifterLMS is Course, then Section, then Lesson, which is the free build's own
 * shape: a section becomes a module and a lesson becomes a lesson. A lesson
 * that names a quiz contributes a second, quiz lesson straight after it, so the
 * quiz keeps its place in the curriculum and the lesson keeps its content.
 *
 * `llms_notifications` is deliberately not read. An import must not resurrect
 * two year old notifications, and the free build has its own notification spine
 * to send from.
 */
final class LifterLmsImporter extends WordPressCourseImporter
{
    public const KEY = 'lifterlms';

    /** The source scheme this importer answers to. */
    public const SCHEME = 'lifterlms-db:';

    private const PRODUCT = 'LifterLMS';

    /** The table a question row problem names. */
    private const QUESTION_TABLE = 'llms_question';

    /**
     * How far a question group is followed. LifterLMS nests one level; the
     * bound is here so a source that nests deeper stops and says so rather
     * than recursing until the process dies.
     */
    private const MAX_GROUP_DEPTH = 1;

    private readonly LifterLmsSource $reader;

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

        $this->reader = new LifterLmsSource($database);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.lifterlms');
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
        $drafts = [];

        foreach ($this->reader->courses($courseIds) as $course) {
            $courseId = WordPressSource::id($course['ID'] ?? null);
            $title = WordPressSource::text($course['post_title'] ?? null);

            if ($courseId < 1 || $title === '') {
                $run->problem(
                    LifterLmsSource::COURSE,
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
                    LifterLmsSource::COURSE,
                    $courseId,
                    null,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->failed(sprintf(
                    'LifterLMS course %d could not be read: %s',
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
        $lessonIds = [];

        foreach ($this->reader->childrenOf(LifterLmsSource::SECTION, LifterLmsSource::META_PARENT_COURSE, $courseId) as $section) {
            $sectionId = WordPressSource::id($section['ID'] ?? null);
            $lessons = $this->reader->childrenOf(
                LifterLmsSource::LESSON,
                LifterLmsSource::META_PARENT_SECTION,
                $sectionId,
            );
            $meta = $this->reader->postMeta(array_map(
                static fn (array $row): int => WordPressSource::id($row['ID'] ?? null),
                $lessons,
            ));
            $items = [];

            foreach ($this->ordered($lessons, $meta) as $lesson) {
                $lessonId = WordPressSource::id($lesson['ID'] ?? null);
                $lessonIds[] = $lessonId;
                $item = $this->lesson($run, $lesson);

                if ($item !== null) {
                    $items[] = $item;
                }

                $quizId = WordPressSource::id($meta[$lessonId][LifterLmsSource::META_QUIZ] ?? null);

                if ($quizId > 0) {
                    $items[] = $this->quiz($run, $quizId);
                }
            }

            $sections[] = SectionDraft::of(
                (string) $sectionId,
                WordPressSource::text($section['post_title'] ?? null) ?: sprintf('Section %d', $sectionId),
                $this->nullable($section['post_excerpt'] ?? null),
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
            $this->memberships($courseId, $lessonIds),
        );
    }

    /**
     * LifterLMS orders a curriculum with its own `_llms_order` meta rather than
     * with `menu_order`, so the post order the reader returns is re-sorted here
     * and falls back to it when the meta is missing.
     *
     * @param list<array<string, mixed>>        $rows
     * @param array<int, array<string, string>> $meta
     *
     * @return list<array<string, mixed>>
     */
    private function ordered(array $rows, array $meta): array
    {
        usort($rows, static function (array $left, array $right) use ($meta): int {
            $leftId = WordPressSource::id($left['ID'] ?? null);
            $rightId = WordPressSource::id($right['ID'] ?? null);

            return [WordPressSource::id($meta[$leftId][LifterLmsSource::META_ORDER] ?? null), $leftId]
                <=> [WordPressSource::id($meta[$rightId][LifterLmsSource::META_ORDER] ?? null), $rightId];
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function lesson(WordPressImportRun $run, array $row): ?ItemDraft
    {
        $id = WordPressSource::id($row['ID'] ?? null);
        $title = WordPressSource::text($row['post_title'] ?? null);
        $body = WordPressSource::text($row['post_content'] ?? null);

        if ($title === '') {
            $run->problem(
                LifterLmsSource::LESSON,
                $id,
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                'A lesson with no title cannot be imported.',
                $body,
            );

            return null;
        }

        if ($body === '') {
            $run->problem(
                LifterLmsSource::LESSON,
                $id,
                'post_content',
                RowProblem::CODE_MISSING_REQUIRED,
                'The lesson has no content, so there is nothing to carry across.',
                $title,
            );

            return null;
        }

        return ItemDraft::lesson((string) $id, $title, 'reading', ['body' => $body]);
    }

    private function quiz(WordPressImportRun $run, int $quizId): ItemDraft
    {
        $meta = $this->reader->postMeta([$quizId])[$quizId] ?? [];
        $post = $this->reader->quizPost($quizId);
        $title = $post === null ? '' : WordPressSource::text($post['post_title'] ?? null);
        // Both limits are gated, and the gates were not being read. LifterLMS
        // stores `_llms_allowed_attempts` and `_llms_time_limit` beside the
        // `_llms_limit_attempts` and `_llms_limit_time` flags that say whether
        // either is in force, and a stale number sits under a flag that is off
        // for as long as nobody edits the quiz. Importing the number without
        // the flag gives the learner a cap the source does not enforce, which
        // is the kind of change nobody notices until somebody is locked out.
        $attempts = self::gated(
            $meta,
            LifterLmsSource::META_LIMIT_ATTEMPTS,
            LifterLmsSource::META_ALLOWED_ATTEMPTS,
        );
        $limit = self::gated($meta, LifterLmsSource::META_LIMIT_TIME, LifterLmsSource::META_TIME_LIMIT);

        return ItemDraft::quiz(
            (string) $quizId,
            $title === '' ? sprintf('Quiz %d', $quizId) : $title,
            $this->questions($run, $quizId),
            passMark: min(100, WordPressSource::id($meta[LifterLmsSource::META_PASSING_PERCENT] ?? null)),
            maxAttempts: $attempts,
            timeLimit: $limit === null ? null : $limit * 60,
        );
    }

    /**
     * A LifterLMS setting that only applies when its own flag says so.
     *
     * The product writes "yes" and "no" for these flags, so anything that is
     * not an affirmative reads as off. An absent flag reads as off too: a site
     * whose quiz predates the flag has no cap in force, and inventing one from
     * the stale number is the failure this exists to stop.
     *
     * @param array<string, string> $meta
     */
    private static function gated(array $meta, string $flag, string $key): ?int
    {
        $on = strtolower(trim($meta[$flag] ?? ''));

        if (!\in_array($on, ['yes', '1', 'true', 'on'], true)) {
            return null;
        }

        $value = WordPressSource::id($meta[$key] ?? null);

        return $value > 0 ? $value : null;
    }

    /**
     * The questions of one quiz, with every type the free build cannot carry
     * recorded as a row problem naming the LifterLMS type word.
     *
     * @return list<QuestionDraft>
     */
    private function questions(WordPressImportRun $run, int $quizId, int $depth = 0): array
    {
        $mapped = [];
        $rows = $this->reader->questions($quizId);
        $meta = $this->reader->postMeta(array_map(
            static fn (array $row): int => WordPressSource::id($row['ID'] ?? null),
            $rows,
        ));

        foreach ($rows as $row) {
            $id = WordPressSource::id($row['ID'] ?? null);
            $own = $meta[$id] ?? [];
            $type = WordPressSource::text($own[LifterLmsSource::META_QUESTION_TYPE] ?? null);
            $title = WordPressSource::text($row['post_title'] ?? null);

            // A question group is not a question: it is a container whose own
            // children are parented on it rather than on the quiz, so treating
            // it as an unmapped type lost every question inside it with
            // nothing said about them. LifterLMS nests one level, and the
            // depth bound is there because a source that ever nested deeper
            // must stop rather than recurse for ever.
            if ($type === LifterLmsSource::QUESTION_GROUP) {
                if ($depth >= self::MAX_GROUP_DEPTH) {
                    $run->problem(
                        self::QUESTION_TABLE,
                        $id,
                        LifterLmsSource::META_QUESTION_TYPE,
                        RowProblem::CODE_REFERENTIAL_MISS,
                        'The question group is nested deeper than LifterLMS nests them, so its questions were'
                            . ' not followed.',
                        $title,
                    );

                    continue;
                }

                foreach ($this->questions($run, $id, $depth + 1) as $inGroup) {
                    $mapped[] = $inGroup;
                }

                continue;
            }

            if (!LifterLmsQuestions::supports($type)) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    LifterLmsSource::META_QUESTION_TYPE,
                    RowProblem::CODE_UNMAPPED_ROLE,
                    sprintf('The free build has no question type that carries a LifterLMS "%s" question.', $type),
                    $title,
                );
                $run->skippedQuietly('question:' . ($type === '' ? 'unnamed' : $type));

                continue;
            }

            try {
                $mapped[] = LifterLmsQuestions::map($row, $own, $this->reader->choices($id));
            } catch (\Throwable $failure) {
                $run->problem(
                    self::QUESTION_TABLE,
                    $id,
                    LifterLmsSource::META_CHOICE,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->skipped(sprintf(
                    'LifterLMS question %d ("%s") was not imported: %s.',
                    $id,
                    ImportNote::excerpt($title),
                    ImportProblem::describe($failure),
                ));
            }
        }

        return $mapped;
    }

    /**
     * Who LifterLMS says is enrolled on this course, and which of its lessons
     * they have finished.
     *
     * A learner's status is a history rather than a value: LifterLMS appends a
     * `_status` row on every change, so the latest one is the answer and an
     * expired or cancelled learner is not imported.
     *
     * @param list<int> $lessonIds
     *
     * @return list<MembershipDraft>
     */
    private function memberships(int $courseId, array $lessonIds): array
    {
        $status = [];
        $rowIds = [];

        foreach ($this->reader->userPostMeta($courseId) as $row) {
            if (WordPressSource::text($row['meta_key'] ?? null) !== LifterLmsSource::USER_STATUS) {
                continue;
            }

            $userId = WordPressSource::id($row['user_id'] ?? null);

            if ($userId < 1) {
                continue;
            }

            $status[$userId] = strtolower(WordPressSource::text($row['meta_value'] ?? null));
            $rowIds[$userId] = WordPressSource::id($row['id'] ?? null);
        }

        $finished = [];

        foreach ($this->reader->userPostMetaFor($lessonIds) as $lessonId => $rows) {
            foreach ($rows as $row) {
                if (WordPressSource::text($row['meta_key'] ?? null) !== LifterLmsSource::USER_COMPLETE) {
                    continue;
                }

                if (strtolower(WordPressSource::text($row['meta_value'] ?? null)) !== LifterLmsSource::COMPLETE) {
                    continue;
                }

                $finished[WordPressSource::id($row['user_id'] ?? null)][] = (string) $lessonId;
            }
        }

        $memberships = [];

        foreach ($status as $userId => $state) {
            if ($state !== LifterLmsSource::STATUS_ENROLLED) {
                continue;
            }

            $memberships[] = MembershipDraft::of(
                (string) ($rowIds[$userId] ?? $userId),
                $userId,
                null,
                array_values(array_unique($finished[$userId] ?? [])),
            );
        }

        return $memberships;
    }

    private function nullable(mixed $value): ?string
    {
        $text = WordPressSource::text($value);

        return $text === '' ? null : $text;
    }
}
