<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

/**
 * A Shika, Guru or OSCampus database, small enough to read and faithful
 * enough to prove an importer against.
 *
 * The tables are created under their bare names, which is what a host table is
 * in the harness: SqliteDatabase gives the "vidyalms_" prefix only to tables
 * of ours, so `Query::fromHostTable('guru_program')` reads exactly what this
 * creates.
 *
 * ## What these fixtures are, and what they are not
 *
 * None of the three products is installed on either development site, and none
 * was installed to write the importers. So a fixture here is not a copy of a
 * real schema: it is the shape the importer was written to expect, and a test
 * over it proves the mapping, the idempotency, the upsert policy and the
 * refusals, not that the shape is right. That distinction is the whole reason
 * the importers read unconfirmed tables with a wildcard select and pick their
 * fields out by name: a fixture cannot tell you a column was renamed, and the
 * importer has to survive finding out on a customer's site.
 *
 * Where the product's schema is published, the columns below are that
 * schema's. OSCampus's course, module, lesson and progress tables come from
 * the component's own install script, which labels itself "OSCampus Database"
 * version 1.0. Everything else is the importer's documented expectation.
 */
final class JoomlaSourceFixture
{
    /**
     * OSCampus. The first four are the published schema; the rest are the
     * later quiz tables whose shape is published nowhere.
     *
     * @var array<string, string>
     */
    private const OSCAMPUS = [
        'oscampus_courses' => 'id INTEGER PRIMARY KEY, teachers_id INTEGER NOT NULL DEFAULT 0,'
            . ' difficulty TEXT NOT NULL DEFAULT \'\', length INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', alias TEXT NOT NULL DEFAULT \'\','
            . ' image TEXT NOT NULL DEFAULT \'\', introtext TEXT NOT NULL DEFAULT \'\','
            . ' description TEXT NOT NULL DEFAULT \'\', access INTEGER NOT NULL DEFAULT 1,'
            . ' published INTEGER NOT NULL DEFAULT 1, released TEXT, metadata TEXT NOT NULL DEFAULT \'\'',
        'oscampus_modules' => 'id INTEGER PRIMARY KEY, courses_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', ordering INTEGER NOT NULL DEFAULT 0',
        'oscampus_lessons' => 'id INTEGER PRIMARY KEY, modules_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', alias TEXT NOT NULL DEFAULT \'\','
            . ' type TEXT NOT NULL DEFAULT \'\', content TEXT NOT NULL DEFAULT \'\','
            . ' description TEXT NOT NULL DEFAULT \'\', length INTEGER NOT NULL DEFAULT 0,'
            . ' access INTEGER NOT NULL DEFAULT 1, published INTEGER NOT NULL DEFAULT 1,'
            . ' ordering INTEGER NOT NULL DEFAULT 0, metadata TEXT NOT NULL DEFAULT \'\'',
        'oscampus_users_lessons' => 'id INTEGER PRIMARY KEY, users_id INTEGER NOT NULL DEFAULT 0,'
            . ' lessons_id INTEGER NOT NULL DEFAULT 0, completed TEXT, score INTEGER NOT NULL DEFAULT 0,'
            . ' visits INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT \'\','
            . ' first_visit TEXT, last_visit TEXT',
        'oscampus_lessons_quizzes' => 'id INTEGER PRIMARY KEY, lessons_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', description TEXT NOT NULL DEFAULT \'\','
            . ' pass INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,'
            . ' time_limit INTEGER NOT NULL DEFAULT 0, ordering INTEGER NOT NULL DEFAULT 0',
        'oscampus_lessons_quiz_questions' => 'id INTEGER PRIMARY KEY, quizzes_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL DEFAULT \'\','
            . ' points INTEGER NOT NULL DEFAULT 1, ordering INTEGER NOT NULL DEFAULT 0',
        'oscampus_lessons_quiz_answers' => 'id INTEGER PRIMARY KEY, questions_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', correct INTEGER NOT NULL DEFAULT 0,'
            . ' ordering INTEGER NOT NULL DEFAULT 0',
    ];

