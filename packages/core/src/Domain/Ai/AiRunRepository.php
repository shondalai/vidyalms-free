<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * Where AI runs, their attempts and their subjects are WRITTEN.
 *
 * **This is a core-owned port, not a third-party seam.** The two shipped
 * implementations are the SQL one and the test double, both in this tree, and
 * both hosts reach AI storage through CoreKernel rather than through a
 * repository of their own. A seam a third party implements takes the opposite
 * rule and arrives as a capability interface probed with instanceof, which is
 * how every AI extension point in this wave is shaped.
 *
 * It is deliberately the write side plus the two reads the write side needs.
 * Screen reads (the run log, the usage window, the learner's activity page)
 * belong to the AI read model and are not restated here: two objects answering
 * the same question with different SQL is how a report and a ceiling end up
 * disagreeing about what a run cost.
 *
 * The write order is load bearing and is stated here rather than left to each
 * caller: reserve() first and inside the same transaction as the ceiling
 * evaluation, then the attempts as they are opened, then complete() once. A
 * ledger written after the call cannot record a call that never came back.
 */
interface AiRunRepository
{
    /**
     * Writes the reserved row and its subject rows, and returns the run id.
     *
     * The subjects go in inside the SAME transaction as the run, because a
     * subject row that can arrive later is a subject row that can be missing
     * when the right-to-know view asks. The caller wraps this in
     * DatabasePort::transactional() together with the ceiling read, so two
     * concurrent runs cannot both see the same headroom.
     *
     * @param list<AiRunSubject> $subjects
     */
    public function reserve(AiRunReservation $reservation, array $subjects = []): int;

    public function markRunning(int $id, \DateTimeImmutable $at): void;

    /**
     * Rewrites the redaction manifest of a run that has already been reserved.
     *
     * **The reserved row cannot carry the true manifest and that is not a bug
     * in the ordering.** The reservation is written before the task runs,
     * inside the transaction the ceiling is evaluated in, so what it records is
     * the manifest of the payload the pipeline resolved. A model-backed task is
     * handed the bridge caller and may hand it a payload of its own, and the
     * caller redacts and sends exactly that; its own contract is that the
     * record and the wire cannot disagree. They could, because the ledger row
     * was never revisited, so the learner's right-to-know view rendered the
     * fields the pipeline resolved while the wire had carried the task's.
     *
     * Called once, on the way out, with what actually left. A run that called
     * no bridge is left exactly as reserved.
     *
     * @param list<string> $fields
     */
    public function recordManifest(int $id, array $fields, \DateTimeImmutable $at): void;

    /**
     * Writes one attempt row. Called before the success test, and again with the
     * closed attempt afterwards, upserting on (run_id, attempt_no).
     */
    public function recordAttempt(AiRunAttempt $attempt): void;

    /**
     * How many attempts this run has already recorded.
     *
     * The runner asks before writing its own synthetic attempt, because the
     * bridge caller numbers its rows from one as well: writing over the top of
     * a real call's row replaced the upstream reference, the token counts and
     * the auto-retry flag with nulls, which is exactly the row an outage
     * post-mortem was going to read.
     */
    public function attemptCount(int $runId): int;

    /**
     * Ends the run and records what it spent, whichever terminal state it
     * reached. Failed runs record their spend too, which is the one rule the
     * import pipeline does not have: an import that fails costs nothing and an
     * AI run that fails has already paid the upstream tokens.
     */
    public function complete(int $id, AiRunOutcome $outcome, \DateTimeImmutable $at): void;

    /**
     * Appends one operational line to a run.
     *
     * ai_runs carries no dedicated note column, and error_message is the only
     * free-text column on the row, so a recall note lands there. error_code is
     * deliberately left alone, so a query counting real errors still counts only
     * those.
     */
    public function note(int $id, string $note): void;

    public function find(int $id): ?AiRun;

    public function findByKey(string $runKey): ?AiRun;

    /**
     * Every run one job produced, oldest first. Read by the job runner to total
     * a build's spend, which is why it is here and not on the read model.
     *
     * @return list<AiRun>
     */
    public function forJob(int $jobId): array;

    /**
     * The windowed spend the scope ceilings read.
     *
     * It sums GREATEST(cost_charged, cost_reserved) over one cost unit and over
     * the states AiRunState::billableStates() names, which is what makes a
     * ceiling bind under fan-out: cost_charged is written at the terminal state,
     * so a window that read only the settled figure would pass every concurrent
     * unit on the same unsettled zero.
     *
     * $scopeColumn is a real column name (course_id, organisation_id,
     * principal_user_id) and an implementation refuses anything else, because a
     * ceiling whose scope came from a caller's string is an injection.
     */
    public function spentInWindow(
        string $costUnit,
        \DateTimeImmutable $since,
        ?string $scopeColumn = null,
        ?int $scopeId = null,
    ): float;

    /**
     * The same window as a count of rows rather than a sum, so a bridge that
     * meters nothing still has a scope ceiling. The state list is explicit
     * because the preview window is a different, generous one over Previewed
     * alone.
     *
     * @param list<string> $states
     */
    public function countInWindow(
        \DateTimeImmutable $since,
        array $states,
        ?string $scopeColumn = null,
        ?int $scopeId = null,
    ): int;

    /**
     * Runs whose retention instant has passed, oldest first, capped.
     *
     * @return list<int>
     */
    public function expiredBefore(\DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Deletes runs and everything that hangs off them: attempts, subjects,
     * drafts and findings.
     *
     * ai_artefact_provenance is deliberately untouched, which is what makes the
     * editorial-responsibility record outlive the prompt log that produced it.
     *
     * @param list<int> $runIds
     */
    public function purge(array $runIds): int;

    /**
     * Runs left non-terminal past their deadline, whose reservation the retain
     * sweep releases.
     *
     * @return list<int>
     */
    public function abandonedBefore(\DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Releases a stuck reservation so it stops occupying the ceiling window.
     *
     * The run is recorded failed rather than deleted: it happened, and a site
     * owner reading a ceiling needs to know why the headroom moved.
     */
    public function releaseReservation(int $id, \DateTimeImmutable $at): void;
}
