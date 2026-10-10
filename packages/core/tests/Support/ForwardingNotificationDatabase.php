<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Database decorator base for deterministic notification write races. */
class ForwardingNotificationDatabase implements DatabasePort
{
    public function __construct(protected readonly DatabasePort $inner)
    {
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
