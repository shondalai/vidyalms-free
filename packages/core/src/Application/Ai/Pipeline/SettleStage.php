<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunOutcome;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * Stage 10. Writes the run's ending to the ledger.
 *
 * It runs after a halt, which is the whole reason the interface has that
 * question on it: a run row is written for every run including the refused and
 * the previewed ones, and without it a policy denial would leave nothing behind
 * for the refusal counts, the right-to-know view or the site owner asking why
 * nothing happened.
 *
 * **A run halted before the reservation stage still gets its row here.** The
 * reservation exists to hold headroom, and a run refused before it needed any
 * held none; but it happened, and counting refusals without their reason cannot
 * tell a site whether it is over budget, unconsented or rate limited. So this
 * stage reserves and settles in one go for that case, in the terminal state, with
 * no reserved amount.
 *
 * **A deferred run is left reserved and is not settled.** A defer is "not now",
 * the job carries the resume instant, and the resumed attempt is what settles the
 * row. Settling it here would make a ceiling that says "not now"
 * indistinguishable from one that says "never".
 *
 * **The spend is written on every terminal path.** An import that fails costs
 * nothing; an AI run that fails has already paid the upstream tokens, and a
 * ledger row saying only "failed" leaves a site owner unable to explain a bill.
 */
final class SettleStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly int $retentionDays = EstimateAndReserveStage::DEFAULT_RETENTION_DAYS,
    ) {
    }

    public function name(): string
    {
        return 'Settle';
    }

    public function runsAfterHalt(): bool
    {
        return true;
    }

    public function handle(AiPipelineRun $run): void
    {
        if ($run->deferUntil !== null) {
            // A run deferred by a windowed ceiling is deferred at stage 2,
            // four stages before anything reserves, so it reaches here with no
            // row at all: the caller then read run 0 and got a 404 for an
            // answer that is not an error. "A deferred run is left reserved"
            // has to be true of the run that never got as far as reserving
            // too, so the row is written and deliberately not settled.
            $run->runId ??= $this->reserveForRefusal($run);

            return;
        }

        if ($run->runId === null) {
            $run->runId = $this->reserveForRefusal($run);
        }

        if ($run->runId === null) {
            return;
        }

        // The manifest of what actually left, when a bridge caller published
        // one. The reserved row carries the manifest of the payload the
        // pipeline resolved, written before the task ran and inside the ceiling
        // transaction, and a model-backed task may hand the caller a payload of
        // its own. The record and the wire are made to agree here, on the one
        // surface a learner reads.
        if ($run->manifestOnTheWire !== null) {
            $this->runs->recordManifest($run->runId, $run->manifestOnTheWire, $run->now);
        }

        $this->runs->complete($run->runId, $this->outcomeOf($run), $run->now);
    }

    private function outcomeOf(AiPipelineRun $run): AiRunOutcome
    {
        if ($run->errorCode === 'cancelled') {
            return AiRunOutcome::cancelled(
                $run->errorMessage ?? 'The site-wide stop halted this run.',
                $run->spent,
            );
        }

        if ($run->refusal !== null) {
            return AiRunOutcome::refused(
                $run->refusal->kind->value,
                $run->refusal->messageKey,
                $run->spent,
            );
        }

        if ($run->errorCode !== null) {
            return AiRunOutcome::failed(
                $run->spent,
                $run->errorCode,
                $run->errorMessage ?? 'The run failed and reported no message.',
            );
        }

        if ($run->request->dryRun) {
            return AiRunOutcome::previewed();
        }

        // The provenance the bridge answered with, rather than the six literal
        // nulls this stage used to pass. `ai_runs` declares every one of these
        // columns and indexes `provider_request_id` for the reconciliation
        // join, so writing nulls made the sweep, the review screen's prompt
        // reference and the usage report read an empty column beside an index
        // built for it. It stays null when no bridge answered, which is the
        // free build's ordinary case and is a real answer rather than a zero.
        $bridge = $run->bridgeResult;

        return AiRunOutcome::succeeded(
            $run->spent,
            $bridge?->tokensIn,
            $bridge?->tokensOut,
            $run->schemaValid,
            $run->model,
            $bridge?->promptRef,
            $bridge?->providerRequestId,
            $bridge?->providerFingerprint,
            $bridge?->finishReason,
            null,
            $bridge !== null && $bridge->unbilled,
        );
    }

    /**
     * Writes the row for a run that was refused before it ever reached the
     * reservation stage.
     *
     * The reserved amount is null rather than zero, because nothing was held and
     * a zero would be a reservation the ceiling window then had to carry.
     *
     * **The key is the run's own identity and not a clock reading**, and the
     * write is guarded the way the reservation stage already guards its own.
     * Keyed on the instant, two refusals at one tick minted the same key: the
     * second raised a unique-constraint violation out of the pipeline and
     * answered 500, on the path whose whole point is that a refusal is an
     * ordinary outcome. A ledger row that cannot be written is a note and
     * never an exception, because losing the record must not also lose the
     * refusal.
     */
    private function reserveForRefusal(AiPipelineRun $run): ?int
    {
        try {
            return $this->reserveRefusalRow($run);
        } catch (\Throwable $unwritable) {
            $run->note(
                \Shondalai\VidyaLMS\Core\Application\Platform\ImportNote::WARNING,
                'The ledger row for this refusal could not be written: ' . $unwritable->getMessage(),
            );

            return null;
        }
    }

    private function reserveRefusalRow(AiPipelineRun $run): int
    {
        return $this->runs->reserve(
            AiRunReservation::create(
                $run->runKey('refused'),
                $run->request->taskKey,
                $run->inputsDigest(),
                $run->now->modify('+' . max(
                    EstimateAndReserveStage::RETENTION_FLOOR_DAYS,
                    $this->retentionDays,
                ) . ' days'),
                1,
                $run->request->jobId,
                $run->placementKey,
                null,
                'null',
                null,
                0,
                $run->request->principal->isGuest() ? null : $run->request->principal->userId,
                $run->request->actorKind,
                $run->request->ruleId,
                $run->request->onBehalfOfUserId,
                $run->request->task->subjectType,
                $run->request->task->subjectId,
                $run->request->courseId,
                $run->request->organisationId(),
                $run->request->locale,
                $run->manifest === [] ? null : ['fields' => $run->manifest],
                1,
                $run->request->dryRun,
                CostRecord::UNIT_NONE,
                null,
            ),
            $run->subjects,
        );
    }
}