    /**
     * Shika, in its current `tj_lms_` spelling. Every column is the
     * importer's documented expectation rather than a published schema.
     *
     * @var array<string, string>
     */
    private const SHIKA = [
        'tj_lms_courses' => 'id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT \'\','
            . ' alias TEXT NOT NULL DEFAULT \'\', short_description TEXT, description TEXT,'
            . ' difficulty_level TEXT, access INTEGER NOT NULL DEFAULT 1, state INTEGER NOT NULL DEFAULT 0',
        'tj_lms_modules' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', description TEXT, ordering INTEGER NOT NULL DEFAULT 0',
        'tj_lms_lessons' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0,'
            . ' module_id INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT \'\','
            . ' format TEXT NOT NULL DEFAULT \'\', content TEXT, url TEXT,'
            . ' duration INTEGER NOT NULL DEFAULT 0, ordering INTEGER NOT NULL DEFAULT 0',
        'tj_lms_lesson_track' => 'id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' course_id INTEGER NOT NULL DEFAULT 0, lesson_id INTEGER NOT NULL DEFAULT 0,'
            . ' status TEXT NOT NULL DEFAULT \'\', completed TEXT',
        'tj_lms_quiz' => 'id INTEGER PRIMARY KEY, lesson_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', description TEXT,'
            . ' pass_percentage INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0,'
            . ' duration INTEGER NOT NULL DEFAULT 0, ordering INTEGER NOT NULL DEFAULT 0',
        'tj_lms_quiz_questions' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0,'
            . ' question TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL DEFAULT \'\','
            . ' marks INTEGER NOT NULL DEFAULT 1, ordering INTEGER NOT NULL DEFAULT 0',
        'tj_lms_quiz_options' => 'id INTEGER PRIMARY KEY, question_id INTEGER NOT NULL DEFAULT 0,'
            . ' option TEXT NOT NULL DEFAULT \'\', correct INTEGER NOT NULL DEFAULT 0,'
            . ' ordering INTEGER NOT NULL DEFAULT 0',
    ];

    /**
     * Guru, whose tables keep the product's older words: a course is a
     * program, a module is a day, a lesson is a task. Every column is the
     * importer's documented expectation.
     *
     * @var array<string, string>
     */
    private const GURU = [
        'guru_program' => 'id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', intro TEXT,'
            . ' description TEXT, difficulty TEXT, access INTEGER NOT NULL DEFAULT 1,'
            . ' published INTEGER NOT NULL DEFAULT 0',
        'guru_days' => 'id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL DEFAULT 0,'
            . ' name TEXT NOT NULL DEFAULT \'\', description TEXT, ordering INTEGER NOT NULL DEFAULT 0',
        'guru_tasks' => 'id INTEGER PRIMARY KEY, day_id INTEGER NOT NULL DEFAULT 0,'
            . ' name TEXT NOT NULL DEFAULT \'\', description TEXT, url TEXT,'
            . ' type TEXT NOT NULL DEFAULT \'\', duration INTEGER NOT NULL DEFAULT 0,'
            . ' ordering INTEGER NOT NULL DEFAULT 0',
        'guru_quiz' => 'id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL DEFAULT 0,'
            . ' name TEXT NOT NULL DEFAULT \'\', description TEXT, pass_score INTEGER NOT NULL DEFAULT 0,'
            . ' attempts INTEGER NOT NULL DEFAULT 0, time_limit INTEGER NOT NULL DEFAULT 0,'
            . ' is_final INTEGER NOT NULL DEFAULT 0, ordering INTEGER NOT NULL DEFAULT 0',
        'guru_questions' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0,'
            . ' question TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL DEFAULT \'\','
            . ' answers TEXT, points INTEGER NOT NULL DEFAULT 1, ordering INTEGER NOT NULL DEFAULT 0',
        'guru_buy' => 'id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL DEFAULT 0,'
            . ' user_id INTEGER NOT NULL DEFAULT 0, customer_id INTEGER NOT NULL DEFAULT 0, expires TEXT',
        'guru_customer' => 'id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' name TEXT NOT NULL DEFAULT \'\'',
    ];

