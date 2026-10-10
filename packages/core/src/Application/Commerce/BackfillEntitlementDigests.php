<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Gives every existing entitlement the digest the unique index is carried on.
 *
 * It is a core use case rather than SQL inside two migration files, because
 * the derivation has to be **the same one** the running code uses. A row
 * hashed by a migration and a row hashed by the repository must be
 * indistinguishable, or the index refuses a legitimate grant on one host and
 * accepts a duplicate on the other. Each host's data migration calls this and
 * writes nothing of its own.
 *
 * **This is a one-way door and it is treated as one.** The separator cannot
 * change afterwards: every digest written here and every digest already on
 * the two development sites would be orphaned by a change, and a backfill run
 * under a live unique index is the highest-risk operation in the whole
 * reconciliation. ADR 0017 records the decision to adopt the shipped colon.
 *
 * **The repair mutates the key and never the status**, which is the
 * correction an earlier three-step sequence needed. A unique index constrains
 * column values, not statuses; the schema grammar has no partial-index form
 * and MySQL has none at all. So revoking the duplicates and then creating the
 * index fails on exactly the sites the repair exists for, **after** the repair
 * has already revoked rows. The four steps, in this order:
 *
 * 1. **Report** the duplicates, by (source, source_ref), with the user, the
 *    course and the grant instant of each row, so a human can see what is
 *    about to change before anything changes.
 * 2. **Keep the earliest active** row per (source, source_ref), and only when
 *    the group holds none, the earliest row of any status. Keeping row one by
 *    id regardless of status is the defect this sentence exists to forbid: a
 *    refund followed by a repurchase leaves a revoked row with the lower id
 *    beside the live paid grant, and electing the revoked one revokes the
 *    learner's access on upgrade. For each losing row, rewrite the key to
 *    `{source_ref}-dup{id}`, deliberately outside the documented grammar so no
 *    replay, no reversal ordinal and no future bridge can ever match it again.
 * 3. **Revoke** those rows with reason `duplicate_grant_repair`, so the
 *    history survives rather than being deleted. A row that was already
 *    revoked keeps the reason it already carried, because the reason a person
 *    was refunded is a fact and this repair is not the thing that revoked it.
 * 4. **Backfill** the digest for every row, after which the unique index can
 *    be created.
 *
 * **The repair rewrites the digest in the same statement as the key.** It has
 * to, and the failure when it does not is total rather than cosmetic: a losing
 * row that already carried a digest keeps the winner's digest under its new
 * key, step 4 then writes that same digest onto the winner, and the unique
 * index refuses it. The run aborts half applied, and every retry aborts in the
 * same place, so the upgrade can never complete.
 *
 * **The whole run is one transaction.** An aborted run leaves nothing revoked
 * and nothing renamed, which is the only safe direction for a repair that
 * mutates a live grant's key.
 *
 * The report is returned rather than logged, so the caller decides whether to
 * print it, store it or refuse to proceed. A dry run does steps 1 and 4's
 * arithmetic and writes nothing, and both hosts run it first so the pre-change
 * state is recorded before anything changes.
 *
 * @phpstan-type DuplicateRow array{id: int, user_id: int, course_id: int, status: string, created_at: ?string}
 * @phpstan-type DuplicateGroup array{source: string, source_ref: string, kept_id: int, rows: list<DuplicateRow>}
 */
final class BackfillEntitlementDigests
{
    private const TABLE = 'entitlements';

    /** The reason a repaired duplicate carries, so the history says what happened to it. */
    public const REPAIR_REASON = 'duplicate_grant_repair';

    /** Rows per statement, so a large site is not read into memory in one go. */
    private const CHUNK = 500;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array{
     *     duplicates: list<DuplicateGroup>,
     *     repaired: int,
     *     digested: int,
     *     dry_run: bool
     * }
     */
    public function execute(bool $dryRun = false): array
    {
        $duplicates = $this->findDuplicates();

        if ($dryRun) {
            return [
                'duplicates' => $duplicates,
                'repaired' => 0,
                'digested' => $this->countUndigested(),
                'dry_run' => true,
            ];
        }

        /** @var array{repaired: int, digested: int} $written */
        $written = $this->database->transactional(function () use ($duplicates): array {
            $repaired = 0;

            foreach ($duplicates as $group) {
                // Step 2 and step 3, together and per row: the key, the digest
                // and the status are rewritten in one statement, so a run
                // interrupted between them cannot leave a rewritten key on an
                // active grant or a stale digest on a rewritten key.
                foreach ($group['rows'] as $row) {
                    if ($row['id'] === $group['kept_id']) {
                        continue;
                    }

                    $repaired += $this->repair(
                        $row['id'],
                        $group['source'],
                        SourceRef::duplicateRepairRef($group['source_ref'], $row['id']),
                        $row['status'],
                    );
                }
            }

            return ['repaired' => $repaired, 'digested' => $this->digestEveryRow()];
        });

        return [
            'duplicates' => $duplicates,
            'repaired' => $written['repaired'],
            'digested' => $written['digested'],
            'dry_run' => false,
        ];
    }

