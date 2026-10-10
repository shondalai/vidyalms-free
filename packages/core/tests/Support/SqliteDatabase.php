<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlQueryCompiler;
use Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * An in-memory SQLite implementation of the database port, faithful to the
 * port's conventions: logical table names get the "vidyalms_" prefix, rows
 * are scalar maps, datetimes are UTC strings. The whole suite runs against
 * this with no CMS loaded.
 *
 * It compiles aggregates, OR groups and nulls-last ordering the same way the
 * host adapters must: see tooling/contract/DatabasePortContract.php, which
 * is the shared proof that all three answer identically.
 */
final class SqliteDatabase implements DatabasePort, SupportsLockingReads
{
    public const PREFIX = 'vidyalms_';

    private int $transactionDepth = 0;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    public static function inMemory(): self
    {
        return new self(new \PDO('sqlite::memory:'));
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function selectAll(Query $query): array
    {
        [$sql, $bindings] = $this->compileSelect($query);
        $statement = $this->pdo->prepare($sql);
        $this->executeBound($statement, $bindings);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    public function selectOne(Query $query): ?array
    {
        $limited = clone $query;
        $rows = $this->selectAll($limited->limit(1));

        return $rows[0] ?? null;
    }

    /**
     * SQLite has one writer at a time, so its ordinary read inside a
     * transaction is already a current read of everything committed. There is
     * no `FOR UPDATE` clause and no need for one; the capability is declared so
     * the caller takes the same code path here as on both hosts.
     */
    public function selectOneForUpdate(Query $query): ?array
    {
        return $this->selectOne($query);
    }

    public function count(Query $query): int
    {
        [$sql, $bindings] = $this->compileSelect($query, forCount: true);
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS aggregate FROM ({$sql}) AS counted");
        $this->executeBound($statement, $bindings);

        return (int) $statement->fetchColumn();
    }

    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, \count($columns), '?'));
        $columnList = implode(', ', array_map($this->quoteIdentifier(...), $columns));

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->physical($table), $columnList, $placeholders);
        $statement = $this->pdo->prepare($sql);

        try {
            $statement->execute($this->normaliseValues(array_values($row)));
        } catch (\PDOException $failure) {
            throw $this->mapConstraint($failure, $table);
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
            array_keys($values),
        ));

        [$whereSql, $whereBindings] = $this->compileEqualityWhere($where);

        $sql = sprintf('UPDATE %s SET %s%s', $this->physical($table), $assignments, $whereSql);
        $statement = $this->pdo->prepare($sql);

        try {
            $statement->execute($this->normaliseValues([...array_values($values), ...$whereBindings]));
        } catch (\PDOException $failure) {
            throw $this->mapConstraint($failure, $table);
        }

        return $statement->rowCount();
    }

    /**
     * The port's own duplicate-key type, or the driver failure untouched.
     *
     * The mapping is the test double's half of an adapter obligation stated
     * on DatabasePort: a caller implementing idempotency catches exactly one
     * type, so a connection failure or a column-length failure must not reach
     * it wearing the same coat.
     */
    private function mapConstraint(\PDOException $failure, string $table): \Throwable
    {
        $sqlState = \is_string($failure->errorInfo[0] ?? null) ? $failure->errorInfo[0] : null;
        $driverCode = \is_int($failure->errorInfo[1] ?? null) ? $failure->errorInfo[1] : null;

        if (!UniqueConstraintViolation::looksLikeOne($sqlState, $driverCode, $failure->getMessage())) {
            return $failure;
        }

        // SQLite names the columns rather than the index, so the index name
        // is null here and a caller that needs certainty re-reads on the key
        // it wrote, exactly as the port's docblock says.
        return UniqueConstraintViolation::on($table, null, $failure);
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, \count($columns), '?'));
        $columnList = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $conflictList = implode(', ', array_map($this->quoteIdentifier(...), $uniqueBy));

        if ($updateColumns === []) {
            $action = 'NOTHING';
        } else {
            $assignments = implode(', ', array_map(
                fn (string $column): string => sprintf(
                    '%1$s = excluded.%1$s',
                    $this->quoteIdentifier($column),
                ),
                $updateColumns,
            ));
            $action = 'UPDATE SET ' . $assignments;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO %s',
            $this->physical($table),
            $columnList,
            $placeholders,
            $conflictList,
            $action,
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($this->normaliseValues(array_values($row)));
    }

    public function delete(string $table, array $where): int
    {
        [$whereSql, $whereBindings] = $this->compileEqualityWhere($where);

        $statement = $this->pdo->prepare(sprintf('DELETE FROM %s%s', $this->physical($table), $whereSql));
        $statement->execute($this->normaliseValues($whereBindings));

        return $statement->rowCount();
    }

    /**
     * How many transactions are open, so a test can assert that a read the
     * design says happens inside one actually does.
     *
     * The AI reservation stage's own docblock said the ceiling evaluation and
     * the reserved write shared one `transactional()` call and they did not:
     * the policies ran four stages earlier with nothing open.
     */
    public function transactionDepth(): int
    {
        return $this->transactionDepth;
    }

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

        $this->pdo->beginTransaction();
        $this->transactionDepth = 1;

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $failure) {
            $this->pdo->rollBack();

            throw $failure;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /**
     * The harness has no host prefix, so a host table is read under its bare
     * name: a test seeds "cq_courses" exactly as Community Quiz names it.
     */
    private function physical(string $logical, bool $hostTable = false): string
    {
        return $this->quoteIdentifier($hostTable ? $logical : self::PREFIX . $logical);
    }

    /**
     * The shared compiler does the work; this only hands it a dialect and
     * collects what it bound. The condition compiler, the LIKE escape, the
     * conditional COUNT, the rate and the nulls-last key were written out
     * here and in both adapters, and agreed only because the port contract
     * checked them afterwards.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileSelect(Query $query, bool $forCount = false): array
    {
        $dialect = new SqliteDialect(self::PREFIX);
        $sql = (new SqlQueryCompiler($dialect))->statement($query, $forCount);

        return [$sql, $dialect->bindings()];
    }

    /**
     * @param array<string, mixed> $where
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileEqualityWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($where as $column => $value) {
            if ($value === null) {
                $clauses[] = $this->quoteIdentifier($column) . ' IS NULL';
                continue;
            }

            $clauses[] = $this->quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * PDOStatement::execute($values) binds every positional value as text.
     * A comparison against an aggregate alias has no column affinity for
     * SQLite to coerce that text back to an integer, so HAVING COUNT(*) > ?
     * would incorrectly match nothing. Bind the scalar type the port was
     * given, as the two production adapters already do.
     *
     * @param list<mixed> $values
     */
    private function executeBound(\PDOStatement $statement, array $values): void
    {
        foreach ($values as $position => $value) {
            $type = match (true) {
                $value === null => \PDO::PARAM_NULL,
                \is_bool($value), \is_int($value) => \PDO::PARAM_INT,
                default => \PDO::PARAM_STR,
            };
            $statement->bindValue($position + 1, \is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function normaliseValues(array $values): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (\is_bool($value)) {
                return $value ? 1 : 0;
            }

            return $value;
        }, $values);
    }
}
