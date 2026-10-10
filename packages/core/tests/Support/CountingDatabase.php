<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * A database port that counts what a request actually asked for: how many
 * reads it issued and how many rows those reads returned.
 *
 * This is how "the list pages at the database" stops being a claim. A use
 * case that reads the whole filtered set and slices in PHP passes every
 * assertion about its output and fails here, because the row count gives it
 * away.
 */
final class CountingDatabase implements DatabasePort
{
    public int $reads = 0;

    public int $rowsRead = 0;

    public function __construct(
        private readonly DatabasePort $inner,
    ) {
    }

    public function reset(): void
    {
        $this->reads = 0;
        $this->rowsRead = 0;
    }

    public function selectAll(Query $query): array
    {
        ++$this->reads;
        $rows = $this->inner->selectAll($query);
        $this->rowsRead += \count($rows);

        return $rows;
    }

    public function selectOne(Query $query): ?array
    {
        ++$this->reads;
        $row = $this->inner->selectOne($query);
        $this->rowsRead += $row === null ? 0 : 1;

        return $row;
    }

    public function count(Query $query): int
    {
        ++$this->reads;

        return $this->inner->count($query);
    }

    public function insert(string $table, array $row): int
    {
        return $this->inner->insert($table, $row);
    }

    public function update(string $table, array $values, array $where): int
    {
        return $this->inner->update($table, $values, $where);
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        $this->inner->upsert($table, $row, $uniqueBy, $updateColumns);
    }

    public function delete(string $table, array $where): int
    {
        return $this->inner->delete($table, $where);
    }

    public function transactional(callable $operation): mixed
    {
        return $this->inner->transactional($operation);
    }
}
