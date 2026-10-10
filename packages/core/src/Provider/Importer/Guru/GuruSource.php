<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Guru;

use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\HostSource;

/**
 * Every read the Guru importer makes, in one place.
 *
 * ## The schema this was written from, and what could not be confirmed
 *
 * Guru (com_guru, by iJoomla) is a commercial component and its install
 * script is not published. Its documentation publishes the vocabulary its
 * screens use, Categories then Courses then Modules then Lessons, with
 * Quizzes and Final Exams and Orders beside them, and its tables carry the
 * older names the product grew up with: a course is a `guru_program`, a
 * module is a `guru_days` row, a lesson is a `guru_tasks` row. **No column of
 * any Guru table could be confirmed from a published source**, so every read
 * here selects every column and the mapper picks its fields out by name with
 * alternatives.
 *
 * That is the most speculative of the three Joomla source importers and it is
 * labelled as such everywhere it appears. It is still worth shipping: a read
 * that finds a column it does not expect loses that field and says so, and a
 * table it cannot read at all loses that table and says so, so the worst case
 * is a migration that reports honestly rather than one that writes nonsense.
 */
final class GuruSource extends HostSource
{
    /** A Guru course. Its table keeps the product's older word for it. */
    public const T_PROGRAM = 'guru_program';

    /** A Guru module. */
    public const T_DAYS = 'guru_days';

    /** A Guru lesson. */
    public const T_TASKS = 'guru_tasks';

    public const T_QUIZ = 'guru_quiz';
    public const T_QUESTIONS = 'guru_questions';

    /** A purchase of a course, which is what access means in Guru. */
    public const T_BUY = 'guru_buy';

    /** The buyer, when the purchase names a customer rather than a user. */
    public const T_CUSTOMER = 'guru_customer';

    /**
     * @return list<string>
     */
    public static function tableNames(): array
    {
        return [
            self::T_PROGRAM,
            self::T_DAYS,
            self::T_TASKS,
            self::T_QUIZ,
            self::T_QUESTIONS,
            self::T_BUY,
            self::T_CUSTOMER,
        ];
    }

    /**
     * @param list<int> $courseIds
     *
     * @return list<array<string, mixed>>
     */
    public function programs(array $courseIds): array
    {
        $query = $this->read(self::T_PROGRAM)->select(['*'])->orderBy('id');

        if ($courseIds !== []) {
            $query->whereIn('id', $courseIds);
        }

        return $this->all($query, ['*']);
    }

    /**
     * The modules of a course. Guru's foreign key to a program has been
     * spelled several ways across its releases, so each is tried in turn.
     *
     * @return list<array<string, mixed>>
     */
    public function days(int $programId): array
    {
        return $this->ordered($this->children(self::T_DAYS, ['program_id', 'pid', 'programid'], $programId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tasks(int $dayId): array
    {
        return $this->ordered($this->children(self::T_TASKS, ['day_id', 'did', 'dayid'], $dayId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function quizzes(int $taskId): array
    {
        return $this->ordered($this->children(self::T_QUIZ, ['task_id', 'tid', 'taskid'], $taskId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function questions(int $quizId): array
    {
        return $this->ordered($this->children(self::T_QUESTIONS, ['quiz_id', 'qid', 'quizid'], $quizId));
    }

    /**
     * Who bought this course.
     *
     * @return list<array<string, mixed>>
     */
    public function purchases(int $programId): array
    {
        return $this->children(self::T_BUY, ['program_id', 'pid', 'course_id'], $programId);
    }

    /**
     * The Joomla account behind one Guru customer row, when a purchase names
     * a customer rather than a user directly.
     *
     * @return array<string, mixed>|null
     */
    public function customer(int $customerId): ?array
    {
        return $this->one($this->read(self::T_CUSTOMER)->select(['*'])->where('id', '=', $customerId), ['*']);
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
     * against each foreign key spelling in turn. Only the last failure
     * escapes: a WHERE on a column that is not there is the evidence that
     * another spelling is the right one.
     *
     * @param list<string> $foreignKeys
     *
     * @return list<array<string, mixed>>
     */
    private function children(string $table, array $foreignKeys, int $parentId): array
    {
        $last = \count($foreignKeys) - 1;

        foreach ($foreignKeys as $index => $column) {
            try {
                return $this->all($this->read($table)->select(['*'])->where($column, '=', $parentId), [$column]);
            } catch (\Throwable $failure) {
                if ($index === $last) {
                    throw $failure;
                }
            }
        }

        return [];
    }
}
