<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * Where import jobs are stored.
 *
 * **This is a core-owned port, not a third-party seam.** It is stated here
 * because R4 added a fifth, defaulted parameter to create(), and a default
 * protects callers rather than implementers: PHP fatals when a class
 * implementing an interface declares fewer parameters than the interface does,
 * so a repository written against the pre-R4 shape would stop loading on
 * upgrade. That is the right trade only because nothing outside this
 * repository is meant to implement it. The two shipped implementations are the
 * SQL one and the test double, both in this tree, and both hosts reach import
 * storage through CoreKernel rather than through a repository of their own.
 *
 * An extension point that a third party does implement takes the opposite
 * rule, and every other new behaviour in this wave followed it: RecordsRowProblems,
 * KnowsItsOrganisation, DeclaresSourceTypes, HonoursUpsertPolicy and
 * PreflightsBeforeWriting are all capability interfaces probed with instanceof,
 * precisely so that ImporterInterface and ImportProgress could stay exactly as
 * they shipped.
 */
interface ImportJobRepository
{
    /**
     * Creates a pending job and returns its id.
     *
     * The organisation is stamped from the run context and is never read out
     * of $options: options are the caller's own payload, and an organisation
     * taken from there is an org administrator enrolling people into somebody
     * else's organisation. Null is the site, which is what a migration started
     * from the site console is.
     *
     * @param array<string, mixed> $options
     */
    public function create(
        string $importer,
        ?string $sourcePath,
        array $options,
        int $createdBy,
        ?int $organisationId = null,
        ?int $organisationUnitId = null,
    ): int;

    public function markRunning(int $id): void;

    /**
     * Writes what the run has produced so far without ending it, so an admin
     * watching a long import sees the log grow. Called once per note.
     *
     * It is also how a run that finished after an operator ended the job
     * records what it did: the status is theirs, and this is the write that
     * leaves it alone. Implementations must not touch status or finished_at.
     *
     * @param array<string, mixed> $totals
     */
    public function recordProgress(int $id, string $log, array $totals): void;

    /**
     * Moves the heartbeat and writes nothing else, for the progress that has
     * nothing to say: a counting phase and a dry run produce no notes, and a
     * job that stopped moving updated_at is read as one that stopped running.
     */
    public function touch(int $id): void;

    /**
     * @param array<string, mixed> $totals
     */
    public function markCompleted(int $id, string $log, array $totals): void;

    /**
     * Ends a dry run. The totals are what the importer would have created;
     * nothing was written, so the job is a preview and not an applied import.
     *
     * @param array<string, mixed> $totals
     */
    public function markPreviewed(int $id, string $log, array $totals): void;

    /**
     * Ends a failed run. The totals are what the importer completed before it
     * failed, which is kept rather than rolled back: those rows exist, and the
     * import map is what makes running the source again update them.
     *
     * @param array<string, mixed> $totals
     */
    public function markFailed(int $id, string $log, array $totals): void;

    /**
     * Ends a job only while it is still running, and answers whether it did.
     *
     * A run that finishes after an operator ended the job must lose: the
     * status a person set is final. Guarding the write rather than reading
     * first makes that true by construction, with no window between the read
     * and the write for the operator's decision to be lost in. The operator's
     * own markFailed() is deliberately unguarded, because they are the one
     * whose write should win.
     *
     * @param array<string, mixed> $totals
     */
    public function finishIfRunning(int $id, string $status, string $log, array $totals): bool;

    public function find(int $id): ?ImportJob;

    /**
     * The most recent jobs first.
     */
    public function recent(int $page, int $perPage): ImportJobPage;

    /**
     * Sources no job needs any more, keyed by source with the ids that name
     * it. A source qualifies only when every job naming it has finished and
     * the last of them finished before $cutoff, so the file behind a run that
     * is still queued, still running, or still inside the retention window is
     * never offered up. At most $limit jobs are considered per call.
     *
     * @return array<string, list<int>>
     */
    public function releasedSourcesBefore(\DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Forgets a source the file for which no longer exists. The job keeps its
     * log and its totals: what the run did is history, where its input used to
     * be is not.
     */
    public function clearSourcePath(int $id): void;
}
