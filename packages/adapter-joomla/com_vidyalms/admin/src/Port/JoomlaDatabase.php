<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlQueryCompiler;
use Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * The Joomla implementation of the core's persistence boundary.
 *
 * Logical table names are prefixed ("courses" becomes "#__vidyalms_courses"),
 * every identifier passes through quoteName() and every value crosses as a
 * bound parameter. The reference semantics are the core's SQLite harness
 * (packages/core/tests/Support/SqliteDatabase.php); where MySQL and SQLite
 * differ the difference is called out in a comment.
 *
 * @since 1.0.0
 */
final class JoomlaDatabase implements DatabasePort, SupportsLockingReads
{
    private const TABLE_PREFIX = '#__vidyalms_';

    /** Joomla's own prefix, for reads of tables another extension owns. */
    private const HOST_PREFIX = '#__';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Joomla's MySQL builder drops an offset that has no limit, so an
     * offset-only page needs a limit large enough to mean "the rest".
     */
    private const UNLIMITED = \PHP_INT_MAX;

    private int $transactionDepth = 0;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public function selectAll(Query $query): array
    {
        $rows = $this->db->setQuery($this->compileSelect($query))->loadAssocList();

        /** @var list<array<string, mixed>> */
        return $rows === null ? [] : array_values($rows);
    }

    public function selectOne(Query $query): ?array
    {
        $limited = clone $query;
        $row = $this->db->setQuery($this->compileSelect($limited->limit(1)))->loadAssoc();

        return $row ?: null;
    }

    /**
     * One row, read under a lock the enclosing transaction holds.
     *
     * InnoDB defaults to REPEATABLE READ, where a plain SELECT after a failed
     * compare-and-swap re-supplies the same stale value from the snapshot for
     * ever. FOR UPDATE is a current read: it sees what the winner committed
     * and blocks until it can, which is what makes concurrent updates
     * converge instead of exhausting its attempt bound.
     */
    public function selectOneForUpdate(Query $query): ?array
    {
        $limited = clone $query;
        $compiled = $this->compileSelect($limited->limit(1));

        // The rendered text already carries LIMIT 1, and DatabaseQuery::__toString()
        // re-applies the limit to whatever setQuery() holds. Leaving the limit on the
        // object therefore renders "LIMIT 1 FOR UPDATE LIMIT 1", which MySQL refuses,
        // so the clause is dropped from the object before the text that carries it is
        // handed back. The bindings survive setQuery(); only the SQL text is swapped.
        $sql = (string) $compiled . ' FOR UPDATE';
        $compiled->setLimit(0, 0);
        $compiled->setQuery($sql);

        $row = $this->db->setQuery($compiled)->loadAssoc();

        return $row ?: null;
    }

    public function count(Query $query): int
    {
        $compiled = $this->compileSelect($query, forCount: true);

        // setQuery() on the query object swaps the SQL text and leaves the
        // bound parameters in place, so the wrapper keeps its bindings.
        $sql = 'SELECT COUNT(*) AS ' . $this->db->quoteName('aggregate')
            . ' FROM (' . (string) $compiled . ') AS ' . $this->db->quoteName('counted');
        $compiled->setQuery($sql);

        return (int) $this->db->setQuery($compiled)->loadResult();
    }

