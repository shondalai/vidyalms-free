<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\OSCampus;

use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\HostSource;

/**
 * Every read the OSCampus importer makes, in one place.
 *
 * ## The schema this was written from
 *
 * The curriculum and progress tables are taken from the OSCampus component's
 * own published install script, `src/admin/sql/install/mysql/install.sql` of
 * the OSCampus repository, which the file itself labels "OSCampus Database"
 * version 1.0. Those tables are named below as CONFIRMED and their columns
 * are listed explicitly, because a listed column is the contract with a
 * foreign schema.
 *
 * The quiz tables are not in that script. `oscampus_lessons_quizzes`,
 * `oscampus_lessons_quiz_questions` and `oscampus_lessons_quiz_answers`
 * belong to the later OSCampus Pro releases, whose schema is not published
 * anywhere this importer's author could read, and the same is true of
 * `oscampus_courses_progress` and `oscampus_lessons_progress`. Those five are
 * marked UNCONFIRMED, are read with a wildcard select, and have their keys
 * pulled out by name with alternatives. Writing a guessed column name into a
 * SELECT list would turn one wrong guess into the loss of the whole question
 * bank.
 *
 * OSCampus names its foreign keys in the plural after the table they point
 * at, which is unusual enough to be worth stating: a lesson's course link is
 * `modules_id`, not `module_id`. The unconfirmed reads assume that convention
 * continues into the quiz tables and accept the singular as an alternative.
 */
final class OSCampusSource extends HostSource
{
    // CONFIRMED against the published install script.
    public const T_COURSES = 'oscampus_courses';
    public const T_MODULES = 'oscampus_modules';
    public const T_LESSONS = 'oscampus_lessons';
    public const T_USERS_LESSONS = 'oscampus_users_lessons';

    // UNCONFIRMED: named by the product but with no published shape.
    public const T_QUIZZES = 'oscampus_lessons_quizzes';
    public const T_QUIZ_QUESTIONS = 'oscampus_lessons_quiz_questions';
    public const T_QUIZ_ANSWERS = 'oscampus_lessons_quiz_answers';
    public const T_COURSES_PROGRESS = 'oscampus_courses_progress';
    public const T_LESSONS_PROGRESS = 'oscampus_lessons_progress';

    /** @var list<string> */
    private const COURSE_COLUMNS = [
        'id',
        'title',
        'alias',
        'introtext',
        'description',
        'difficulty',
        'published',
        'access',
        'released',
    ];

    /** @var list<string> */
    private const MODULE_COLUMNS = ['id', 'courses_id', 'title', 'ordering'];

    /** @var list<string> */
    private const LESSON_COLUMNS = [
        'id',
        'modules_id',
        'title',
        'alias',
        'type',
        'content',
        'description',
        'length',
        'published',
        'ordering',
    ];

    /** @var list<string> */
    private const USERS_LESSONS_COLUMNS = ['id', 'users_id', 'lessons_id', 'completed', 'score', 'visits'];

    /**
     * The tables this importer may read, and the only ones it may read.
     *
     * @return list<string>
     */
    public static function tableNames(): array
    {
        return [
            self::T_COURSES,
            self::T_MODULES,
            self::T_LESSONS,
            self::T_USERS_LESSONS,
            self::T_QUIZZES,
            self::T_QUIZ_QUESTIONS,
            self::T_QUIZ_ANSWERS,
            self::T_COURSES_PROGRESS,
            self::T_LESSONS_PROGRESS,
        ];
    }

    /**
     * The courses, oldest first. An empty filter means every course.
     *
     * @param list<int> $courseIds
     *
     * @return list<array<string, mixed>>
     */
    public function courses(array $courseIds): array
    {
        $query = $this->read(self::T_COURSES)->select(self::COURSE_COLUMNS)->orderBy('id');

        if ($courseIds !== []) {
            $query->whereIn('id', $courseIds);
        }

        return $this->all($query, self::COURSE_COLUMNS);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function modules(int $courseId): array
    {
        return $this->all(
            $this->read(self::T_MODULES)
                ->select(self::MODULE_COLUMNS)
                ->where('courses_id', '=', $courseId)
                ->orderBy('ordering')
                ->orderBy('id'),
            self::MODULE_COLUMNS,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lessons(int $moduleId): array
    {
        return $this->all(
            $this->read(self::T_LESSONS)
                ->select(self::LESSON_COLUMNS)
                ->where('modules_id', '=', $moduleId)
                ->orderBy('ordering')
                ->orderBy('id'),
            self::LESSON_COLUMNS,
        );
    }

    /**
     * Everything OSCampus recorded about people on these lessons: that they
     * were there at all, and which lessons they completed.
     *
     * There is no enrolment table in the published schema, so this is both
     * halves. A person with a row here had the course.
     *
     * @param list<int> $lessonIds
     *
     * @return list<array<string, mixed>>
     */
    public function usersLessons(array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }

        return $this->all(
            $this->read(self::T_USERS_LESSONS)
                ->select(self::USERS_LESSONS_COLUMNS)
                ->whereIn('lessons_id', $lessonIds)
                ->orderBy('id'),
            self::USERS_LESSONS_COLUMNS,
        );
    }

    /**
     * UNCONFIRMED. The quizzes of one lesson, read whole.
     *
     * @return list<array<string, mixed>>
     */
    public function quizzes(int $lessonId): array
    {
        return $this->children(self::T_QUIZZES, ['lessons_id', 'lesson_id'], $lessonId);
    }

    /**
     * UNCONFIRMED. The questions of one quiz, read whole.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(int $quizId): array
    {
        return $this->children(self::T_QUIZ_QUESTIONS, ['quizzes_id', 'quiz_id'], $quizId);
    }

    /**
     * UNCONFIRMED. The offered answers of one question, read whole.
     *
     * @return list<array<string, mixed>>
     */
    public function answers(int $questionId): array
    {
        return $this->children(self::T_QUIZ_ANSWERS, ['questions_id', 'question_id'], $questionId);
    }

    /**
     * @return list<string>
     */
    protected function tables(): array
    {
        return self::tableNames();
    }

    /**
     * The rows of an unconfirmed table that belong to one parent, tried
     * against each foreign key spelling in turn.
     *
     * The first spelling is the convention the confirmed half of the schema
     * uses; the second is the ordinary Joomla one. Only the last failure
     * escapes, because a WHERE on a column that is not there is exactly the
     * evidence that the other spelling is the right one, and the caller wants
     * to be told once, not twice.
     *
     * @param list<string> $foreignKeys in the order this mapper prefers them
     *
     * @return list<array<string, mixed>>
     */
    private function children(string $table, array $foreignKeys, int $parentId): array
    {
        $last = \count($foreignKeys) - 1;

        foreach ($foreignKeys as $index => $column) {
            try {
                return $this->ordered($this->all(
                    $this->read($table)->select(['*'])->where($column, '=', $parentId),
                    [$column],
                ));
            } catch (\Throwable $failure) {
                if ($index === $last) {
                    throw $failure;
                }
            }
        }

        return [];
    }
}
