<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

/**
 * Every read the LifterLMS importer makes, in one place.
 *
 * ## The schema this file was written from
 *
 * LifterLMS 7.x, from the plugin's own published source and its developer
 * documentation: the `course`, `section`, `lesson`, `llms_quiz` and
 * `llms_question` custom post types; the `_llms_parent_course`,
 * `_llms_parent_section` and `_llms_order` post meta that carry the
 * curriculum; `_llms_parent_id`, which is the `parent_id` property
 * `LLMS_Question` publishes and is how a question names its quiz or its
 * question group; `_llms_quiz` on a lesson and `_llms_lesson_id` on a quiz,
 * which are the two halves of the same link; the quiz settings meta
 * `_llms_passing_percent`, `_llms_allowed_attempts` and `_llms_time_limit`
 * with the `_llms_limit_attempts` and `_llms_limit_time` flags that gate
 * them;
 * a question's `_llms_question_type` and `_llms_points` with its repeated
 * `_llms_choice` meta, one serialised choice per row carrying `id`, `choice`,
 * `choice_type`, `correct` and `marker`; and the `lifterlms_user_postmeta`
 * table, whose `_status` and `_is_complete` rows are where enrolment and
 * completion live rather than in `usermeta`.
 *
 * `llms_notifications` is deliberately out of scope: an import must not
 * resurrect two year old notifications, and the free build has its own
 * notification spine to send from.
 *
 * **It has not been run against a real LifterLMS install.** No LifterLMS is
 * installed on either development site and none was installed to write this.
 * See LifterLmsImporter's own docblock for what a site owner should check
 * first.
 */
final class LifterLmsSource extends WordPressSource
{
    public const COURSE = 'course';
    public const SECTION = 'section';
    public const LESSON = 'lesson';
    public const QUIZ = 'llms_quiz';
    public const QUESTION = 'llms_question';

    public const META_PARENT_COURSE = '_llms_parent_course';
    public const META_PARENT_SECTION = '_llms_parent_section';

    /**
     * The parent a question names, which for a question is its quiz and for a
     * question inside a group is the group.
     *
     * It is the `parent_id` property `LLMS_Question` publishes as an
     * `LLMS_Post_Model` property, which is stored as this meta key. Reading
     * `post_parent` instead was reading a column the product does not publish
     * as the link.
     */
    public const META_PARENT_ID = '_llms_parent_id';

    /** The question type LifterLMS uses for a group of questions. */
    public const QUESTION_GROUP = 'group';
    public const META_ORDER = '_llms_order';
    public const META_QUIZ = '_llms_quiz';
    public const META_QUESTION_TYPE = '_llms_question_type';
    public const META_POINTS = '_llms_points';
    public const META_MULTI_CHOICES = '_llms_multi_choices';
    public const META_CHOICE = '_llms_choice';
    public const META_PASSING_PERCENT = '_llms_passing_percent';
    public const META_ALLOWED_ATTEMPTS = '_llms_allowed_attempts';
    public const META_TIME_LIMIT = '_llms_time_limit';

    /**
     * The two flags that say whether the two limits above are in force.
     *
     * LifterLMS keeps the number and the switch apart, so a quiz can carry a
     * stale `_llms_allowed_attempts` under an `_llms_limit_attempts` of "no"
     * for as long as nobody edits it. Reading the number without the flag
     * imports a cap the source does not enforce.
     */
    public const META_LIMIT_ATTEMPTS = '_llms_limit_attempts';
    public const META_LIMIT_TIME = '_llms_limit_time';
    public const META_VIDEO = '_llms_video_embed';

    /** The one LifterLMS table this importer reads. */
    private const TABLE_USER_POSTMETA = 'lifterlms_user_postmeta';

    /** The user postmeta keys enrolment and completion are written under. */
    public const USER_STATUS = '_status';
    public const USER_COMPLETE = '_is_complete';

    /** The `_status` value that means the learner may take the course. */
    public const STATUS_ENROLLED = 'enrolled';

    /** The `_is_complete` value LifterLMS writes. */
    public const COMPLETE = 'yes';

    /** @var list<string> */
    private const USER_POSTMETA_COLUMNS = ['id', 'user_id', 'post_id', 'meta_key', 'meta_value', 'updated_date'];

    private const OPTION_VERSION = 'lifterlms_version';

    protected function productTables(): array
    {
        return [self::TABLE_USER_POSTMETA];
    }

    /**
     * LifterLMS is here when its own table is present and either its version
     * option is set or the site really does have `course` posts. The post type
     * word is generic, so it never decides this alone.
     */
    public function isInstalled(): bool
    {
        return $this->tableIsPresent(self::TABLE_USER_POSTMETA)
            && ($this->option(self::OPTION_VERSION) !== '' || $this->postCount(self::COURSE) > 0);
    }

