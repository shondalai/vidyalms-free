<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/**
 * The housekeeping sweep that removes uploaded import sources once nobody is
 * going to run them again.
 *
 * Of the two ways to bound the pile, deleting the moment a job reaches a
 * terminal state and keeping the file for a window, this is the window, and
 * deliberately: a terminal job is exactly the one an operator re-runs. The
 * documented recovery from a half-finished import is to run the same source
 * again and let the import map turn the second run into an update, and both
 * adapters' Import screens offer that as a Retry button on a finished job.
 * Deleting on completion would take the source away from the only button that
 * needs it. So the source lives for a configured number of days after the last
 * run that used it finished, and is then removed by the scheduled routine that
 * already prunes spent idempotency keys and finished queue rows.
 *
 * A source is released only when every job naming it has finished and the last
 * of them finished before the cutoff, so a retry queued this morning against a
 * job that completed last month keeps the file. The rows that named it are
 * left in place with their log and their totals; only source_path is cleared,
 * which is what stops a later Retry from queueing a run against bytes that are
 * gone.
 *
 * Scheme sources ("cms-db:", "learndash-db:") are not files and no file of
 * theirs is deleted: nothing was uploaded, and the source string stays valid
 * for a re-run for ever. Their row report is swept all the same, on the job's
 * own terminal age, because value_excerpt is a verbatim source cell whichever
 * kind of source it was copied out of.
 *
 * Like the entitlement sweep, this has no caller principal. It is the host's
 * scheduler calling, not a person, and it takes no decision a capability
 * check would guard: it removes files the retention window already released.
 */
final class PruneImportSources
{
    public const DEFAULT_RETENTION_DAYS = 30;

    /**
     * How many finished jobs one pass considers. The sweep runs on a schedule,
     * so a backlog drains over several passes rather than holding one request
     * open while it deletes ten thousand files.
     */
    public const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly StoragePort $storage,
        private readonly ClockPort $clock,
        /**
         * The row report is pruned with the source it quotes.
         *
         * import_row_problems.value_excerpt is a verbatim copy of a CSV line
         * that may hold a name or an email address, so keeping it after the
         * file it was copied from has been deleted would make the retention
         * window a fiction: the personal data would outlive its source by
         * exactly as long as the job row lives, which is for ever. Optional so
         * a caller built before the table existed still constructs.
         */
        private readonly ?\Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemRepository $rowProblems = null,
    ) {
    }

    /**
     * @return array{deleted: int, cleared: int, forgotten: int, retention_days: int}
     */
    public function execute(
        int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $retentionDays = max(1, $retentionDays);
        $cutoff = $this->clock->now()->sub(new \DateInterval('P' . $retentionDays . 'D'));

        $deleted = 0;
        $cleared = 0;

        $forgotten = 0;

        foreach ($this->jobs->releasedSourcesBefore($cutoff, max(1, $limit)) as $source => $jobIds) {
            // The row report ages on the job's own clock, not on the deletion
            // of an uploaded file. Every one of the seven database-source
            // importers takes a scheme source, so this branch used to skip
            // them all and their row problems, quoting learner ids and source
            // cells, lived exactly as long as the job row, which is for ever.
            // A released job is one that finished before the cutoff, which is
            // the retention window the whole class is about.
            foreach ($jobIds as $jobId) {
                $this->rowProblems?->forgetJob($jobId);
                $forgotten++;
            }

            if (!self::isStoredFile($source)) {
                continue;
            }

            // A source already gone is still worth clearing: the file may have
            // been removed by hand, or by a previous pass whose limit cut off
            // one of the rows naming it.
            $this->storage->delete($source);
            $deleted++;

            foreach ($jobIds as $jobId) {
                $this->jobs->clearSourcePath($jobId);
                $cleared++;
            }
        }

        return [
            'deleted' => $deleted,
            'cleared' => $cleared,
            'forgotten' => $forgotten,
            'retention_days' => $retentionDays,
        ];
    }

    /**
     * The source convention has two shapes: a private storage path and a
     * scheme URI. Only the first one put bytes on disk.
     */
    private static function isStoredFile(string $source): bool
    {
        if (!str_starts_with($source, 'imports/')) {
            return false;
        }
        try {
            \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($source);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
