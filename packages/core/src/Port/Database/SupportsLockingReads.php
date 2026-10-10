<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * A current read that holds the row until the transaction ends.
 *
 * **It is a capability interface and not a method on `DatabasePort`**, on the
 * house rule: an adapter that cannot take a row lock is still a complete
 * adapter, and widening the port would make every implementation, including
 * the two host adapters and every test double, answer a question some of them
 * have no answer to.
 *
 * **It exists because a compare-and-swap cannot converge under REPEATABLE
 * READ.** The counter loop reads `next_number`, tries `UPDATE ... WHERE
 * next_number = :seen`, and on a miss reads again; but the retry's plain
 * SELECT is a consistent read against the transaction's own snapshot, so it
 * re-supplies the same stale value for ever and the loop gives up after its
 * attempt bound. Both hosts run InnoDB, where that is the default isolation
 * level, and SQLite hides it because its snapshot is the write itself. A
 * `SELECT ... FOR UPDATE` is a CURRENT read: it sees what the winner
 * committed and blocks until it can, which is what makes the second attempt
 * land on a free number rather than on the same one.
 *
 * **Absent means the compare-and-swap stands**, which is what every caller did
 * before this existed. The unique index on the document is still the
 * authority, so the outcome is a refused allocation rather than a duplicate
 * number; what the lock buys is that the refusal is rare instead of certain.
 */
interface SupportsLockingReads
{
    /**
     * One row, read under a lock the enclosing transaction holds.
     *
     * The caller MUST already be inside `DatabasePort::transactional()`: a
     * lock outside a transaction is released before it can be used, which
     * reads as working and is not.
     *
     * @return array<string, mixed>|null
     */
    public function selectOneForUpdate(Query $query): ?array;
}
