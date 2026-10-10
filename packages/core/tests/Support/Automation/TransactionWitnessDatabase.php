<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Automation;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The real database, plus a record of how deep the transaction nesting was at
 * any moment somebody asked.
 *
 * A process killed between a step's effect and its outcome cannot be staged
 * in one PHP process, so the property that stops it is asserted directly
 * instead: the claim, the effect and the outcome are inside one transaction,
 * so a kill rolls all three back together and the lease returns a row that has
 * genuinely not acted. Before R6 they were three separate commits and the
 * reclaim found no claim to stop the retry, so the effect ran twice.
 */
final class TransactionWitnessDatabase implements DatabasePort
{
    private int $depth = 0;

    public function __construct(private readonly DatabasePort $inner)
    {
    }

    /** How deep the nesting is right now. */
    public function depth(): int
    {
        return $this->depth;
    }

    public function transactional(callable $operation): mixed
    {
        ++$this->depth;

        try {
            return $this->inner->transactional($operation);
        } finally {
            --$this->depth;
        }
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

    public function delete(string $table, array $where): int
    {
        return $this->inner->delete($table, $where);
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        $this->inner->upsert($table, $row, $uniqueBy, $updateColumns);
    }
}