    public function absenceReason(): string
    {
        if (!$this->tableIsPresent(self::TABLE_USER_POSTMETA)) {
            return sprintf('"%s" is not a table on this site', self::TABLE_USER_POSTMETA);
        }

        return sprintf('the "%s" option is unset and no "%s" post exists', self::OPTION_VERSION, self::COURSE);
    }

    /**
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
     * The sections of one course, or the lessons of one section, both of which
     * LifterLMS carries as a parent id in meta.
     *
     * @return list<array<string, mixed>>
     */
    public function childrenOf(string $postType, string $metaKey, int $parentId): array
    {
        return $parentId < 1 ? [] : $this->postsWithMeta($postType, $metaKey, (string) $parentId);
    }

    /**
     * The questions of one quiz.
     *
     * The published link is the meta, not the column. `LLMS_Question` is an
     * `LLMS_Post_Model` whose reference declares `'parent_id' => 'absint'`,
     * which is the `_llms_parent_id` post meta, and `get_questions()` is
     * documented as answering "child questions (for question group)". Nothing
     * in the product's own published documentation says `post_parent` carries
     * it, so reading `post_parent` alone found questions only on a site that
     * happens to set it too, and the test fixture seeded `post_parent` itself,
     * which is exactly why the suite could not see this.
     *
     * So the meta is asked first and the column is a fallback, which costs one
     * query on a site that keeps both and is the difference between an
     * imported quiz and an empty one on a site that keeps only the meta.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(int $quizId): array
    {
        if ($quizId < 1) {
            return [];
        }

        $byMeta = $this->postsWithMeta(self::QUESTION, self::META_PARENT_ID, (string) $quizId);
        $byColumn = $this->database->selectAll(
            $this->read(self::TABLE_POSTS)
                ->select(self::POST_COLUMNS)
                ->where('post_parent', '=', $quizId)
                ->where('post_type', '=', self::QUESTION)
                ->orderBy('menu_order')
                ->orderBy('ID'),
        );

        return self::orderedQuestions(self::mergeById($byMeta, $byColumn));
    }

    /**
     * Two lists of post rows, merged on ID with the first list winning.
     *
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $second
     *
     * @return list<array<string, mixed>>
     */
    private static function mergeById(array $first, array $second): array
    {
        $merged = [];

        foreach ([...$first, ...$second] as $row) {
            $id = self::id($row['ID'] ?? null);

            if ($id > 0 && !isset($merged[$id])) {
                $merged[$id] = $row;
            }
        }

        return array_values($merged);
    }

    /**
     * Post rows in the order LifterLMS draws them: `menu_order`, then ID.
     *
     * postsWithMeta() orders by post id, so a merged list has to be put back
     * into the product's own order or a quiz whose questions were reordered
     * imports them as they were first created.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function orderedQuestions(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $order = self::id($left['menu_order'] ?? null) <=> self::id($right['menu_order'] ?? null);

            return $order !== 0 ? $order : self::id($left['ID'] ?? null) <=> self::id($right['ID'] ?? null);
        });

        return $rows;
    }

    /**
     * One quiz post, which a lesson names in its own meta rather than
     * parenting, so it is fetched by id.
     *
     * @return array<string, mixed>|null
     */
    public function quizPost(int $quizId): ?array
    {
        return $this->post($quizId);
    }

    /**
     * One question's choices, each already unserialised into scalars.
     *
     * @return list<array<string, string>>
     */
    public function choices(int $questionId): array
    {
        $choices = [];

        foreach ($this->metaList($questionId, self::META_CHOICE) as $serialised) {
            $choice = self::properties($serialised);

            if ($choice !== []) {
                $choices[] = $choice;
            }
        }

        return $choices;
    }

    /**
     * @param list<int> $postIds
     *
     * @return array<int, array<string, string>>
     */
    public function postMeta(array $postIds): array
    {
        return $this->meta($postIds);
    }

    /**
     * Every user postmeta row about one post, newest last.
     *
     * @return list<array<string, mixed>>
     */
    public function userPostMeta(int $postId): array
    {
        if ($postId < 1) {
            return [];
        }

        return $this->database->selectAll(
            $this->read(self::TABLE_USER_POSTMETA)
                ->select(self::USER_POSTMETA_COLUMNS)
                ->where('post_id', '=', $postId)
                ->orderBy('id'),
        );
    }

    /**
     * Every user postmeta row about a set of posts, as postId => rows.
     *
     * @param list<int> $postIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function userPostMetaFor(array $postIds): array
    {
        $found = [];

        foreach ($this->pages($postIds) as $page) {
            foreach (
                $this->database->selectAll(
                    $this->read(self::TABLE_USER_POSTMETA)
                    ->select(self::USER_POSTMETA_COLUMNS)
                    ->whereIn('post_id', $page)
                    ->orderBy('id'),
                ) as $row
            ) {
                $found[self::id($row['post_id'] ?? null)][] = $row;
            }
        }

        return $found;
    }
}
