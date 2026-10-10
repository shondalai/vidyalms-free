<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * A database port that refuses one table's inserts.
 *
 * It exists so a test can force the outbox's own insert to fail inside the
 * business transaction, which is the direction that loses mail: written from
 * an event listener, the enqueue's failure was swallowed by the bus and the
 * state committed without it. Everything else passes through untouched, so
 * what the test measures is the transaction and not a stubbed world.
 */
final class FailingQueueDatabase implements DatabasePort
{
    public function __construct(
        private readonly DatabasePort $inner,
        private readonly string $failingTable = 'notification_queue',
    ) {
    }

    public function selectAll(Query $query): array
    {
        return $this->inner->selectAll($query);
    }

    public function selectOne(Query $query): ?array
    {
        return $this->inner->selectOne($query);
    }

    public function count(Query $query): int
    {
        return $this->inner->count($query);
    }

    public function insert(string $table, array $row): int
    {
        if ($table === $this->failingTable) {
            throw new \RuntimeException(sprintf('The %s insert failed.', $table));
        }

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
