<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AiPipelineRun;
use Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AiRunStageInterface;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;

/**
 * The object every AI invariant lives in: an ordered list of named stages,
 * fixed at construction.
 *
 * **It is not injectable by a package, and the word used to be there.** The
 * kernel constructs the twelve stages inline and hands them over; ORDER is a
 * const a conformance test pins; and a KernelReady listener runs after the
 * constructor, so a vendor moderation or grounding stage is a core edit. The
 * grounding stage this wave added is the proof: it changed ORDER and the
 * kernel together.
 *
 * That is a deliberate shape rather than an oversight, and what a vendor does
 * instead is the eleven seams. A moderation step is a bridge capability, a new
 * gate is a policy, a new input is a fact, a stricter redaction is a redactor,
 * a new output is a task with an acceptor. A stage is the ORDER those seams
 * are asked in, and an order a package could reorder is an order none of the
 * nine invariants can be stated over.
 *
 * Nine responsibilities were being assigned in prose to a class nobody had
 * named: the policy acceptance gate, the policy chain, the route candidate
 * iteration, the attempt ledger written before the success test, post-hoc schema
 * validation and the schema_unenforced flag, the auto-retry decision under
 * HonoursIdempotency, the reservation and the settlement, and the promise moved
 * out of the task interface's docblock. Two entry paths need all nine, StartAiRun
 * and AiJobRunner, so leaving it unnamed produces either a duplicated
 * implementation, in which case the redactor-only-path invariant holds on one
 * path and not the other, or an unnamed shared class that is Moodle's
 * process_action with the label removed.
 *
 * **It holds no authorisation of its own**, exactly as ImportRunner holds none.
 * StartAiRun and AiJobRunner each require their own capability at their own
 * front door and then call this.
 *
 * **The stop control is checked between every stage**, not once at the start. A
 * control that halted every task on the site only at the moment a run began
 * would let a four-minute build finish after an operator hit it, and Article 14
 * asks for a stop rather than a request not to start.
 */
final class AiRunPipeline
{
    /**
     * The order, published here because it is the design's own list and because
     * a reader has to be able to see it without opening eleven files.
     *
     * @var list<string>
     */
    public const ORDER = [
        'ResolvePlacement',
        'EvaluatePolicies',
        'AssembleFacts',
        // Between the facts and the redactor, because a chunk is redacted like
        // everything else and its provenance label belongs in the same
        // manifest. Grounding was write-only until it existed: the indexer
        // wrote ai_grounding_chunks and no code path read the table.
        'ResolveGrounding',
        'Redact',
        'NarrowSchema',
        'EstimateAndReserve',
        'IterateRoute',
        'RecordAttempt',
        'ValidateOutput',
        'Settle',
        'WriteDrafts',
    ];

    /** How long an interactive run may take before the deadline stage refuses it. */
    public const DEFAULT_DEADLINE_SECONDS = 120;

    /** @var list<AiRunStageInterface> */
    private readonly array $stages;

    /**
     * @param list<AiRunStageInterface> $stages
     */
    public function __construct(
        array $stages,
        private readonly ClockPort $clock,
        /**
         * Where the per-run discriminator comes from.
         *
         * Optional so a pipeline assembled by hand still constructs; absent,
         * an interactive run falls back to a counter, which is unique inside
         * one process and is what a test needs.
         */
        private readonly ?RandomPort $random = null,
    ) {
        $this->stages = array_values($stages);
    }

    /**
     * The stage names this pipeline actually holds, which a conformance test
     * compares against ORDER.
     *
     * @return list<string>
     */
    public function stageNames(): array
    {
        return array_values(array_map(
            static fn (AiRunStageInterface $stage): string => $stage->name(),
            $this->stages,
        ));
    }

    /**
     * Runs one unit through every stage.
     *
     * $stop is asked between stages rather than read once, so a stop set while a
     * run is in flight is observed by that run. A stopped run records cancelled
     * and never failed, because recording the exercise of an Article 14 control
     * as an ordinary breakage files the evidence in the same list as a genuine
     * one.
     */
    public function run(
        AiRunRequest $request,
        ?CancellationSignal $stop = null,
        ?\DateTimeImmutable $deadline = null,
    ): AiRunReport {
        $now = $this->clock->now();
        $stop ??= CancellationSignal::never();
        $run = new AiPipelineRun(
            $request,
            $now,
            $deadline ?? $now->modify('+' . self::DEFAULT_DEADLINE_SECONDS . ' seconds'),
            $this->nonceFor($request),
        );
        $run->stop = $stop;

        foreach ($this->stages as $stage) {
            if ($run->isHalted() && !$stage->runsAfterHalt()) {
                continue;
            }

            if (!$run->isHalted() && $stop->isCancelled()) {
                $run->fail('cancelled', 'The site-wide stop was set while this run was in flight.');
                $run->note(
                    \Shondalai\VidyaLMS\Core\Application\Platform\ImportNote::WARNING,
                    'Stopped before the ' . $stage->name() . ' stage.',
                );

                // The stop is observed AT this stage's boundary, so this stage
                // is the one that must not run. Falling through to handle()
                // here meant the named stage still executed: a stop observed
                // before IterateRoute still called the task, and one observed
                // before WriteDrafts still wrote its drafts.
                if (!$stage->runsAfterHalt()) {
                    continue;
                }
            }

            $stage->handle($run);
        }

        return new AiRunReport(
            $run->runId ?? 0,
            $this->stateOf($run, $stop),
            $run->spent,
            $run->draftIds,
            // What actually left when a bridge caller published a manifest,
            // and the resolved payload's otherwise. The two differ whenever a
            // model-backed task hands the caller a payload of its own, and the
            // one a person is shown has to be the one that left.
            $run->manifestOnTheWire ?? $run->manifest,
            $run->inputsDigest(),
            $run->refusal,
            $run->deferUntil,
            $run->errorCode,
            $run->errorMessage,
            $run->log(),
        );
    }

    /**
     * The per-run discriminator.
     *
     * A job unit gets none, deliberately: its key is derived so a resume
     * collides with the completed unit and the unique index refuses the
     * duplicate. Everything else gets one, because two runs alike in every
     * stored field are still two runs and the ledger has to hold both.
     */
    private function nonceFor(AiRunRequest $request): string
    {
        if ($request->jobId !== null) {
            return '';
        }

        static $counter = 0;

        ++$counter;

        return ($this->random?->uuid() ?? '') . ':' . $counter;
    }

    private function stateOf(AiPipelineRun $run, CancellationSignal $stop): AiRunState
    {
        return match (true) {
            $run->errorCode === 'cancelled' || ($run->errorCode === null && $stop->isCancelled() && $run->result === null)
                => AiRunState::Cancelled,
            $run->refusal !== null => AiRunState::Refused,
            // A deferral is not a terminal state for the run: the job carries
            // the resume instant and the run row stays reserved until the resumed
            // attempt settles it. Reporting refused here would have made a
            // ceiling that says "not now" indistinguishable from one that says
            // "never".
            $run->deferUntil !== null => AiRunState::Reserved,
            $run->errorCode !== null => AiRunState::Failed,
            $run->request->dryRun => AiRunState::Previewed,
            default => AiRunState::Succeeded,
        };
    }
}
