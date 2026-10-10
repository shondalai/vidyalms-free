<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

/**
 * A WordPress database with one LMS plugin's tables in it, small enough to
 * read and faithful enough to prove an importer against.
 *
 * The four core tables are the ones WordPress installs, with the columns the
 * importers read and SQLite's types in place of MySQL's. The product tables
 * are each plugin's own, from the published schema each importer names in its
 * docblock. They are created under their bare names, which is what a host
 * table is in the harness: SqliteDatabase gives the "vidyalms_" prefix only to
 * tables of ours, and `$wpdb->prefix` is the adapter's business on a real
 * site.
 *
 * It creates only tables that are not there. A fixture that dropped or
 * replaced a table it did not create would be one mistake away from wiping a
 * real one.
 */
final class WordPressLmsFixture
{
    /** The WordPress core tables every product reader needs. */
    private const CORE = [
        'posts' => 'ID INTEGER PRIMARY KEY, post_author INTEGER NOT NULL DEFAULT 0, post_parent INTEGER NOT NULL DEFAULT 0,'
            . ' post_title TEXT NOT NULL DEFAULT \'\', post_name TEXT NOT NULL DEFAULT \'\','
            . ' post_excerpt TEXT NOT NULL DEFAULT \'\', post_content TEXT NOT NULL DEFAULT \'\','
            . ' post_status TEXT NOT NULL DEFAULT \'publish\', post_type TEXT NOT NULL DEFAULT \'post\','
            . ' menu_order INTEGER NOT NULL DEFAULT 0, post_date TEXT NOT NULL DEFAULT \'\'',
        'postmeta' => 'meta_id INTEGER PRIMARY KEY, post_id INTEGER NOT NULL DEFAULT 0,'
            . ' meta_key TEXT NOT NULL DEFAULT \'\', meta_value TEXT',
        'usermeta' => 'umeta_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' meta_key TEXT NOT NULL DEFAULT \'\', meta_value TEXT',
        'options' => 'option_id INTEGER PRIMARY KEY, option_name TEXT NOT NULL DEFAULT \'\', option_value TEXT',
    ];

    /** LearnDash: the two ProQuiz tables and the activity table. */
    public const LEARNDASH = [
        'learndash_pro_quiz_master' => 'id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', text TEXT,'
            . ' time_limit INTEGER NOT NULL DEFAULT 0, quiz_run_once INTEGER NOT NULL DEFAULT 0,'
            . ' statistics_on INTEGER NOT NULL DEFAULT 0',
        'learndash_pro_quiz_question' => 'id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0,'
            . ' sort INTEGER NOT NULL DEFAULT 0, title TEXT NOT NULL DEFAULT \'\', points REAL NOT NULL DEFAULT 1,'
            . ' question TEXT, answer_type TEXT NOT NULL DEFAULT \'single\', answer_data TEXT, correct_msg TEXT,'
            . ' online INTEGER NOT NULL DEFAULT 1',
        'learndash_user_activity' => 'activity_id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' post_id INTEGER NOT NULL DEFAULT 0, course_id INTEGER NOT NULL DEFAULT 0,'
            . ' activity_type TEXT NOT NULL DEFAULT \'course\', activity_status INTEGER NOT NULL DEFAULT 0,'
            . ' activity_started INTEGER NOT NULL DEFAULT 0, activity_completed INTEGER NOT NULL DEFAULT 0',
    ];

    /** Tutor LMS: its two question tables. */
    public const TUTOR = [
        'tutor_quiz_questions' => 'question_id INTEGER PRIMARY KEY, quiz_id INTEGER NOT NULL DEFAULT 0,'
            . ' question_title TEXT NOT NULL DEFAULT \'\', question_description TEXT,'
            . ' question_type TEXT NOT NULL DEFAULT \'single_choice\', question_mark REAL NOT NULL DEFAULT 1,'
            . ' question_order INTEGER NOT NULL DEFAULT 0',
        'tutor_quiz_question_answers' => 'answer_id INTEGER PRIMARY KEY, belongs_question_id INTEGER NOT NULL DEFAULT 0,'
            . ' belongs_question_type TEXT NOT NULL DEFAULT \'\', answer_title TEXT NOT NULL DEFAULT \'\','
            . ' is_correct INTEGER NOT NULL DEFAULT 0, answer_two_gap_match TEXT,'
            . ' answer_order INTEGER NOT NULL DEFAULT 0',
    ];

    /** LifterLMS: the one table it keeps enrolment and completion in. */
    public const LIFTERLMS = [
        'lifterlms_user_postmeta' => 'id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL DEFAULT 0,'
            . ' post_id INTEGER NOT NULL DEFAULT 0, meta_key TEXT NOT NULL DEFAULT \'\', meta_value TEXT,'
            . ' updated_date TEXT NOT NULL DEFAULT \'\'',
    ];

    /** The ProQuiz model a serialised LearnDash answer names. */
    private const ANSWER_CLASS = 'WpProQuiz_Model_AnswerTypes';

