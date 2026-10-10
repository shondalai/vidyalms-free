<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

/**
 * Every read the LearnDash importer makes, in one place.
 *
 * ## The schema this file was written from
 *
 * LearnDash LMS 4.x, from the vendor's published developer documentation:
 * the `sfwd-courses`, `sfwd-lessons`, `sfwd-topic`, `sfwd-quiz` and
 * `sfwd-question` custom post types, their `postmeta` relations (`course_id`
 * on a lesson, topic or quiz, `lesson_id` on a topic or quiz, `quiz_pro_id`
 * on a quiz, `question_pro_id` on a question), the serialised per post
 * settings arrays `_sfwd-courses`, `_sfwd-lessons` and `_sfwd-quiz`, the
 * ProQuiz tables `learndash_pro_quiz_master` and `learndash_pro_quiz_question`
 * that LearnDash inherited from WP Pro Quiz, and `learndash_user_activity`
 * with its `activity_type` of `course`, `lesson`, `topic`, `quiz` or `access`.
 *
 * **It has not been run against a real LearnDash install.** No LearnDash is
 * installed on either development site and none was installed to write this.
 * See LearnDashImporter's own docblock for what a site owner should check
 * first.
 *
 * ## Why the serialised settings are read the way they are
 *
 * `_sfwd-quiz` and its siblings are PHP serialised arrays written by another
 * plugin, and `answer_data` on a ProQuiz question is a serialised array of
 * `WpProQuiz_Model_AnswerTypes` objects. Nothing here ever reconstructs an
 * object: every unserialise passes `['allowed_classes' => false]`, so a
 * payload naming a class that happens to exist in this process cannot cause
 * that class to be constructed or its `__wakeup` to run. What comes back for
 * an object is `__PHP_Incomplete_Class`, whose private property names arrive
 * mangled as "\0ClassName\0prop"; `properties()` unmangles them and keeps the
 * scalars, which is all any mapping here reads.
 */
final class LearnDashSource extends WordPressSource
{
    public const COURSE = 'sfwd-courses';
    public const LESSON = 'sfwd-lessons';
    public const TOPIC = 'sfwd-topic';
    public const QUIZ = 'sfwd-quiz';

    /** The relation meta keys LearnDash writes on a step. */
    public const META_COURSE = 'course_id';
    public const META_LESSON = 'lesson_id';
    public const META_QUIZ_PRO = 'quiz_pro_id';

    /**
     * The usermeta key LearnDash's own published enrolment functions read,
     * with the course id in it.
     *
     * `learndash_get_course_users_access_from_meta()` and
     * `learndash_get_user_courses_from_meta()` both read
     * `course_{COURSE_ID}_access_from`, which is where a learner's enrolment
     * date lives. It is a format string rather than a key because the course id
     * is part of the key, which is the dynamic-column shape the organisations
     * design names by hand: it is composed from an integer here and never from
     * a caller's string.
     */
    public const META_ACCESS_FROM = 'course_%d_access_from';

    /**
     * The postmeta key that carries a step's secondary course association,
     * which is what LearnDash writes when a step is shared into a second
     * course. The primary association stays in META_COURSE.
     */
    public const META_SHARED_COURSE = 'ld_course_%d';

    /** The per post settings arrays, keyed by the post type they belong to. */
    public const SETTINGS = [
        self::COURSE => '_sfwd-courses',
        self::LESSON => '_sfwd-lessons',
        self::TOPIC => '_sfwd-topic',
        self::QUIZ => '_sfwd-quiz',
    ];

    /** The ProQuiz tables and the activity table, and nothing else. */
    private const TABLE_QUIZ_MASTER = 'learndash_pro_quiz_master';
    private const TABLE_QUIZ_QUESTION = 'learndash_pro_quiz_question';
    private const TABLE_ACTIVITY = 'learndash_user_activity';

    /**
     * The ProQuiz question columns this importer reads, which is the contract
     * with a schema this product does not own.
     *
     * @var list<string>
     */
    private const QUESTION_COLUMNS = [
        'id',
        'quiz_id',
        'sort',
        'title',
        'points',
        'question',
        'answer_type',
        'answer_data',
        'correct_msg',
        'online',
    ];

    /** @var list<string> */
    private const ACTIVITY_COLUMNS = [
        'activity_id',
        'user_id',
        'post_id',
        'course_id',
        'activity_type',
        'activity_status',
        'activity_started',
        'activity_completed',
    ];

    /** LearnDash's own words for what an activity row is about. */
    public const ACTIVITY_COURSE = 'course';
    public const ACTIVITY_ACCESS = 'access';
    public const ACTIVITY_LESSON = 'lesson';
    public const ACTIVITY_TOPIC = 'topic';

    protected function productTables(): array
    {
        return [self::TABLE_QUIZ_MASTER, self::TABLE_QUIZ_QUESTION, self::TABLE_ACTIVITY];
    }

    /**
     * LearnDash is here when it has at least one course and at least one of
     * its own tables is present.
     *
     * Both halves are needed. A site that once had LearnDash and removed it
     * keeps its `sfwd-courses` posts, and a site that deactivated the plugin
     * keeps its tables; neither alone is a LearnDash to migrate, and saying so
     * before a run starts is cheaper than a run that reads half a course.
     */
    public function isInstalled(): bool
    {
        return $this->postCount(self::COURSE) > 0
            && ($this->tableIsPresent(self::TABLE_QUIZ_QUESTION) || $this->tableIsPresent(self::TABLE_ACTIVITY));
    }

