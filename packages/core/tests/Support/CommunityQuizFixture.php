<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

/**
 * A Community Quiz database, small enough to read and faithful enough to
 * prove the importer against.
 *
 * The tables are the ones com_communityquiz installs (see
 * communityquiz/com_communityquiz/admin/sql/install.mysql.utf8.sql), with the
 * columns the importer reads and SQLite's types in place of MySQL's. They are
 * created under their bare names, which is what a host table is in the
 * harness: SqliteDatabase gives the "vidyalms_" prefix only to tables of ours.
 */
final class CommunityQuizFixture
{
    public const PREFIX = 'cq_';

    private const TABLES = [
        'courses' => 'id INTEGER PRIMARY KEY, catid INTEGER, title TEXT NOT NULL DEFAULT \'\', alias TEXT NOT NULL DEFAULT \'\','
            . ' subtitle TEXT NOT NULL DEFAULT \'\', description TEXT, level TEXT NOT NULL DEFAULT \'beginner\','
            . ' access_model TEXT NOT NULL DEFAULT \'free\', completion TEXT, drip TEXT, governance TEXT,'
            . ' cert_template_id INTEGER, cert_config TEXT, visibility TEXT NOT NULL DEFAULT \'public\','
            . ' show_in_catalog INTEGER NOT NULL DEFAULT 1, state INTEGER NOT NULL DEFAULT 0,'
            . ' language TEXT NOT NULL DEFAULT \'*\'',
        'course_modules' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT \'\','
            . ' summary TEXT, ordering INTEGER NOT NULL DEFAULT 0',
        'lessons' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0, module_id INTEGER NOT NULL DEFAULT 0,'
            . ' title TEXT NOT NULL DEFAULT \'\', type TEXT NOT NULL DEFAULT \'video\', content TEXT, quiz_id INTEGER,'
            . ' is_preview INTEGER NOT NULL DEFAULT 0, duration TEXT NOT NULL DEFAULT \'\', ordering INTEGER NOT NULL DEFAULT 0',
        'quizzes' => 'id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT \'\', intro TEXT, kind TEXT NOT NULL DEFAULT \'quiz\','
            . ' duration INTEGER NOT NULL DEFAULT 0, pass_mark REAL NOT NULL DEFAULT 0, scale_max REAL NOT NULL DEFAULT 100,'
            . ' attempts_allowed INTEGER NOT NULL DEFAULT 1, feedback TEXT, state INTEGER NOT NULL DEFAULT 0',
        'sections' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0, type TEXT NOT NULL DEFAULT \'fixed\','
            . ' title TEXT NOT NULL DEFAULT \'\', ordering INTEGER NOT NULL DEFAULT 0',
        'section_items' => 'id INTEGER PRIMARY KEY, section_id INTEGER NOT NULL DEFAULT 0, question_id INTEGER NOT NULL DEFAULT 0,'
            . ' points_override REAL, ordering INTEGER NOT NULL DEFAULT 0',
        'questions' => 'id INTEGER PRIMARY KEY, type TEXT NOT NULL DEFAULT \'mcq\', title TEXT NOT NULL DEFAULT \'\', stem TEXT,'
            . ' content TEXT, points REAL NOT NULL DEFAULT 1, feedback TEXT, rubric_id INTEGER, state INTEGER NOT NULL DEFAULT 1',
        'enrollments' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' status TEXT NOT NULL DEFAULT \'not_started\', source TEXT NOT NULL DEFAULT \'manual\', enrolled_at TEXT, expires_at TEXT',
        'lesson_progress' => 'id INTEGER PRIMARY KEY, enrollment_id INTEGER NOT NULL DEFAULT 0, course_id INTEGER NOT NULL DEFAULT 0,'
            . ' lesson_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'todo\'',
        'attempts' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' status TEXT NOT NULL DEFAULT \'submitted\'',
        'blueprints' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0, total_items INTEGER NOT NULL DEFAULT 0',
        'accommodations' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER, scope TEXT NOT NULL DEFAULT \'user\','
            . ' target_id INTEGER NOT NULL DEFAULT 0',
        'course_prerequisites' => 'id INTEGER PRIMARY KEY, course_id INTEGER NOT NULL DEFAULT 0,'
            . ' required_type TEXT NOT NULL DEFAULT \'course\', required_id INTEGER NOT NULL DEFAULT 0',
    ];

    private function __construct(
        private readonly SqliteDatabase $database,
        public readonly string $prefix,
    ) {
    }

    /**
     * Creates one Community Quiz schema in the throwaway database. A second
     * one under another prefix is how two installs sharing a database are
     * proved.
     *
     * It creates only tables that are not there: a fixture that dropped or
     * replaced a table it did not create would be one mistyped prefix away
     * from wiping a real one.
     */
    public static function install(SqliteDatabase $database, string $prefix = self::PREFIX): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a usable table prefix.', $prefix));
        }

        foreach (array_keys(self::TABLES) as $table) {
            if (self::exists($database, $prefix . $table)) {
                throw new \RuntimeException(sprintf(
                    'The table "%s%s" already exists. This fixture only ever creates its own tables.',
                    $prefix,
                    $table,
                ));
            }
        }

        foreach (self::TABLES as $table => $columns) {
            $database->pdo()->exec(sprintf('CREATE TABLE "%s%s" (%s)', $prefix, $table, $columns));
        }

        return new self($database, $prefix);
    }

    private static function exists(SqliteDatabase $database, string $table): bool
    {
        $statement = $database->pdo()->prepare(
            'SELECT COUNT(*) FROM sqlite_master WHERE type = \'table\' AND name = ?',
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO "%s%s" (%s) VALUES (%s)',
            $this->prefix,
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
        $assignments = implode(', ', array_map(static fn (string $column): string => '"' . $column . '" = ?', array_keys($values)));
        $conditions = implode(' AND ', array_map(static fn (string $column): string => '"' . $column . '" = ?', array_keys($where)));

        $statement = $this->database->pdo()->prepare(sprintf(
            'UPDATE "%s%s" SET %s WHERE %s',
            $this->prefix,
            $table,
            $assignments,
            $conditions,
        ));
        $statement->execute([...array_values($values), ...array_values($where)]);
    }

    public function count(string $table): int
    {
        $statement = $this->database->pdo()->query(sprintf('SELECT COUNT(*) FROM "%s%s"', $this->prefix, $table));

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }
}
