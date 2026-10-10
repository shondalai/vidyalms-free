<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * Where an import's bad rows go.
 *
 * Not into the job log, and the reason is mechanical. The log is rewritten
 * whole on every progress flush, so ten thousand bad rows in a forty thousand
 * row file make the flush quadratic and the run's own progress reporting
 * becomes the slowest thing in the import. Rows go here, appended, and the log
 * gets one summary note per file.
 *
 * Rows cross this boundary as maps in Application\Platform\RowProblem's own
 * serialised shape; see ImportRowProblemPage for why.
 */
interface ImportRowProblemRepository
{
    /**
     * Appends what a run has found so far.
     *
     * Called as the run goes rather than at the end, for the same reason the
     * import map is written before the next row is read: a process that is
     * killed on row nine thousand must leave behind what it already knew.
     * Appending is safe to repeat only in the sense that a re-run of the whole
     * job clears first; this method never deduplicates, because two identical
     * problems on two rows are two problems.
     *
     * @param list<array<string, mixed>> $problems RowProblem::toArray() shape
     */
    public function append(int $jobId, array $problems): void;

    /**
     * One page, by file and then by row, optionally narrowed to one code or
     * one outcome.
     *
     * By file first, because a OneRoster package is eight files and an
     * operator fixes one of them at a time; by row inside it, because that is
     * the order their spreadsheet is in and the whole reason the row number is
     * carried. Ordering by row alone would interleave enrollments.csv row 2
     * with users.csv row 4, which is a report of nothing in particular.
     */
    public function page(
        int $jobId,
        int $page,
        int $perPage,
        ?string $code = null,
        ?string $outcome = null,
    ): ImportRowProblemPage;

    /**
     * Every row the run did not write, oldest first, for the failed-rows
     * download. Bounded, because the download is built in memory and a file
     * whose every row failed would otherwise be reconstructed whole.
     *
     * @return list<array<string, mixed>> RowProblem::toArray() shape
     */
    public function rejected(int $jobId, int $limit): array;

    /**
     * Forgets a job's rows.
     *
     * Called when the job's source is pruned, because value_excerpt is a
     * verbatim copy of a CSV line that may hold a name or an address: keeping
     * the excerpt after the file it was copied from has been deleted would
     * make the retention window a fiction. Called again when a job is re-run,
     * so a second run's report is the second run's.
     */
    public function forgetJob(int $jobId): void;
}
