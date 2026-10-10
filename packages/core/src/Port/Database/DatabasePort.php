<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * The single persistence boundary of the core. Adapters implement it with
 * Joomla's DatabaseDriver or WordPress's $wpdb; tests implement it with SQLite.
 *
 * Conventions at this boundary:
 * - table names are logical ("courses"); the adapter prefixes them
 *   ("#__vidyalms_courses", "{$wpdb->prefix}vidyalms_courses"), except for a
 *   Query::fromHostTable() read, which the adapter resolves with the host
 *   prefix alone so an importer can read another extension's tables. Only
 *   reads can name a host table; the write methods below take a logical name.
 * - rows are associative arrays of scalars and nulls
 * - datetimes cross as "Y-m-d H:i:s" strings in UTC
 * - JSON columns cross as already-encoded strings; repositories own encoding
 * - booleans cross as 0/1 integers
 */
interface DatabasePort
{
    /**
     * @return list<array<string, mixed>>
     */
    public function selectAll(Query $query): array;

    /**
     * @return array<string, mixed>|null
     */
    public function selectOne(Query $query): ?array;

    public function count(Query $query): int;

    /**
     * Inserts one row and returns the generated primary key.
     *
     * @param array<string, mixed> $row
     *
     * @throws UniqueConstraintViolation when a unique index refuses the row.
     *                                   Every implementation maps its own
     *                                   driver's duplicate-key error onto
     *                                   this type and nothing else onto it,
     *                                   because a caller that catches
     *                                   Throwable to implement idempotency
     *                                   would swallow a connection failure and
     *                                   report a grant that never happened.
     */
    public function insert(string $table, array $row): int;

    /**
     * Updates rows matched by column equality and returns the affected count.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     *
     * @throws UniqueConstraintViolation when a unique index refuses the new
     *                                   values, on the same terms as insert()
     */
    public function update(string $table, array $values, array $where): int;

    /**
     * Inserts the row, or updates $updateColumns when a row with the same
     * $uniqueBy values already exists. This is the primitive behind idempotent
     * progress heartbeats.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $uniqueBy
     * @param list<string>         $updateColumns
     */
    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void;

    /**
     * Deletes rows matched by column equality and returns the affected count.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int;

    /**
     * Runs the operation inside a transaction, committing on return and
     * rolling back on any throwable, which is rethrown.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