    /**
     * @param array<string, string> $tables
     */
    private function __construct(
        private readonly SqliteDatabase $database,
        private readonly array $tables,
    ) {
    }

    public static function oscampus(SqliteDatabase $database): self
    {
        return self::install($database, self::OSCAMPUS);
    }

    public static function shika(SqliteDatabase $database): self
    {
        return self::install($database, self::SHIKA);
    }

    /**
     * The same Shika schema under the older `tjlms_` spelling of the family.
     *
     * Two spellings is not a hypothetical: the importer's closed table list
     * carries both and picks one by probing, and an install on the older
     * spelling has to import exactly as well as one on the newer.
     */
    public static function shikaLegacy(SqliteDatabase $database): self
    {
        $tables = [];

        foreach (self::SHIKA as $table => $columns) {
            $tables[str_replace('tj_lms_', 'tjlms_', $table)] = $columns;
        }

        return self::install($database, $tables);
    }

    public static function guru(SqliteDatabase $database): self
    {
        return self::install($database, self::GURU);
    }

    /** Whether this fixture created that table, for a seed that serves two spellings of one family. */
    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): void
    {
        $this->require($table);
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO "%s" (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $columns)),
            implode(', ', array_fill(0, \count($columns), '?')),
        );

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute(array_map(
            static fn (mixed $value): mixed => \is_array($value) ? json_encode($value, \JSON_THROW_ON_ERROR) : $value,
            array_values($row),
        ));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $values, array $where): void
    {
        $this->require($table);
        $assignments = implode(', ', array_map(
            static fn (string $column): string => '"' . $column . '" = ?',
            array_keys($values),
        ));
        $conditions = implode(' AND ', array_map(
            static fn (string $column): string => '"' . $column . '" = ?',
            array_keys($where),
        ));

        $statement = $this->database->pdo()->prepare(sprintf(
            'UPDATE "%s" SET %s WHERE %s',
            $table,
            $assignments,
            $conditions,
        ));
        $statement->execute([...array_values($values), ...array_values($where)]);
    }

    /**
     * Removes one of the product's tables, which is how "this install renamed
     * or dropped that table" is proved: the importer has to lose that table
     * and keep the rest.
     */
    public function drop(string $table): void
    {
        $this->require($table);
        $this->database->pdo()->exec(sprintf('DROP TABLE "%s"', $table));
    }

    /**
     * Renames one column of one table, which is the other half of the same
     * proof: the table is there and its shape has moved.
     */
    public function renameColumn(string $table, string $from, string $to): void
    {
        $this->require($table);
        $this->database->pdo()->exec(sprintf('ALTER TABLE "%s" RENAME COLUMN "%s" TO "%s"', $table, $from, $to));
    }

    /**
     * Creates one product's schema in the throwaway database.
     *
     * It creates only tables that are not there: a fixture that dropped or
     * replaced a table it did not create would be one mistyped name away from
     * wiping a real one.
     *
     * @param array<string, string> $tables
     */
    private static function install(SqliteDatabase $database, array $tables): self
    {
        foreach (array_keys($tables) as $table) {
            if (self::exists($database, $table)) {
                throw new \RuntimeException(sprintf(
                    'The table "%s" already exists. This fixture only ever creates its own tables.',
                    $table,
                ));
            }
        }

        foreach ($tables as $table => $columns) {
            $database->pdo()->exec(sprintf('CREATE TABLE "%s" (%s)', $table, $columns));
        }

        return new self($database, $tables);
    }

    private static function exists(SqliteDatabase $database, string $table): bool
    {
        $statement = $database->pdo()->prepare(
            'SELECT COUNT(*) FROM sqlite_master WHERE type = \'table\' AND name = ?',
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function require(string $table): void
    {
        if (!isset($this->tables[$table])) {
            throw new \InvalidArgumentException(sprintf('"%s" is not one of this fixture\'s tables.', $table));
        }
    }
}