    /**
     * What is missing, for the refusal an absent product earns.
     */
    public function absenceReason(): string
    {
        if ($this->postCount(self::COURSE) < 1) {
            return sprintf('no "%s" post exists on this site', self::COURSE);
        }

        return sprintf('neither "%s" nor "%s" is a table on this site', self::TABLE_QUIZ_QUESTION, self::TABLE_ACTIVITY);
    }

    /**
     * The courses the selector picked, oldest first. An empty selector means
     * every course.
     *
     * @param list<int> $courseIds
     *
     * @return list<array<string, mixed>>
     */
    public function courses(array $courseIds): array
    {
        return $courseIds === []
            ? $this->posts(self::COURSE)
            : $this->postsByIds($courseIds, self::COURSE);
    }

    /**
     * The steps of one course of one type, in LearnDash's own order.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(string $postType, int $courseId): array
    {
        $primary = $this->postsWithMeta($postType, self::META_COURSE, (string) $courseId);
        $shared = $this->sharedSteps($postType, $courseId);

        if ($shared === []) {
            return $primary;
        }

        $merged = [];

        foreach ([...$primary, ...$shared] as $row) {
            $id = self::id($row['ID'] ?? null);

            if ($id > 0 && !isset($merged[$id])) {
                $merged[$id] = $row;
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * The meta of many steps at once.
     *
     * @param list<int> $postIds
     *
     * @return array<int, array<string, string>>
     */
    public function stepMeta(array $postIds): array
    {
        return $this->meta($postIds);
    }

    /**
     * One post's settings array, already unserialised into scalars.
     *
     * @param array<string, string> $meta
     *
     * @return array<string, string>
     */
    public function settings(array $meta, string $postType): array
    {
        $key = self::SETTINGS[$postType] ?? '';

        return $key === '' ? [] : self::properties($meta[$key] ?? '');
    }

    /**
     * One ProQuiz master row, which is where LearnDash keeps a quiz's time
     * limit and its repeat allowance.
     *
     * @return array<string, mixed>|null
     */
    public function quizMaster(int $proQuizId): ?array
    {
        if ($proQuizId < 1) {
            return null;
        }

        return $this->database->selectOne(
            $this->read(self::TABLE_QUIZ_MASTER)
                ->select(['id', 'name', 'text', 'time_limit', 'quiz_run_once', 'statistics_on'])
                ->where('id', '=', $proQuizId),
        );
    }

    /**
     * The questions of one ProQuiz quiz, in the author's order.
     *
     * @return list<array<string, mixed>>
     */
    public function quizQuestions(int $proQuizId): array
    {
        if ($proQuizId < 1) {
            return [];
        }

        return $this->database->selectAll(
            $this->read(self::TABLE_QUIZ_QUESTION)
                ->select(self::QUESTION_COLUMNS)
                ->where('quiz_id', '=', $proQuizId)
                ->orderBy('sort')
                ->orderBy('id'),
        );
    }

    /**
     * Every activity row of one course: who has access, and what they have
     * finished.
     *
     * @return list<array<string, mixed>>
     */
    public function activity(int $courseId): array
    {
        if ($courseId < 1) {
            return [];
        }

        return $this->database->selectAll(
            $this->read(self::TABLE_ACTIVITY)
                ->select(self::ACTIVITY_COLUMNS)
                ->where('course_id', '=', $courseId)
                ->orderBy('activity_id'),
        );
    }

    /**
     * The learners LearnDash's own access meta says may take this course.
     *
     * `learndash_get_course_users_access_from_meta()` and
     * `learndash_get_user_courses_from_meta()` are the published functions for
     * "who is enrolled", and both read the usermeta key
     * `course_{COURSE_ID}_access_from`. The activity table alone answers a
     * different question: who has done something. A learner enrolled last week
     * who has not opened the course yet has an access row and no activity row,
     * and was silently left behind by an importer reading only the activity.
     *
     * The key is composed from an integer the caller has already turned into
     * one, and the read is a prefix match rather than an equality on a composed
     * key, for the reason the organisations design gives for the same shape in
     * Tutor: a key composed from a caller's value is how a dynamic column
     * becomes an injection.
     *
     * @return array<int, string> user id to the access-from value LearnDash stored
     */
    public function accessFrom(int $courseId): array
    {
        if ($courseId < 1) {
            return [];
        }

        $key = sprintf(self::META_ACCESS_FROM, $courseId);
        $found = [];

        foreach ($this->userMetaLike('course_') as $userId => $rows) {
            if (isset($rows[$key])) {
                $found[$userId] = $rows[$key];
            }
        }

        return $found;
    }

    /**
     * The courses a step is shared into beyond its primary one.
     *
     * LearnDash's own Shared Course Steps documentation distinguishes the
     * primary association, which is the single `course_id` meta a step carries,
     * from the secondary associations created when the same step is added to
     * another course. Reading `course_id` alone therefore answers with the
     * steps whose primary course is this one, and a course assembled entirely
     * out of steps shared from elsewhere imports with no modules at all and no
     * row problem, because there is nothing there to report a problem about.
     *
     * @return list<array<string, mixed>>
     */
    public function sharedSteps(string $postType, int $courseId): array
    {
        return $this->postsWithMeta($postType, sprintf(self::META_SHARED_COURSE, $courseId), (string) $courseId);
    }
}
