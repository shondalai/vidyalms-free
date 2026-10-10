<?php

declare(strict_types=1);

/**
 * VidyaLMS: the persistence port against $wpdb.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlQueryCompiler;
use Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

defined('ABSPATH') || exit;

/**
 * The WordPress implementation of the core's persistence boundary.
 *
 * Logical table names are prefixed ("courses" becomes "{$wpdb->prefix}vidyalms_courses";
 * on multisite that is the per-site prefix, so each site owns its own LMS).
 * Every identifier passes through a strict quoter that accepts only plain
 * identifiers, dot-qualified identifiers and "expr AS alias"; anything else
 * throws rather than reaching the database as raw SQL. Every value crosses as a
 * placeholder bound through $wpdb->prepare().
 *
 * The reference semantics are the core's SQLite harness
 * (packages/core/tests/Support/SqliteDatabase.php) and the proven Joomla
 * adapter (packages/adapter-joomla, JoomlaDatabase). A behaviour that holds
 * there and not here is a bug in this class.
 *
 * $wpdb->prepare() has no named parameters, so the SQL is built with positional
 * placeholders in the order values are collected and the argument list is
 * carried alongside it.
 */
final class WpDatabase implements DatabasePort, SupportsLockingReads
{
    private const TABLE_PREFIX = 'vidyalms_';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private int $transactionDepth = 0;

    /**
     * @param \wpdb $wpdb the site connection, shared with the rest of WordPress
     */
    public function __construct(
        private readonly \wpdb $wpdb,
    ) {
    }

    public function selectAll(Query $query): array
    {
        [$sql, $arguments] = $this->compileSelect($query);

        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // The shared SQL compiler validates identifiers and prepared() binds values. Live transactional reads cannot be cached.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $this->wpdb->get_results($this->prepared($sql, $arguments), ARRAY_A);
        $this->guardRead();

        /** @var list<array<string, mixed>> */
        return \is_array($rows) ? array_values($rows) : [];
    }

    public function selectOne(Query $query): ?array
    {
        $limited = clone $query;
        [$sql, $arguments] = $this->compileSelect($limited->limit(1));

        /** @var array<string, mixed>|null $row */
        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // The shared SQL compiler validates identifiers and prepared() binds values. Live transactional reads cannot be cached.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row($this->prepared($sql, $arguments), ARRAY_A);
        $this->guardRead();

        return \is_array($row) ? $row : null;
    }

    /**
     * One row, read under a lock the enclosing transaction holds.
     *
     * InnoDB defaults to REPEATABLE READ, where a plain SELECT after a failed
     * compare-and-swap re-supplies the same stale value from the snapshot for
     * ever. FOR UPDATE is a current read: it sees what the winner committed
     * and blocks until it can, which is what makes an invoice number series
     * converge instead of exhausting its attempt bound.
     */
    public function selectOneForUpdate(Query $query): ?array
    {
        $limited = clone $query;
        [$sql, $arguments] = $this->compileSelect($limited->limit(1));

        /** @var array<string, mixed>|null $row */
        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // The shared SQL compiler validates identifiers and prepared() binds values. Live transactional reads cannot be cached.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $row = $this->wpdb->get_row($this->prepared($sql . ' FOR UPDATE', $arguments), ARRAY_A);
        $this->guardRead();

        return is_array($row) ? $row : null;
    }

    public function count(Query $query): int
    {
        [$inner, $arguments] = $this->compileSelect($query, forCount: true);

        // The inner select keeps its grouping and drops its ordering and paging,
        // exactly as the SQLite harness does, so a grouped query counts groups.
        $sql = 'SELECT COUNT(*) AS `aggregate` FROM (' . $inner . ') AS `counted`';

        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // The shared SQL compiler validates identifiers and prepared() binds values. Live transactional reads cannot be cached.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $value = $this->wpdb->get_var($this->prepared($sql, $arguments));
        $this->guardRead();

        return (int) $value;
    }

    public function insert(string $table, array $row): int
    {
        if ($row === []) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \InvalidArgumentException(sprintf('Cannot insert an empty row into "%s".', $table));
        }

        $arguments = [];
        $columns = [];
        $placeholders = [];

        foreach ($row as $column => $value) {
            $columns[] = $this->identifier((string) $column);
            $placeholders[] = $this->placeholder($value, $arguments);
        }

        $sql = 'INSERT INTO ' . $this->physical($table)
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        $this->execute($sql, $arguments, $table);