    public function insert(string $table, array $row): int
    {
        if ($row === []) {
            throw new \InvalidArgumentException(sprintf('Cannot insert an empty row into "%s".', $table));
        }

        $query = $this->db->createQuery();
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        $index = 0;

        foreach ($row as $column => $value) {
            $key = ':v' . $index++;
            $columns[] = $this->identifier((string) $column);
            $placeholders[] = $key;
            $this->addBinding($values, $types, $key, $value);
        }

        $query->insert($this->db->quoteName($this->physical($table)))
            ->columns($columns)
            ->values(implode(', ', $placeholders));

        $this->applyBindings($query, $values, $types);
        $this->executeWrite($query, $table);

        return (int) $this->db->insertid();
    }

    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }

        $query = $this->db->createQuery()->update($this->db->quoteName($this->physical($table)));
        $bound = [];
        $types = [];
        $index = 0;

        foreach ($values as $column => $value) {
            $key = ':s' . $index++;
            $query->set($this->identifier((string) $column) . ' = ' . $key);
            $this->addBinding($bound, $types, $key, $value);
        }

        foreach ($this->equalityClauses($where, 'w', $bound, $types) as $clause) {
            $query->where($clause);
        }

        $this->applyBindings($query, $bound, $types);
        $this->executeWrite($query, $table);

        // MySQL counts rows it actually changed, SQLite counts rows it matched.
        // The core's guarded completion update always changes the columns it
        // sets, so "exactly one affected row" still gates CourseCompleted.
        return (int) $this->db->getAffectedRows();
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        if ($row === []) {
            throw new \InvalidArgumentException(sprintf('Cannot upsert an empty row into "%s".', $table));
        }

        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        $index = 0;

        foreach ($row as $column => $value) {
            $key = ':v' . $index++;
            $columns[] = $this->identifier((string) $column);
            $placeholders[] = $key;
            $this->addBinding($values, $types, $key, $value);
        }

        if ($updateColumns === []) {
            // A no-op assignment, not INSERT IGNORE: IGNORE would also swallow
            // truncation and foreign key failures that must surface.
            $guard = $this->identifier((string) ($uniqueBy[0] ?? array_key_first($row)));
            $assignments = $guard . ' = ' . $guard;
        } else {
            $assignments = implode(', ', array_map(
                function (string $column): string {
                    $quoted = $this->identifier($column);

                    return $quoted . ' = VALUES(' . $quoted . ')';
                },
                $updateColumns,
            ));
        }

        $sql = 'INSERT INTO ' . $this->db->quoteName($this->physical($table))
            . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $assignments;

        $query = $this->db->createQuery();
        $query->setQuery($sql);

        $this->applyBindings($query, $values, $types);
        $this->executeWrite($query, $table);
    }

    public function delete(string $table, array $where): int
    {
        $query = $this->db->createQuery()->delete($this->db->quoteName($this->physical($table)));
        $bound = [];
        $types = [];

        foreach ($this->equalityClauses($where, 'w', $bound, $types) as $clause) {
            $query->where($clause);
        }

        $this->applyBindings($query, $bound, $types);
        $this->executeWrite($query, $table);

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Runs one write and maps a duplicate-key refusal onto the port's own
     * type.
     *
     * Joomla raises ExecutionFailureException for every statement failure,
     * carrying the driver's own exception as its previous. A caller that
     * turns a unique index into an idempotency guarantee has to distinguish
     * "the index refused this" from "the connection dropped" or "the value
     * was too long", and it cannot do that from a message match: on the money
     * path the wrong answer is a paid order reported as already granted when
     * nothing was granted.
     *
     * The mapping is narrow on purpose. UniqueConstraintViolation::looksLikeOne()
     * publishes the two facts every supported engine reports, so this adapter,
     * WpDatabase and the SQLite harness answer the question identically, and
     * tooling/contract/DatabasePortContract.php asserts both directions:
     * a duplicate raises the port type, and an insert naming a column that
     * does not exist does not.
     *
     * @throws UniqueConstraintViolation when a unique index refused the write
     */
    private function executeWrite(QueryInterface $query, string $table): void
    {
        try {
            $this->db->setQuery($query)->execute();
        } catch (\Throwable $failure) {
            $violation = $this->asUniqueViolation($failure, $table);

            throw $violation ?? $failure;
        }
    }

    /**
     * The duplicate-key reading of a driver failure, or null when it is
     * something else.
     *
     * The SQLSTATE lives on the PDO exception Joomla wraps, and the driver
     * code on the wrapper itself, so both are read defensively: a driver that
     * reports neither answers no, which leaves the original exception intact
     * rather than mislabelling it.
     */
    private function asUniqueViolation(\Throwable $failure, string $table): ?UniqueConstraintViolation
    {
        $sqlState = null;
        $driverCode = null;
        $message = $failure->getMessage();

        for ($candidate = $failure; $candidate !== null; $candidate = $candidate->getPrevious()) {
            $message .= ' ' . $candidate->getMessage();

            if ($candidate instanceof \PDOException) {
                // The PDO driver carries both facts here. The mysqli driver
                // has no PDO exception at all and reports mysqli_errno as the
                // exception code, which the cast below reads.
                $info = $candidate->errorInfo ?? [];
                $state = $info[0] ?? null;
                $number = $info[1] ?? null;

                if (\is_string($state)) {
                    $sqlState = $state;
                }

                if (\is_int($number)) {
                    $driverCode = $number;
                }
            }

            $code = (int) $candidate->getCode();

            if ($driverCode === null && $code > 0) {
                $driverCode = $code;
            }
        }

        if (!UniqueConstraintViolation::looksLikeOne($sqlState, $driverCode, $message)) {
            return null;
        }

        return UniqueConstraintViolation::on($table, self::indexNameIn($message), $failure);
    }

    /**
     * The index MySQL says refused the write.
     *
     * MySQL 8 qualifies the key with the table ("courses.courses_slug_uq")
     * and 5.7 does not, so the last segment is taken. Null where the driver
     * does not say: a caller that needs certainty re-reads on the key it
     * wrote rather than trusting a name.
     */
    private static function indexNameIn(string $message): ?string
    {
        if (preg_match("/for key '([^']+)'/i", $message, $matches) !== 1) {
            return null;
        }

        $position = strrpos($matches[1], '.');

        return $position === false ? $matches[1] : substr($matches[1], $position + 1);
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

        $this->db->transactionStart();
        $this->transactionDepth = 1;

        try {
            $result = $operation();
            $this->db->transactionCommit();

            return $result;
        } catch (\Throwable $failure) {
            $this->db->transactionRollback();

            throw $failure;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    /**
     * The shared compiler builds every fragment; this feeds them to Joomla's
     * query object, which owns the prefix replacement, the binding and the
     * limit.
     *
     * The condition compiler with its ORed group, the LIKE escape, the
     * conditional COUNT, the AVG rate and the nulls-last leading key used to
     * be written out here, in WpDatabase and in the SQLite harness, and the
     * three agreed only because the port contract checked them afterwards.
     */
    private function compileSelect(Query $query, bool $forCount = false): QueryInterface
    {
        $compiled = $this->db->createQuery();
        $dialect = new JoomlaSqlDialect($this->db, self::TABLE_PREFIX, self::HOST_PREFIX);
        $sql = new SqlQueryCompiler($dialect);

        $compiled->select($sql->selectList($query, $forCount));
        $compiled->from($sql->fromClause($query));

        foreach ($sql->joins($query) as $join) {
            $compiled->join($join['type'], $join['table'] . ' AS ' . $join['alias'], $join['on']);
        }

        foreach ($sql->whereClauses($query) as $clause) {
            $compiled->where($clause);
        }

        $grouping = $sql->groupBy($query);

        if ($grouping !== []) {
            $compiled->group($grouping);
        }

        foreach ($sql->havingClauses($query) as $clause) {
            $compiled->having($clause);
        }

        if (!$forCount) {
            foreach ($sql->orderBy($query) as $order) {
                $compiled->order($order);
            }

            $limit = $query->limitValue();
            $offset = $query->offsetValue();

            if ($limit !== null) {
                $compiled->setLimit($limit, $offset);
            } elseif ($offset > 0) {
                $compiled->setLimit(self::UNLIMITED, $offset);
            }
        }

        $dialect->bindTo($compiled);

        return $compiled;
    }

    /**
     * @param array<string, mixed>  $where
     * @param array<string, mixed>  $values
     * @param array<string, string> $types
     *
     * @return list<string>
     */
    private function equalityClauses(array $where, string $prefix, array &$values, array &$types): array
    {
        $clauses = [];
        $index = 0;

        foreach ($where as $column => $value) {
            $quoted = $this->identifier((string) $column);

            // A null in the where map is an IS NULL test, not an equality test
            // against NULL, which would never match. The core's guarded
            // completion update depends on it.
            if ($value === null) {
                $clauses[] = $quoted . ' IS NULL';
                continue;
            }

            $key = ':' . $prefix . $index++;
            $clauses[] = $quoted . ' = ' . $key;
            $this->addBinding($values, $types, $key, $value);
        }

        return $clauses;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $types
     */
    private function addBinding(array &$values, array &$types, string $key, mixed $value): void
    {
        $normalised = $this->normalise($value);
        $values[$key] = $normalised;
        $types[$key] = $this->parameterType($normalised);
    }

    /**
     * Binds array elements, never a loop variable: Joomla binds by reference
     * and a shared variable would collapse every placeholder onto one value.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $types
     */
    private function applyBindings(QueryInterface $query, array &$values, array $types): void
    {
        foreach ($types as $key => $type) {
            $query->bind($key, $values[$key], $type);
        }
    }

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::DATETIME_FORMAT);
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function parameterType(mixed $value): string
    {
        return match (true) {
            $value === null => ParameterType::NULL,
            \is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }


    /**
     * A host table belongs to another extension ("#__cq_courses"), so it takes
     * Joomla's own prefix and none of ours. Only a Query::fromHostTable() read
     * reaches this branch; every write stays inside our own tables.
     */
    private function identifier(string $column): string
    {
        return (new JoomlaSqlDialect($this->db, self::TABLE_PREFIX, self::HOST_PREFIX))->quoteIdentifier($column);
    }

    private function physical(string $logical, bool $hostTable = false): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $logical) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsupported table name "%s".', $logical));
        }

        return ($hostTable ? self::HOST_PREFIX : self::TABLE_PREFIX) . $logical;
    }
}
