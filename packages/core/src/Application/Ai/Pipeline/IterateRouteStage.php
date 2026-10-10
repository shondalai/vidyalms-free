<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiBridgeCaller;
use Shondalai\VidyaLMS\Core\Application\Ai\AiBridgeCallerFactory;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunProgress;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * Stage 7. Runs the task.
 *
 * On the free build every task is deterministic and calls no model, so this
 * stage is where the free path meets the same ordering a model-backed task will:
 * the run is already reserved, the facts are already resolved, the manifest is
 * already rendered, and the task is handed a context carrying none of the
 * database, the Principal or a bridge handle.
 *
 * **A dry run stops here without running the task.** The dry run's product is
 * the manifest, which the previous stages already produced, and running the task
 * would be doing the work the preview exists to avoid. That is also why a
 * preview costs nothing and why previews get their own generous rate window
 * rather than borrowing the real budget: charging a person for exercising the
 * privacy control the design sells would be exactly backwards.
 *
 * **There is no streaming here, and that is a decision.** Every generative task
 * is map-reduced into units small enough for a buffered call, so progress comes
 * from the job log rather than from tokens appearing. Three problems resolve at
 * once: a buffered call fits inside a non-streaming timeout, the
 * bytes-on-the-wire settlement case cannot arise on a path that does not stream,
 * and map-reduce is the injection containment pattern, because a poisoned source
 * affects only its own output.
 *
 * A task that throws is a failure and never a refusal. An exception out of a
 * provider is programmer error, and dressing one up as a typed refusal would put
 * a bug in the same countable bucket as a ceiling.
 */
final class IterateRouteStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly string $siteRef,
        private readonly ?AiRunProgress $progress = null,
        /**
         * Where the run's one bridge caller is built.
         *
         * Trailing and defaulted so a stage assembled by hand still
         * constructs; absent, `hasBridge()` answers false and a model-backed
         * task refuses, which is what a build with no model access is.
         */
        private readonly ?AiBridgeCallerFactory $callers = null,
    ) {
    }

    public function name(): string
    {
        return 'IterateRoute';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        if ($run->request->dryRun) {
            $run->note(
                ImportNote::INFO,
                'Dry run: the manifest above is exactly what would be sent, and no bridge was called.',
            );

            return;
        }

        $task = $run->task;

        if ($task === null) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.task.unregistered', [
                'task' => $run->request->taskKey,
            ]);

            return;
        }

        if ($run->runId !== null) {
            $this->runs->markRunning($run->runId, $run->now);
        }

        $callerRef = hash('sha256', $this->siteRef . $run->request->principal->userId);
        // Held rather than passed straight in, because the two things it knows
        // that nothing else does, what actually left and what came back, have
        // to be read back off it after the task has run.
        $caller = $this->callers?->forRun($run, $callerRef);
        $context = AiTaskContext::create(
            $callerRef,
            $run->runId === null ? '' : (string) $run->runId,
            $run->now,
            $run->deadline,
            $run->placementKey,
            null,
            1,
            $run->request->locale,
            $run->reserved,
            false,
            1,
            $run->facts,
            // The stop, threaded from the pipeline that already holds it. The
            // member was seated a wave early precisely so a task could be
            // written to ask, and both production call sites stopped at the
            // twelfth argument, so every task was told to continue for ever.
            $run->stop,
            // The caller, built here and nowhere else. Nothing seated one, so
            // a model-backed task could not reach a model at all and the whole
            // stage-C claim of zero core edits was false.
            $caller,
            // The payload the grounding stage resolved and the redact stage
            // rendered the manifest from. Without it a task declaring
            // RequiresGrounding could compose a call carrying the chunk keys
            // as authored options and none of the chunk text, so the run's
            // manifest named sources the wire never carried.
            $run->payload,
        );

        try {
            $result = $task->run($run->request->task, $context);
        } catch (\Throwable $broke) {
            $this->recordWhatLeft($run, $caller);
            $run->fail('task_threw', sprintf(
                'The task "%s" threw: %s',
                $run->request->taskKey,
                $broke->getMessage(),
            ));

            return;
        }

        $this->recordWhatLeft($run, $caller);

        $run->result = $result;
        $run->spent = $result->cost;

        foreach ($result->warnings as $warning) {
            $run->note(ImportNote::WARNING, $warning);
        }

        if ($result->refusal !== null) {
            $run->refuse(
                $result->refusal->kind,
                $result->refusal->messageKey,
                $result->refusal->parameters,
            );

            return;
        }

        foreach ($result->drafts as $candidate) {
            $this->progress?->drafted($candidate);
        }

        $this->progress?->spent($result->cost);
    }

    /**
     * The two facts only the caller holds, read back onto the run.
     *
     * **The manifest first, and this is the containment half.** The redact
     * stage renders a manifest from the payload the pipeline resolved, and a
     * model-backed task is free to hand the caller a different payload: its own
     * authored options, which are not narrowed the way facts are. The caller
     * redacts whatever it is given and publishes the manifest of exactly that,
     * and its own docblock says the record and the wire cannot disagree. They
     * could, because nothing outside two unit tests ever read it, so the ledger
     * row named the run's payload while the wire carried the task's.
     *
     * **Then the provenance.** `ai_runs` declares `provider_request_id`,
     * `provider_fingerprint`, `prompt_ref`, `finish_reason` and two token
     * counts, and indexes the first, because the reconciliation sweep joins on
     * it. The settle stage passed literal nulls into every one of them, so only
     * the attempt row carried them and the sweep had nothing to join.
     */
    private function recordWhatLeft(AiPipelineRun $run, ?AiBridgeCaller $caller): void
    {
        if ($caller === null) {
            return;
        }

        $manifest = $caller->lastManifest();

        if ($manifest !== []) {
            $run->manifestOnTheWire = $manifest;
        }

        $run->bridgeResult = $caller->lastResult();
    }
}