        return (int) $this->wpdb->insert_id;
    }

    /**
     * The true affected row count, which the guarded completion update depends
     * on: the core sets completed_at only WHERE the enrolment is still active
     * and completed_at IS NULL, and emits its exactly-once CourseCompleted only
     * when this returns 1.
     *
     * $wpdb->query() returns the rows affected for an UPDATE, and MySQL counts
     * rows CHANGED, not rows matched (WordPress does not open the connection
     * with MYSQLI_CLIENT_FOUND_ROWS). An update that sets a column to the value
     * it already holds therefore reports 0. The core's guarded update always
     * changes the columns it sets, so the gate holds.
     */
    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }

        $arguments = [];
        $assignments = [];

        foreach ($values as $column => $value) {
            $assignments[] = $this->identifier((string) $column) . ' = ' . $this->placeholder($value, $arguments);
        }

        $sql = 'UPDATE ' . $this->physical($table)
            . ' SET ' . implode(', ', $assignments)
            . $this->equalityWhere($where, $arguments);

        return $this->execute($sql, $arguments, $table);
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        if ($row === []) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \InvalidArgumentException(sprintf('Cannot upsert an empty row into "%s".', $table));
        }

        $arguments = [];
        $columns = [];
        $placeholders = [];

        foreach ($row as $column => $value) {
            $columns[] = $this->identifier((string) $column);
            $placeholders[] = $this->placeholder($value, $arguments);
        }

        if ($updateColumns === []) {
            // A real DO NOTHING, written as a no-op assignment. INSERT IGNORE
            // would also swallow truncation and constraint failures that must
            // surface, and this backs the activity-log dedupe where a silently
            // dropped error would look like a successful write.
            $guard = $this->identifier((string) ($uniqueBy[0] ?? array_key_first($row)));
            $assignments = $guard . ' = ' . $guard;
        } else {
            $assignments = implode(', ', array_map(
                function (string $column): string {
                    $quoted = $this->identifier($column);

                    // VALUES() is deprecated in MySQL 8.0.20 in favour of a row
                    // alias, but the alias form is a syntax error on MySQL 5.7
                    // and MariaDB 10.3, which WordPress still supports.
                    return $quoted . ' = VALUES(' . $quoted . ')';
                },
                $updateColumns,
            ));
        }

        $sql = 'INSERT INTO ' . $this->physical($table)
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $assignments;

        $this->execute($sql, $arguments, $table);
    }

    public function delete(string $table, array $where): int
    {
        $arguments = [];
        $sql = 'DELETE FROM ' . $this->physical($table) . $this->equalityWhere($where, $arguments);

        return $this->execute($sql, $arguments, $table);
    }

    /**
     * Nesting is safe: only the outermost call issues the statements, so a use
     * case that composes two transactional repositories still commits once.
     *
     * This needs InnoDB, which is what the dbDelta statements in Setup\Activator
     * create. On MyISAM the statements are accepted and silently do nothing,
     * which would leave a failed enrolment half written.
     */
    public function transactional(callable $operation): mixed
    {
        if ($this->transactionDepth > 0) {
            $this->transactionDepth++;

            try {
                return $operation();
            } finally {
                $this->transactionDepth--;
            }
        }

        $this->statement('START TRANSACTION');
        $this->transactionDepth = 1;

        try {
            $result = $operation();
            $this->statement('COMMIT');

            return $result;
        } catch (\Throwable $failure) {
            $this->statement('ROLLBACK');

            throw $failure;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /**
     * The shared compiler builds the statement; this only hands it a dialect
     * and collects what it bound.
     *
     * The condition compiler with its ORed group, the LIKE escape, the
     * conditional COUNT, the AVG rate and the nulls-last leading key used to
     * be written out here, in JoomlaDatabase and in the SQLite harness, and
     * the three agreed only because the port contract checked them
     * afterwards.
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function compileSelect(Query $query, bool $forCount = false): array
    {
        $dialect = $this->dialect();
        $sql = (new SqlQueryCompiler($dialect))->statement($query, $forCount);

        return [$sql, $dialect->arguments()];
    }

    /**
     * A null in the where map is an IS NULL test, not an equality test against
     * NULL, which would never match. The core's guarded completion update
     * depends on it.
     *
     * @param array<string, mixed> $where
     * @param list<scalar>         $arguments
     */
    private function equalityWhere(array $where, array &$arguments): string
    {
        if ($where === []) {
            return '';
        }

        $clauses = [];

        foreach ($where as $column => $value) {
            $quoted = $this->identifier((string) $column);

            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
                continue;
            }

            $clauses[] = $quoted . ' = ' . $this->placeholder($value, $arguments);
        }

        return ' WHERE ' . implode(' AND ', $clauses);
    }

    /**
     * @param list<scalar> $arguments
     */
    private function prepared(string $sql, array $arguments): string
    {
        if ($arguments === []) {
            return $sql;
        }

        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $prepared = $this->wpdb->prepare($sql, $arguments);

        if (!\is_string($prepared)) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException(sprintf('WordPress could not prepare the statement "%s".', $sql));
        }

        return $prepared;
    }

    /**
     * @param list<scalar> $arguments
     *
     * @return int the affected row count
     *
     * @throws UniqueConstraintViolation when a unique index refused the write
     */
    private function execute(string $sql, array $arguments, ?string $table = null): int
    {
        return $this->statement($this->prepared($sql, $arguments), $table);
    }

    /**
     * The logical table is a trailing optional parameter rather than a new
     * required one, because the two transaction statements have no table and
     * never fail on an index.
     *
     * @throws UniqueConstraintViolation when a unique index refused the write
     */
    private function statement(string $sql, ?string $table = null): int
    {
        // Private compiler validates identifiers and binds all values through prepared(); transaction literals have no input.
        // The shared SQL compiler validates identifiers and prepared() binds values. Live transactional reads cannot be cached.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared
        $result = $this->wpdb->query($sql);

        if ($result === false) {
            $error = $this->wpdb->last_error !== '' ? $this->wpdb->last_error : 'unknown database error';

            if ($table !== null) {
                $violation = $this->asUniqueViolation($error, $table);

                if ($violation !== null) {
                    throw $violation;
                }
            }

            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException(sprintf('The VidyaLMS statement failed: %s', $error));
        }

        return (int) $result;
    }

    /**
     * The duplicate-key reading of a failed write, or null when it is
     * something else.
     *
     * WordPress publishes last_error through its database abstraction. A
     * different driver's unrecognised error remains an ordinary failure.
     */
    private function asUniqueViolation(string $error, string $table): ?UniqueConstraintViolation
    {
        // wpdb exposes last_error, not a portable driver handle. Recognise
        // MySQL/MariaDB's specific duplicate-entry diagnostic, never a broad
        // "constraint" substring that would misclassify unrelated failures.
        if (preg_match("/^Duplicate entry .+ for key ['`].+['`]$/isD", trim($error)) !== 1) {
            // The server's numeric diagnostic survives lc_messages changes.
            // Read it through wpdb rather than reaching into a mysqli handle.
            // Diagnostic state is connection-local and must never be cached.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $diagnostics = $this->wpdb->get_results('SHOW WARNINGS', ARRAY_A);
            $duplicate = false;
            foreach (is_array($diagnostics) ? $diagnostics : [] as $diagnostic) {
                if ((int) ($diagnostic['Code'] ?? 0) === 1062) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                return null;
            }
        }

        return UniqueConstraintViolation::on($table, self::indexNameIn($error));
    }

    /**
     * The index MySQL says refused the write.
     *
     * MySQL 8 qualifies the key with the table ("courses.courses_slug_uq")
     * and 5.7 does not, so the last segment is taken. Null where the driver
     * does not say.
     */
    private static function indexNameIn(string $message): ?string
    {
        if (preg_match("/for key '([^']+)'/i", $message, $matches) !== 1) {
            return null;
        }

        $position = strrpos($matches[1], '.');

        return $position === false ? $matches[1] : substr($matches[1], $position + 1);
    }

    /**
     * A read returns an empty result both when nothing matched and when the
     * statement failed, so the error has to be read from the connection.
     * $wpdb->flush() clears last_error at the start of every query, so this
     * only ever sees the error of the statement just run.
     */
    private function guardRead(): void
    {
        if ($this->wpdb->last_error !== '') {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException(sprintf('The VidyaLMS query failed: %s', $this->wpdb->last_error));
        }
    }

    /**
     * The rules below have exactly one implementation, in the dialect the read
     * path compiles through. The write methods of the port take a table and a
     * row map rather than a Query, so they thread their own argument list;
     * what they must not do is state the rules a second time, which is how two
     * copies of an escape or a quote drift apart.
     */
    private function dialect(): WpSqlDialect
    {
        return new WpSqlDialect($this->wpdb, self::TABLE_PREFIX, self::DATETIME_FORMAT);
    }

    private function identifier(string $name): string
    {
        return $this->dialect()->quoteIdentifier($name);
    }

    /**
     * A host table belongs to another plugin ("wp_learndash_..."), so it takes
     * WordPress's own prefix and none of ours. Only a Query::fromHostTable()
     * read reaches that branch; every write stays inside our own tables.
     */
    private function physical(string $logical, bool $hostTable = false): string
    {
        return $this->dialect()->physicalTable($logical, $hostTable);
    }

    /**
     * @param list<scalar> $arguments
     */
    private function placeholder(mixed $value, array &$arguments): string
    {
        $dialect = $this->dialect();
        $text = $dialect->placeholder($value);

        foreach ($dialect->arguments() as $argument) {
            $arguments[] = $argument;
        }

        return $text;
    }
}
