<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * Gives every seat assignment that has no opaque reference the one the unique
 * index is carried on.
 *
 * A clean install finds nothing to do: the column and its index come from the
 * compiled schema and every row written since has been minted with one. What
 * it repairs is a row that predates the column on a site that upgraded, and a
 * row whose mint failed half way, and both hosts run it from their own upgrade
 * path for that reason rather than because a particular release needed it.
 *
 * It is a core use case rather than an UPDATE inside two migration files, and
 * for the same reason {@see \Shondalai\VidyaLMS\Core\Application\Commerce\BackfillEntitlementDigests}
 * is one: the value has to be minted by **the same helper** the running code
 * mints with. A reference written by a host's own SQL and a reference written
 * by the store must be indistinguishable in length, alphabet and source of
 * randomness, or the column means one thing on a site that installed clean
 * and another on a site that upgraded. There is exactly one mint,
 * {@see OrganisationLearningStore::mintAssignmentRef()}, and this runs it.
 *
 * **A reference is not derivable, so it cannot be recomputed.** The digest
 * backfill could be run twice and write the same digest twice, because a
 * digest is a function of the row. A reference is a fresh draw from the
 * CSPRNG, so a second pass over a row that already has one would replace it
 * and orphan every `source_ref` composed from it. That is why the predicate
 * is "still has no reference" in both the read and the write, and why the
 * write is guarded a second time in the statement itself: two administrators
 * loading wp-admin at the same moment run this concurrently, and the loser of
 * that race must write nothing rather than overwrite the winner.
 *
 * **There is no wrapping transaction, deliberately.** Every row here is
 * independent and every write only ever fills a null, so a run interrupted
 * half way leaves the rows it reached with their references and the rest
 * exactly as they were, and the next run finishes the job. A transaction over
 * a whole table would buy nothing and would hold locks on `seat_assignments`
 * for the length of an upgrade.
 *
 * A dry run counts and writes nothing, so a host can report what is about to
 * happen before anything happens.
 */
final class BackfillAssignmentReferences
{
    /** Rows per pass, so a large site is not read into memory in one go. */
    private const CHUNK = 500;

    /**
     * Mint attempts per row before the run gives up on it.
     *
     * A collision on 32 characters from a CSPRNG is not an outcome anyone
     * will see, but the column carries a unique index and a caller that
     * cannot tell a collision from a bug is a caller that reports the wrong
     * failure. Three draws, then the violation is raised with the index it
     * names still attached.
     */
    private const ATTEMPTS = 3;

    public function __construct(private readonly OrganisationLearningStore $store)
    {
    }

    /**
     * @return array{pending: int, minted: int, dry_run: bool}
     */
    public function execute(bool $dryRun = false): array
    {
        if ($dryRun) {
            return ['pending' => \count($this->store->assignmentsWithoutReference(self::CHUNK)), 'minted' => 0, 'dry_run' => true];
        }

        $minted = 0;

        while (true) {
            // Re-read rather than paged by offset: rows leave this set as the
            // pass writes them, which is exactly the case an offset cursor
            // skips rows in.
            $ids = $this->store->assignmentsWithoutReference(self::CHUNK);

            if ($ids === []) {
                return ['pending' => 0, 'minted' => $minted, 'dry_run' => false];
            }

            $written = 0;

            foreach ($ids as $id) {
                $written += $this->mintFor($id);
            }

            if ($written === 0) {
                // Every row in the page was taken by a concurrent run between
                // the read and the write. Nothing is left for this pass to do
                // and re-reading would loop for ever.
                return ['pending' => 0, 'minted' => $minted, 'dry_run' => false];
            }

            $minted += $written;
        }
    }

    private function mintFor(int $id): int
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->store->giveAssignmentReference($id, $this->store->mintAssignmentRef());
            } catch (UniqueConstraintViolation $collision) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $collision;
                }
            }
        }
    }
}
