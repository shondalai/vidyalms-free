<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunAttempt;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;

/**
 * Stage 8. Closes the attempt row for the candidate this run actually used.
 *
 * A model-backed run makes its attempt rows inside AiBridgeCaller, one per
 * candidate in the route chain, written before the success test. A free run
 * makes no bridge call at all, and without this stage the free build would ship
 * an attempt ledger nothing exercises: exactly the seam-nobody-tests failure the
 * free-provider rule exists to prevent.
 *
 * So the deterministic run writes the one attempt it made, against the null
 * bridge, and the aggregations an operator asks ("which route is failing this
 * week", "what did the failed attempts cost this month") answer over free rows
 * before a single Pro bridge exists.
 *
 * **It writes nothing when the run already has attempts.** Both this stage and
 * the caller number from one, and the row is an upsert on (run, attempt), so a
 * task that made a real call had its upstream reference, its token counts and
 * its auto-retry flag overwritten with nulls by the synthetic row that was
 * standing in for the call it did not make.
 *
 * The row is written for a refused and a failed run too, and never only for a
 * successful one. An attempt row that appeared only on success would make
 * winners and losers indistinguishable, which is the whole reason this is a
 * child table rather than a counter on the run.
 */
final class RecordAttemptStage implements AiRunStageInterface
{
    public function __construct(private readonly AiRunRepository $runs)
    {
    }

    public function name(): string
    {
        return 'RecordAttempt';
    }

    /**
     * True, and this is the second stage that runs after a halt.
     *
     * A task that refused and a task that threw both made an attempt, and both
     * are exactly the attempts an outage post-mortem is looking for. A run
     * halted before it was ever reserved has no row to hang an attempt off and
     * is skipped by the guard below rather than by the ordering.
     */
    public function runsAfterHalt(): bool
    {
        return true;
    }

    public function handle(AiPipelineRun $run): void
    {
        if ($run->runId === null || $run->request->dryRun) {
            // A preview attempted nothing. Writing an attempt row for it would
            // put a call that never happened into the aggregation an operator
            // uses to find the route that is failing.
            return;
        }

        if ($this->runs->attemptCount($run->runId) > 0) {
            // The task made real calls and the caller already wrote a row for
            // each. This stage numbers its synthetic attempt one, and so does
            // the caller, so writing anyway replaced the first real call's
            // upstream reference, token counts and auto-retry flag with nulls.
            return;
        }

        $opened = AiRunAttempt::opened(
            $run->runId,
            1,
            $run->bridgeKey,
            $run->now,
            $run->routeId,
            $run->model,
        );

        $this->runs->recordAttempt($opened->closed(
            $run->refusal === null && $run->errorCode === null,
            $run->now,
            $run->refusal?->kind->value,
            $run->errorCode,
            $run->errorMessage,
            null,
            null,
            $run->spent->unit,
            $run->spent->amount,
        ));
    }
}
