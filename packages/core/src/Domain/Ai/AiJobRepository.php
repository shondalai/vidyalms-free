<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * Where AI jobs are stored.
 *
 * A core-owned port, on the same terms as ImportJobRepository, and shaped after
 * it deliberately: the progress write, the bare heartbeat, the guarded finish
 * and the late-finish note are the four writes the import pipeline already
 * proved, and reinventing them would have produced a second answer with
 * different edge cases.
 *
 * Two methods have no import counterpart, and each is here because money or
 * regulation changed the rule:
 *
 * - defer() ends this attempt without ending the job, so a build that trips a
 *   monthly ceiling at item forty keeps its forty drafts under a status that
 *   does not read as a breakage.
 * - recordSpend() writes what the job cost even on a failed ending, because an
 *   import that fails costs nothing and an AI job that fails has already paid
 *   the upstream tokens.
 */
interface AiJobRepository
{
    /**
     * Creates a pending job and returns its id.
     *
     * Exactly one of taskKey and recipeKey may be set, and an implementation
     * refuses both and neither: a job naming no work is a row the drain can only
     * discover at run time.
     *
     * The organisation is stamped from the run context and is never read out of
     * the options map, for the reason QueueImportJob already gives: options are
     * the caller's own payload, and an organisation taken from there is an
     * administrator raising a run against somebody else's tenant.
     *
     * @param array<string, mixed> $options
     */
    public function create(
        ?string $taskKey,
        ?string $recipeKey,
        string $scopeType,
        int $scopeId,
        array $options,
        int $createdBy,
        bool $dryRun = false,
        ?string $placementKey = null,
        ?int $organisationId = null,
    ): int;

    public function markRunning(int $id): void;

    /**
     * Writes what the run has produced so far without ending it, so an admin
     * watching a long build sees the log grow. Called once per note.
     *
     * It is also how a run that finished after an operator ended the job records
     * what it did: the status is theirs, and this is the write that leaves it
     * alone. Implementations must not touch status or finished_at.
     *
     * @param array<string, mixed> $totals
     */
    public function recordProgress(int $id, string $log, array $totals): void;

    /**
     * Moves the heartbeat and writes nothing else, for the progress that has
     * nothing to say. A chunking phase and a dry run produce no notes, and a job
     * that stopped moving updated_at is read as one that stopped running.
     */
    public function touch(int $id): void;

    /**
     * Records what the job has spent so far, in the unit the bridge declared.
     *
     * Separate from the finish writes because a job that fails at item forty has
     * already paid for forty items, and the figure has to survive the ending
     * that follows it.
     */
    public function recordSpend(int $id, string $costUnit, ?float $reserved, ?float $charged): void;

    /**
     * Ends a job only while it is still running, and answers whether it did.
     *
     * A run that finishes after an operator ended the job must lose: the status
     * a person set is final. Guarding the write rather than reading first makes
     * that true by construction, with no window between the read and the write
     * for the operator's decision to be lost in.
     *
     * @param array<string, mixed> $totals
     */
    public function finishIfRunning(int $id, AiJobStatus $status, string $log, array $totals): bool;

    /**
     * Parks a running job until an instant, under the same guard finishIfRunning
     * uses, and answers whether it won.
     *
     * @param array<string, mixed> $totals
     */
    public function deferIfRunning(
        int $id,
        \DateTimeImmutable $resumeAt,
        string $log,
        array $totals,
    ): bool;

    /**
     * Re-opens a deferred job so its next attempt can claim it.
     *
     * Deferred is terminal for the attempt and not for the job, so the resume is
     * this one write plus the scheduler re-arming under the unchanged dedupe
     * key: one row rather than a second job.
     */
    public function reopenDeferred(int $id): bool;

    public function find(int $id): ?AiJob;

    /**
     * Deferred jobs whose resume instant has passed, oldest first.
     *
     * @return list<AiJob>
     */
    public function dueForResume(\DateTimeImmutable $now, int $limit): array;
}
