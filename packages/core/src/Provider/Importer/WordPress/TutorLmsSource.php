<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

/**
 * Every read the Tutor LMS importer makes, in one place.
 *
 * ## The schema this file was written from
 *
 * Tutor LMS 2.x, from the plugin's own published source and developer
 * documentation: the `courses`, `topics`, `lesson`, `tutor_quiz` and
 * `tutor_enrolled` custom post types, whose hierarchy is carried in
 * `post_parent` rather than in meta (a topic's parent is its course, a lesson
 * or quiz's parent is its topic, and an enrolment post's parent is the course
 * with the student in `post_author`); the `tutor_quiz_questions` and
 * `tutor_quiz_question_answers` tables; the serialised `tutor_quiz_option`
 * post meta carrying `passing_grade`, `attempts_allowed` and the
 * `time_limit` pair; the serialised `_video` post meta; and the
 * `_tutor_completed_lesson_id_{lessonId}` user meta a completion writes.
 *
 * **It has not been run against a real Tutor LMS install.** No Tutor LMS is
 * installed on either development site and none was installed to write this.
 * See TutorLmsImporter's own docblock for what a site owner should check
 * first.
 *
 * The post type word `courses` is generic enough that another plugin could
 * own it, so detection never rests on it alone.
 */
final class TutorLmsSource extends WordPressSource
{
    public const COURSE = 'courses';
    public const TOPIC = 'topics';
    public const LESSON = 'lesson';
    public const QUIZ = 'tutor_quiz';
    public const ENROLMENT = 'tutor_enrolled';

    /** The post status Tutor writes on an enrolment that is actually granted. */
    public const ENROLLED = 'completed';

    /** The serialised meta a quiz keeps its run settings in. */
    public const META_QUIZ_OPTION = 'tutor_quiz_option';

    /** The serialised meta a lesson keeps its video in. */
    public const META_VIDEO = '_video';

    /** The user meta prefix a finished lesson is recorded under. */
    public const META_COMPLETED_LESSON = '_tutor_completed_lesson_id_';

    private const TABLE_QUESTIONS = 'tutor_quiz_questions';
    private const TABLE_ANSWERS = 'tutor_quiz_question_answers';

    /** @var list<string> */
    private const QUESTION_COLUMNS = [
        'question_id',
        'quiz_id',
        'question_title',
        'question_description',
        'question_type',
        'question_mark',
        'question_order',
    ];

    /** @var list<string> */
    private const ANSWER_COLUMNS = [
        'answer_id',
        'belongs_question_id',
        'belongs_question_type',
        'answer_title',
        'is_correct',
        'answer_two_gap_match',
        'answer_order',
    ];

    /** The option Tutor writes its version into. */
    private const OPTION_VERSION = 'tutor_version';

    protected function productTables(): array
    {
        return [self::TABLE_QUESTIONS, self::TABLE_ANSWERS];
    }

    /**
     * Tutor is here when its question table is present and either its version
     * option is set or the site really does have `courses` posts.
     */
    public function isInstalled(): bool
    {
        return $this->tableIsPresent(self::TABLE_QUESTIONS)
            && ($this->option(self::OPTION_VERSION) !== '' || $this->postCount(self::COURSE) > 0);
    }

    public function absenceReason(): string
    {
        if (!$this->tableIsPresent(self::TABLE_QUESTIONS)) {
            return sprintf('"%s" is not a table on this site', self::TABLE_QUESTIONS);
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
     * The children of one post, which is how Tutor carries its whole tree.
     *
     * @return list<array<string, mixed>>
     */
    public function children(string $postType, int $parentId): array
    {
        if ($parentId < 1) {
            return [];
        }

        $rows = $this->database->selectAll(
            $this->read(self::TABLE_POSTS)
                ->select(self::POST_COLUMNS)
                ->where('post_parent', '=', $parentId)
                ->where('post_type', '=', $postType)
                ->orderBy('menu_order')
                ->orderBy('ID'),
        );

        return $rows;
    }

    /**
     * Every enrolment post of one course. The student is the post author and
     * the state is the post status, which is Tutor's own shape.
     *
     * @return list<array<string, mixed>>
     */
    public function enrolments(int $courseId): array
    {
        return $this->children(self::ENROLMENT, $courseId);
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
     * The questions of one quiz, in the author's order.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(int $quizId): array
    {
        if ($quizId < 1) {
            return [];
        }

        return $this->database->selectAll(
            $this->read(self::TABLE_QUESTIONS)
                ->select(self::QUESTION_COLUMNS)
                ->where('quiz_id', '=', $quizId)
                ->orderBy('question_order')
                ->orderBy('question_id'),
        );
    }

    /**
     * The answers of one question, in the author's order.
     *
     * @return list<array<string, mixed>>
     */
    public function answers(int $questionId): array
    {
        if ($questionId < 1) {
            return [];
        }

        return $this->database->selectAll(
            $this->read(self::TABLE_ANSWERS)
                ->select(self::ANSWER_COLUMNS)
                ->where('belongs_question_id', '=', $questionId)
                ->orderBy('answer_order')
                ->orderBy('answer_id'),
        );
    }

    /**
     * Which lessons each learner has finished, as userId => lesson id list.
     *
     * Tutor writes one user meta row per finished lesson whose key carries the
     * lesson id. That is the dynamic-column anti-pattern the organisations
     * design names by hand, and it is exactly why this is read with a prefix
     * match and never composed into a key from a caller's value.
     *
     * @return array<int, list<int>>
     */
    public function completedLessons(): array
    {
        $found = [];

        foreach ($this->userMetaLike(self::META_COMPLETED_LESSON) as $userId => $rows) {
            foreach (array_keys($rows) as $key) {
                $lessonId = self::id(substr($key, \strlen(self::META_COMPLETED_LESSON)));

                if ($lessonId > 0) {
                    $found[$userId][] = $lessonId;
                }
            }
        }

        return $found;
    }
}
