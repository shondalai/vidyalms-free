<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The sink a long-running AI task reports through while it runs.
 *
 * It mirrors ImportProgress exactly, because the job model is the import
 * pipeline copied rather than reinvented: everything a run produces goes
 * through here rather than being returned at the end, which is what makes a
 * four-minute course build observable and a failed one honest. Notes reach the
 * job log as they happen, and the counts and the spend recorded before a
 * failure survive it.
 *
 * Three members the importer's sink has no use for:
 *
 * - drafted() takes a candidate rather than a row, because a task never writes
 *   a table and a draft written by anything but the pipeline would escape the
 *   run's own ledger.
 * - spent() takes a CostRecord rather than an integer of credits, because the
 *   unit belongs to the bridge and not to the core. A bridge that meters
 *   nothing says so, and a run that spent and then failed still records what it
 *   spent: an import that fails costs nothing and an AI job that fails has
 *   already paid the upstream tokens.
 * - stopRequested() is polled between units, so the site-wide Article 14 stop
 *   halts a long build within one unit rather than at the end of it. It is a
 *   question and never a cached value, for the reason CancellationSignal gives.
 *
 * A task holds no state of its own between calls; the run's state lives on this
 * object, which is why the job id and the dry-run flag are read from here and
 * never from the provider.
 */
interface AiRunProgress
{
    /**
     * The AI job this run belongs to, or 0 when the task is running inline
     * outside one. Pass it to the job map so the job's manifest is complete,
     * which is what makes a resumed build an update rather than a second set of
     * drafts.
     */
    public function jobId(): int;

    /**
     * True when the run must analyse only: resolve the grounding set, render
     * the redaction manifest, estimate the cost, and call no bridge and write
     * no artefact. The dry run is the privacy artefact and it costs nothing.
     */
    public function isDryRun(): bool;

    /**
     * Appends one note, which reaches the job log immediately.
     *
     * @param string $level one of the ImportNote level constants
     */
    public function note(string $level, string $message): void;

    /**
     * Adds to a running total.
     *
     * The keys are provider chosen, which is why ai_jobs.totals carries a
     * mirrored version column: a task naming its own counters means the shape
     * is not engine owned, and pretending otherwise is how an unreadable column
     * happens.
     */
    public function counted(string $what, int $delta = 1): void;

    /**
     * One thing the task proposes. Nothing is written to a domain table here:
     * a candidate becomes an ai_drafts row and becomes an artefact only when a
     * named human accepts it.
     */
    public function drafted(AiDraftCandidate $candidate): void;

    /**
     * What this unit cost, in the unit the bridge declared. A record rather
     * than a number, because the core knows what a unit is without knowing what
     * any one provider's product unit is worth.
     */
    public function spent(CostRecord $cost): void;

    /**
     * Whether the run has been told to stop, asked between units rather than
     * read once. A stop set four minutes into a build is exactly the case this
     * exists for, and a value captured at construction could not see it.
     */
    public function stopRequested(): bool;
}
