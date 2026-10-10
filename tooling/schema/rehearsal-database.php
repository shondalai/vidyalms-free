<?php

/**
 * A database port for the offline schema rehearsals, and for nothing else.
 *
 * The rehearsals in packages/adapter-joomla/build and
 * packages/adapter-wordpress/build install the shipped schema into a throwaway
 * MySQL database and then have to answer one question the SQL cannot: does the
 * install path produce the seed rows the collapsed migration chain used to
 * produce? The rows are written by the core's own use cases, never by the
 * rehearsal, because two implementations of "create the default series" would
 * drift and the drift would be invisible. So the use cases need a DatabasePort
 * over the throwaway connection.
 *
 * The shipped host ports cannot serve that offline. JoomlaDatabase takes a
 * Joomla\Database\DatabaseInterface and WpDatabase takes a \wpdb, and neither
 * class exists outside a booted CMS. This is the third implementation the port
 * has, beside those two and the suite's SQLite double, and it is deliberately
 * the narrowest: it answers exactly the calls the install path makes and
 * refuses everything else out loud, so a rehearsal that starts exercising
 * something wider fails rather than quietly proving less than it claims.
 *
 * What it therefore does NOT prove: that JoomlaDatabase and WpDatabase compile
 * the same queries. That is what tooling/contract/DatabasePortContract.php is
 * for on the unit side, and what installing on the two dev sites is for on the
 * integration side.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Schema;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

final class RehearsalDatabase implements DatabasePort
{
    /**
     * The product's own prefix, between the host prefix and the logical name,
     * exactly as both shipped ports place it.
     */
    public const PRODUCT_PREFIX = 'vidyalms_';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $hostPrefix,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function selectAll(Query $query): array
    {
        [$where, $bindings] = $this->compileConditions($query);

        $sql = 'SELECT * FROM ' . $this->physical($query->table()) . $where;
        $limit = $query->limitValue();

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectOne(Query $query): ?array
    {
        $limited = clone $query;

        return $this->selectAll($limited->limit(1))[0] ?? null;
    }

    public function count(Query $query): int
    {
        [$where, $bindings] = $this->compileConditions($query);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->physical($query->table()) . $where);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $table, array $row): int
    {
        $columns = array_keys($row);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->physical($table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_fill(0, \count($columns), '?')),
        );

        try {
            $this->pdo->prepare($sql)->execute(array_values($row));
        } catch (\PDOException $failure) {
            throw $this->mapConstraint($failure, $table);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }

        $assignments = implode(', ', array_map(
            fn (string $column): string => $this->quote($column) . ' = ?',
            array_keys($values),
        ));

        $conditions = implode(' AND ', array_map(
            fn (string $column): string => $this->quote($column) . ' = ?',
            array_keys($where),
        ));

        $sql = sprintf('UPDATE %s SET %s', $this->physical($table), $assignments)
            . ($where === [] ? '' : ' WHERE ' . $conditions);

        $statement = $this->pdo->prepare($sql);
        $statement->execute([...array_values($values), ...array_values($where)]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $uniqueBy
     * @param list<string>         $updateColumns
     */
    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        if ($uniqueBy === []) {
            throw new \LogicException('The rehearsal port needs the unique columns to upsert on.');
        }

        $columns = array_keys($row);

        // MySQL keys the conflict off whichever unique index the row collides
        // with rather than off a named column list, so $uniqueBy is not in the
        // statement. An empty $updateColumns means "leave the existing row
        // alone", which is a self-assignment rather than no clause at all,
        // because ON DUPLICATE KEY UPDATE takes at least one assignment.
        $assignments = $updateColumns === []
            ? sprintf('%1$s = %1$s', $this->quote($uniqueBy[0]))
            : implode(', ', array_map(
                fn (string $column): string => sprintf('%1$s = VALUES(%1$s)', $this->quote($column)),
                $updateColumns,
            ));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->physical($table),
            implode(', ', array_map($this->quote(...), $columns)),
            implode(', ', array_fill(0, \count($columns), '?')),
            $assignments,
        );

        $this->pdo->prepare($sql)->execute(array_values($row));
    }

    /**
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        $conditions = implode(' AND ', array_map(
            fn (string $column): string => $this->quote($column) . ' = ?',
            array_keys($where),
        ));

        $statement = $this->pdo->prepare(
            'DELETE FROM ' . $this->physical($table) . ($where === [] ? '' : ' WHERE ' . $conditions),
        );
        $statement->execute(array_values($where));

        return $statement->rowCount();
    }

    public function transactional(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $operation();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $failure) {
            $this->pdo->rollBack();

            throw $failure;
        }
    }

    /**
     * The physical name: host prefix, product prefix, logical name. A host
     * table read is not supported, because no install-path write is one.
     */
    private function physical(string $logical): string
    {
        return $this->quote($this->hostPrefix . self::PRODUCT_PREFIX . $logical);
    }

    private function quote(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }

    /**
     * Only the comparison predicate, which is every predicate the install path
     * uses. Anything else stops the rehearsal rather than being approximated.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileConditions(Query $query): array
    {
        if ($query->readsHostTable() || $query->joins() !== [] || $query->aggregates() !== []) {
            throw new \LogicException('The rehearsal port reads one table with plain comparisons only.');
        }

        $clauses = [];
        $bindings = [];

        foreach ($query->conditions() as $condition) {
            if ($condition['kind'] !== 'compare') {
                throw new \LogicException(
                    'The rehearsal port does not compile the "' . $condition['kind'] . '" predicate.',
                );
            }

            $clauses[] = $this->quote($condition['column']) . ' ' . $condition['operator'] . ' ?';
            $bindings[] = $condition['value'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * The port's own duplicate-key type, or the driver failure untouched. A
     * caller implementing idempotency catches exactly one type, so a
     * connection failure must not reach it wearing the same coat.
     */
    private function mapConstraint(\PDOException $failure, string $table): \Throwable
    {
        $sqlState = \is_string($failure->errorInfo[0] ?? null) ? $failure->errorInfo[0] : null;
        $driverCode = \is_int($failure->errorInfo[1] ?? null) ? $failure->errorInfo[1] : null;

        if (!UniqueConstraintViolation::looksLikeOne($sqlState, $driverCode, $failure->getMessage())) {
            return $failure;
        }

        return UniqueConstraintViolation::on($table, null, $failure);
    }
}