    /**
     * Step 1: every (source, source_ref) pair held by more than one row, with
     * enough of each row for a person to recognise it.
     *
     * @return list<DuplicateGroup>
     */
    public function findDuplicates(): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['id', 'user_id', 'course_id', 'source', 'source_ref', 'status', 'created_at'])
                ->whereNotNull('source_ref')
                ->orderBy('id'),
        );

        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) $row['source'] . "\0" . (string) $row['source_ref'];
            $grouped[$key][] = [
                'id' => (int) $row['id'],
                'user_id' => (int) $row['user_id'],
                'course_id' => (int) $row['course_id'],
                'status' => (string) ($row['status'] ?? ''),
                'created_at' => $row['created_at'] === null ? null : (string) $row['created_at'],
            ];
        }

        $duplicates = [];

        foreach ($grouped as $key => $group) {
            if (\count($group) < 2) {
                continue;
            }

            [$source, $sourceRef] = explode("\0", $key, 2);
            $duplicates[] = [
                'source' => $source,
                'source_ref' => $sourceRef,
                'kept_id' => self::electKept($group),
                'rows' => $group,
            ];
        }

        return $duplicates;
    }

    /**
     * Which row of a duplicate group keeps the canonical key.
     *
     * The earliest **active** row, and only when the group holds none, the
     * earliest row of any status. Choosing by id alone is how a refund
     * followed by a repurchase ends with the refunded row keeping the key and
     * the paid one revoked, which leaves the learner with no access at all.
     * A group with no active row is elected rather than refused, because
     * refusing would wedge the upgrade on exactly the sites whose duplicates
     * are all historic, and the elected row is already revoked either way.
     *
     * @param list<DuplicateRow> $rows
     */
    private static function electKept(array $rows): int
    {
        foreach ($rows as $row) {
            if ($row['status'] === Entitlement::STATUS_ACTIVE) {
                return $row['id'];
            }
        }

        return $rows[0]['id'];
    }

    /**
     * Step 4: the digest for every row that has none.
     *
     * Chunked and re-read each pass rather than paged by offset, because the
     * predicate is "still has no digest" and rows leave that set as the pass
     * writes them, which is exactly the case an offset cursor skips rows in.
     */
    private function digestEveryRow(): int
    {
        $written = 0;

        while (true) {
            $rows = $this->database->selectAll(
                Query::from(self::TABLE)
                    ->select(['id', 'source', 'source_ref'])
                    ->whereNull('source_ref_digest')
                    ->whereNotNull('source_ref')
                    ->orderBy('id')
                    ->limit(self::CHUNK),
            );

            if ($rows === []) {
                return $written;
            }

            foreach ($rows as $row) {
                $this->database->update(self::TABLE, [
                    'source_ref_digest' => SourceRef::digest((string) $row['source'], (string) $row['source_ref']),
                ], ['id' => (int) $row['id']]);

                $written++;
            }
        }
    }

    private function countUndigested(): int
    {
        return $this->database->count(
            Query::from(self::TABLE)->whereNull('source_ref_digest')->whereNotNull('source_ref'),
        );
    }

    private function repair(int $id, string $source, string $repairedRef, string $status): int
    {
        $values = [
            'source_ref' => $repairedRef,
            // In the same statement as the key it belongs to. A digest left
            // behind on a rewritten key is the winner's digest, and step 4
            // then writes that same digest onto the winner and is refused by
            // the unique index, half way through a migration.
            'source_ref_digest' => SourceRef::digest($source, $repairedRef),
            'status' => Entitlement::STATUS_REVOKED,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        // A row already revoked keeps the reason it carried, and the column is
        // left out of the statement rather than rewritten with a null. The
        // reason a person was refunded is a fact, and this repair is not the
        // thing that revoked them.
        if ($status !== Entitlement::STATUS_REVOKED) {
            $values['revoked_reason'] = self::REPAIR_REASON;
        }

        return $this->database->update(self::TABLE, $values, ['id' => $id]);
    }
}