    private function __construct(
        private readonly SqliteDatabase $database,
    ) {
    }

    /**
     * @param array<string, string> $productTables one of the constants above
     */
    public static function install(SqliteDatabase $database, array $productTables): self
    {
        $tables = [...self::CORE, ...$productTables];

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

        return new self($database);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO "%s" (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $columns)),
            implode(', ', array_fill(0, \count($columns), '?')),
        );

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute(array_map(
            static fn (mixed $value): mixed => \is_bool($value) ? (int) $value : $value,
            array_values($row),
        ));
    }

    /**
     * Sets columns on one row, found by one column's value.
     *
     * Tests that vary a product setting need to change a row rather than add a
     * second one: two rows for one setting is a shape the product never has,
     * so a test built that way proves something about duplicate handling
     * rather than about the setting.
     *
     * @param array<string, mixed> $values
     */
    public function update(string $table, int $id, array $values, string $keyColumn = 'id'): void
    {
        if ($values === []) {
            return;
        }

        $sql = sprintf(
            'UPDATE "%s" SET %s WHERE "%s" = ?',
            $table,
            implode(', ', array_map(
                static fn (string $column): string => '"' . $column . '" = ?',
                array_keys($values),
            )),
            $keyColumn,
        );

        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute([
            ...array_map(
                static fn (mixed $value): mixed => \is_bool($value) ? (int) $value : $value,
                array_values($values),
            ),
            $id,
        ]);
    }

    /**
     * Replaces one post's meta rows for the named keys, so a test can vary a
     * setting rather than write a second row for it.
     *
     * @param array<string, mixed> $pairs
     */
    public function replaceMeta(int $postId, array $pairs): void
    {
        foreach (array_keys($pairs) as $key) {
            $statement = $this->database->pdo()->prepare(
                'DELETE FROM "postmeta" WHERE "post_id" = ? AND "meta_key" = ?',
            );
            $statement->execute([$postId, $key]);
        }

        $this->meta($postId, $pairs);
    }

    /**
     * One post, with the defaults a published LMS object has.
     *
     * @param array<string, mixed> $overrides
     */
    public function post(int $id, string $type, string $title, array $overrides = []): void
    {
        $this->insert('posts', [
            'ID' => $id,
            'post_type' => $type,
            'post_title' => $title,
            'post_name' => strtolower(str_replace(' ', '-', $title)),
            'post_status' => 'publish',
            'post_date' => '2024-01-01 00:00:00',
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $pairs
     */
    public function meta(int $postId, array $pairs): void
    {
        /** @var mixed $value */
        foreach ($pairs as $key => $value) {
            $this->insert('postmeta', ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
        }
    }

    public function userMeta(int $userId, string $key, string $value): void
    {
        $this->insert('usermeta', ['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
    }

    public function option(string $name, string $value): void
    {
        $this->insert('options', ['option_name' => $name, 'option_value' => $value]);
    }

    /**
     * A serialised list of `WpProQuiz_Model_AnswerTypes`, written by hand.
     *
     * It is composed rather than produced with `serialize()` because the shape
     * being proved is exactly the one this product refuses to reconstruct: a
     * serialised object of a class that does not exist here, whose private
     * property names arrive mangled. Round tripping a stdClass would prove the
     * unmangling against the wrong class name.
     *
     * @param list<array{answer: string, correct?: bool, points?: int}> $answers
     */
    public static function answerData(array $answers): string
    {
        $objects = '';
        $index = 0;

        foreach ($answers as $answer) {
            $properties = [
                '_answer' => $answer['answer'],
                '_correct' => $answer['correct'] ?? false,
                '_points' => $answer['points'] ?? 1,
            ];
            $body = '';

            /** @var string|bool|int $value */
            foreach ($properties as $name => $value) {
                $key = "\0" . self::ANSWER_CLASS . "\0" . $name;
                $body .= sprintf('s:%d:"%s";%s', \strlen($key), $key, self::scalar($value));
            }

            $objects .= sprintf(
                'i:%d;O:%d:"%s":%d:{%s}',
                $index,
                \strlen(self::ANSWER_CLASS),
                self::ANSWER_CLASS,
                \count($properties),
                $body,
            );
            $index++;
        }

        return sprintf('a:%d:{%s}', \count($answers), $objects);
    }

    /**
     * One serialised settings array, which is how all three products store a
     * settings bag in post meta.
     *
     * @param array<string, mixed> $values
     */
    public static function serialised(array $values): string
    {
        return serialize($values);
    }

    private static function scalar(string|bool|int $value): string
    {
        if (\is_bool($value)) {
            return 'b:' . ($value ? '1' : '0') . ';';
        }

        if (\is_int($value)) {
            return 'i:' . $value . ';';
        }

        return sprintf('s:%d:"%s";', \strlen($value), $value);
    }

    private static function exists(SqliteDatabase $database, string $table): bool
    {
        $statement = $database->pdo()->prepare(
            'SELECT COUNT(*) FROM sqlite_master WHERE type = \'table\' AND name = ?',
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }
}
