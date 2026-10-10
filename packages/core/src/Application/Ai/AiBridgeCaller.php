<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\EstimatesCost;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\HonoursIdempotency;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\NegotiatesCapabilities;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\ProducesStructuredOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AiRedactionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;

/**
 * The single holder of a bridge in the entire product.
 *
 * Nothing else may name ModelBridgeInterface or construct a BridgeRequest,
 * and a CI grep asserts it. That is the whole reason this class exists: an
 * earlier design handed every task the bridge and the Principal, which meant
 * the redactor was the only path to a model **only for code that chose to use
 * it**. A task holding the handle composes its own PromptSegment list, and a
 * segment's text is free, so a third-party task could put a learner's display
 * name or an unredacted submission on the wire without touching the redactor,
 * and the structural test asserting otherwise was unwritable because every
 * task legitimately held the handle.
 *
 * So a task is handed this instead, and this does, in order and never
 * optionally:
 *
 * 1. resolves the active redactor from its registry, refusing the run when it
 *    cannot, because an unresolved gate fails closed;
 * 2. builds the RedactedPayload, which is the only thing a BridgeRequest can
 *    be constructed from;
 * 3. narrows the output schema to what the candidate route actually enforces,
 *    recording every weakening;
 * 4. iterates the route chain in position order;
 * 5. writes the attempt row **before** the success test;
 * 6. takes and settles the reservation for that call;
 * 7. validates the returned structure against the original schema, because
 *    the narrowed one was guidance and never the gate.
 *
 * A refusal is an ordinary return value at every step. An exception out of a
 * bridge is programmer error and is not swallowed: the reservation is
 * released and the attempt recorded on the way past, and it keeps going up.
 */
final class AiBridgeCaller
{
    private int $attempt = 0;

    /**
     * What actually left the site on the last call.
     *
     * @var list<string>
     */
    private array $lastManifest = [];

    /**
     * What the last successful call came back with, kept so the run's ledger
     * row can record it.
     *
     * **This is a seam repair and not a convenience.** `ai_runs` declares
     * `provider_request_id`, `provider_fingerprint`, `prompt_ref`,
     * `finish_reason` and the two token counts, and indexes the first, because
     * the design's reconciliation join and the review screen's prompt
     * reference both rest on them. Nothing wrote any of them: `AiTaskResult`
     * carries nothing back from a bridge, so between here and the settle stage
     * there was no object holding the values and the stage passed literal
     * nulls. Only the attempt row had them, and an attempt row is not what the
     * sweep joins on.
     */
    private ?BridgeResult $lastResult = null;

    private function __construct(
        private readonly ModelBridgeRegistry $bridges,
        private readonly AiRedactorRegistry $redactors,
        private readonly AiSchemaNarrower $narrower,
        private readonly AiAttemptLedgerInterface $attempts,
        private readonly AiReservationLedgerInterface $reservations,
        private readonly ClockPort $clock,
        private readonly AiCallEnvelope $envelope,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The runner builds one of these per run and seats it on the task
     * context. A task never builds one, because a task holding the registries
     * is a task holding a bridge one `get()` away.
     */
    public static function forRun(
        ModelBridgeRegistry $bridges,
        AiRedactorRegistry $redactors,
        AiSchemaNarrower $narrower,
        AiAttemptLedgerInterface $attempts,
        AiReservationLedgerInterface $reservations,
        ClockPort $clock,
        AiCallEnvelope $envelope,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $bridges,
            $redactors,
            $narrower,
            $attempts,
            $reservations,
            $clock,
            $envelope,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * One call to whatever route answers first.
     *
     * @param array<string, mixed>|null $outputSchema the task's own declared output schema, unnarrowed
     */
    public function call(AiTaskPayload $payload, ?array $outputSchema = null): BridgeResult|BridgeRefusal
    {
        $redacted = $this->redact($payload);

        if ($redacted instanceof BridgeRefusal) {
            return $redacted;
        }

        if ($redacted->isEmpty()) {
            // A redactor that dropped everything produced a payload the
            // runner refuses rather than a call it pays for.
            return BridgeRefusal::of(
                RefusalKind::InputRejected,
                'ai.refusal.redacted_payload_empty',
                ['task' => $this->envelope->taskKey],
            );
        }

        if ($this->envelope->routes === []) {
            return BridgeRefusal::of(
                RefusalKind::NotConfigured,
                'ai.refusal.no_route',
                ['task' => $this->envelope->taskKey],
            );
        }

        return $this->iterate($redacted, $outputSchema);
    }

    /**
     * What actually left the site on the last call, which is what the run's
     * redaction manifest records and what the learner's right-to-know view
     * renders. It is the manifest of the payload rather than a second copy of
     * it, so the record and the wire cannot disagree.
     *
     * @return list<string>
     */
    public function lastManifest(): array
    {
        return $this->lastManifest;
    }

    /**
     * The provenance of the last successful call, or null when the last call
     * produced no artefact.
     *
     * Read by the run pipeline on the way out of the task, so the ledger row
     * carries the counterparty's own request id, fingerprint, prompt reference,
     * finish reason and token counts rather than five nulls beside an index
     * built for them.
     */
    public function lastResult(): ?BridgeResult
    {
        return $this->lastResult;
    }

    private function redact(AiTaskPayload $payload): RedactedPayload|BridgeRefusal
    {
        if (!$this->redactors->has($this->envelope->redactorKey)) {
            // The published miss direction for this registry, and one of the
            // two that diverge from the notification precedent on purpose: a
            // redaction level is a gate, and an unresolved gate fails closed.
            return BridgeRefusal::of(
                RefusalKind::NotConfigured,
                'ai.refusal.redactor_unresolved',
                ['redactor' => $this->envelope->redactorKey],
            );
        }

        $context = AiRedactionContext::create(
            $this->envelope->redactionLevel,
            $this->envelope->siteRef,
            $this->envelope->declaredFactKeys,
            $this->envelope->locale,
        );

        try {
            // The declared-fact filter is applied here rather than trusted to
            // the redactor, so every registered redactor is held to it.
            $redacted = $this->redactors->get($this->envelope->redactorKey)->redact(
                $payload->onlyFacts($this->envelope->declaredFactKeys),
                $context,
            );
        } catch (\Throwable $broken) {
            return BridgeRefusal::of(
                RefusalKind::NotConfigured,
                'ai.refusal.redactor_contract_broken',
                ['redactor' => $this->envelope->redactorKey, 'reason' => $broken->getMessage()],
            );
        }

        $this->lastManifest = $redacted->manifest;

        return $redacted;
    }

    /**
     * @param array<string, mixed>|null $outputSchema
     */
    private function iterate(RedactedPayload $payload, ?array $outputSchema): BridgeResult|BridgeRefusal
    {
        $last = BridgeRefusal::of(
            RefusalKind::NotConfigured,
            'ai.refusal.no_route',
            ['task' => $this->envelope->taskKey],
        );

        foreach ($this->envelope->routes as $candidate) {
            $outcome = $this->tryCandidate($candidate, $payload, $outputSchema);

            if ($outcome instanceof BridgeResult) {
                return $outcome;
            }

            $last = $outcome;

            // Two refusals end the chain rather than moving down it. A
            // cancelled run has been stopped by a person and a deadline has
            // elapsed for every candidate equally, so trying the next route
            // would spend against a decision already taken.
            if ($outcome->kind === RefusalKind::Cancelled || $outcome->kind === RefusalKind::DeadlineExceeded) {
                return $outcome;
            }
        }

        return $last;
    }

    /**
     * @param array<string, mixed>|null $outputSchema
     */
    private function tryCandidate(
        AiRouteCandidate $candidate,
        RedactedPayload $payload,
        ?array $outputSchema,
    ): BridgeResult|BridgeRefusal {
        ++$this->attempt;
        $now = $this->clock->now();

        if (!$this->bridges->has($candidate->bridgeKey)) {
            // The route is not selectable and the placement reports that no
            // model is configured, rather than failing at click time.
            $this->attempts->record($this->attemptRow(
                $candidate,
                AiAttemptRecord::OUTCOME_UNRESOLVED,
                $now,
                RefusalKind::NotConfigured->value,
            ));

            return BridgeRefusal::of(
                RefusalKind::NotConfigured,
                'ai.refusal.bridge_unresolved',
                ['bridge' => $candidate->bridgeKey],
            );
        }

        if (!$candidate->isSelectable()) {
            $this->attempts->record($this->attemptRow(
                $candidate,
                AiAttemptRecord::OUTCOME_REFUSED,
                $now,
                RefusalKind::CeilingExceeded->value,
            ));

            return BridgeRefusal::of(
                RefusalKind::CeilingExceeded,
                'ai.refusal.route_declares_no_ceiling',
                ['bridge' => $candidate->bridgeKey, 'model' => $candidate->model],
            );
        }

        $bridge = $this->bridges->get($candidate->bridgeKey);
        $context = BridgeContext::create(
            $now,
            $this->envelope->deadline,
            $this->idempotencyKey($candidate),
            $candidate->params + ['model' => $candidate->model, 'prompt_ref' => $candidate->promptRef],
            $this->logger,
        );

        $narrowed = $this->narrowFor($bridge, $context, $outputSchema);

        $request = BridgeRequest::fromRedactedPayload(
            $payload,
            $this->envelope->deadline,
            $context->idempotencyKey,
            $candidate->maxCostPerRun,
            $this->envelope->callerRef,
            $narrowed?->schema,
            $context->routeParams,
        );

        $estimate = $this->estimate($bridge, $context, $request, $candidate);
        $overCeiling = $this->exceedsCeiling($estimate, $candidate->maxCostPerRun);

        if ($overCeiling) {
            $this->attempts->record($this->attemptRow(
                $candidate,
                AiAttemptRecord::OUTCOME_REFUSED,
                $now,
                RefusalKind::CeilingExceeded->value,
                $narrowed,
            ));

            return BridgeRefusal::of(
                RefusalKind::CeilingExceeded,
                'ai.refusal.per_run_cost',
                ['bridge' => $candidate->bridgeKey, 'model' => $candidate->model],
            );
        }

        $callRef = $this->envelope->runKey . ':' . $this->attempt;
        $this->reservations->reserve($callRef, $estimate);

        try {
            $answer = $bridge->run($request, $context);
        } catch (\Throwable $error) {
            // An exception out of a provider is programmer error and is not
            // converted into a refusal, which would file a bug as an outage.
            // The reservation is released and the attempt recorded on the way
            // past, because a run that vanished without either is a run
            // holding headroom nobody can find.
            $this->reservations->release($callRef);
            $this->attempts->record($this->attemptRow(
                $candidate,
                AiAttemptRecord::OUTCOME_ERRORED,
                $now,
                null,
                $narrowed,
            ));

            throw $error;
        }

        if ($answer instanceof BridgeRefusal) {
            $this->reservations->release($callRef);
            $this->attempts->record($this->attemptRow(
                $candidate,
                AiAttemptRecord::OUTCOME_REFUSED,
                $now,
                $answer->kind->value,
                $narrowed,
                null,
                null,
                null,
                $answer->providerRequestId,
                $answer->isRetryable() && $bridge instanceof HonoursIdempotency,
            ));

            return $answer;
        }

        // The attempt row is written before the success test, which is what
        // makes "which route is failing this week" and "what did the failed
        // attempts cost this month" answerable from outside the run.
        $this->attempts->record($this->attemptRow(
            $candidate,
            AiAttemptRecord::OUTCOME_SUCCEEDED,
            $now,
            null,
            $narrowed,
            $answer->cost,
            $answer->tokensIn,
            $answer->tokensOut,
            $answer->providerRequestId,
            $bridge instanceof HonoursIdempotency,
        ));

        $this->reservations->settle($callRef, $answer->cost);
        // Kept before the post-hoc schema gate, because a payload the runner
        // then refuses is still a generation the counterparty billed and still
        // a request id the reconciliation sweep has to be able to find.
        $this->lastResult = $answer;

        return $this->validate($answer, $outputSchema, $candidate);
    }

    /**
     * Post-hoc validation, which is the gate the narrowed schema never was.
     *
     * @param array<string, mixed>|null $outputSchema
     */
    private function validate(
        BridgeResult $result,
        ?array $outputSchema,
        AiRouteCandidate $candidate,
    ): BridgeResult|BridgeRefusal {
        if ($outputSchema === null || $result->structure === null) {
            return $result;
        }

        $errors = DefinitionSchemaCheck::errors($outputSchema, $result->structure, '', true);

        if ($errors === []) {
            return $result;
        }

        return BridgeRefusal::of(
            RefusalKind::SchemaInvalid,
            'ai.refusal.schema_invalid',
            [
                'bridge' => $candidate->bridgeKey,
                'model' => $candidate->model,
                'first' => $errors[0],
            ],
            $result->providerRequestId,
        );
    }

    /**
     * @param array<string, mixed>|null $outputSchema
     */
    private function narrowFor(
        ModelBridgeInterface $bridge,
        BridgeContext $context,
        ?array $outputSchema,
    ): ?NarrowedSchema {
        if ($outputSchema === null) {
            return null;
        }

        $constrains = $bridge instanceof ProducesStructuredOutput
            && $this->supports($bridge, ProducesStructuredOutput::class, $context);

        if (!$constrains) {
            // The published absence: the runner validates after the fact and
            // the run is flagged schema_unenforced. The dropped list still
            // names every constraint, so the review screen can say which.
            return $this->narrower->unenforced($outputSchema);
        }

        return $this->narrower->narrow($outputSchema, $bridge->supportedSchemaKeywords());
    }

    private function estimate(
        ModelBridgeInterface $bridge,
        BridgeContext $context,
        BridgeRequest $request,
        AiRouteCandidate $candidate,
    ): CostEstimate {
        if (!$bridge instanceof EstimatesCost || !$this->supports($bridge, EstimatesCost::class, $context)) {
            // The absent answer is the route's declared per-run maximum,
            // never an optimistic guess: an optimistic guess against a
            // ceiling is a ceiling that passes everything.
            return $this->declaredMaximum($candidate);
        }

        $estimate = $bridge->estimate($request);

        // **An estimate a bridge could not obtain is the same absence.** It was
        // not, and that is how the per-run ceiling stopped binding on exactly
        // the calls it exists for: a bridge that implements the capability
        // answers unknown on a timeout, a rate limit, an unconfigured row or a
        // counterparty whose estimate entry point has not shipped, and an
        // unknown estimate made `exceedsCeiling` answer false. The documented
        // fallback was written once, for the absent capability, and never
        // reached from here. Substituting the declared maximum means the
        // ceiling can refuse or hold and can never disappear.
        return $estimate->isKnown() ? $estimate : $this->declaredMaximum($candidate);
    }

    /**
     * The route's own per-run maximum, as an upper bound.
     */
    private function declaredMaximum(AiRouteCandidate $candidate): CostEstimate
    {
        return CostEstimate::of(
            $candidate->maxCostPerRun->unit,
            $candidate->maxCostPerRun->amount ?? 0.0,
            true,
        );
    }

    private function exceedsCeiling(CostEstimate $estimate, CostRecord $ceiling): bool
    {
        if (!$estimate->isKnown() || !$ceiling->isKnown()) {
            return false;
        }

        if ($estimate->unit !== $ceiling->unit) {
            // A ceiling denominated in a unit the estimate does not use never
            // fires, and pretending otherwise would compare tokens to money.
            // The unit-free call ceilings are what bound this case, and they
            // are evaluated by a policy rather than here.
            return false;
        }

        return ($estimate->amount ?? 0.0) > ($ceiling->amount ?? 0.0);
    }

    /**
     * The instanceof probe, with the one documented downgrade.
     *
     * A capability is a claim about the deployed counterparty and not only
     * about the class, so a bridge pointed at a remote whose version does not
     * support something may answer false for a capability it implements. It
     * may never answer true for one it does not, which is why the probe runs
     * first and the negotiation only ever narrows the answer.
     *
     * @param class-string $capability
     */
    private function supports(ModelBridgeInterface $bridge, string $capability, BridgeContext $context): bool
    {
        if (!$bridge instanceof $capability) {
            return false;
        }

        if ($bridge instanceof NegotiatesCapabilities) {
            return $bridge->supports($capability, $context);
        }

        return true;
    }

    /**
     * One key per run and candidate, so a retry of the same call carries the
     * same key and a different route does not.
     */
    private function idempotencyKey(AiRouteCandidate $candidate): string
    {
        return hash('sha256', implode(':', [
            $this->envelope->runKey,
            $this->envelope->taskKey,
            $candidate->bridgeKey,
            $candidate->model,
            (string) $candidate->position,
            (string) $this->attempt,
        ]));
    }

    private function attemptRow(
        AiRouteCandidate $candidate,
        string $outcome,
        \DateTimeImmutable $at,
        ?string $refusalKind = null,
        ?NarrowedSchema $narrowed = null,
        ?CostRecord $cost = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?string $providerRequestId = null,
        bool $autoRetrySafe = false,
    ): AiAttemptRecord {
        return AiAttemptRecord::of(
            $this->envelope->runKey,
            $this->attempt,
            $candidate->position,
            $candidate->bridgeKey,
            $candidate->model,
            $outcome,
            $at,
            $refusalKind,
            $providerRequestId,
            $tokensIn,
            $tokensOut,
            $cost,
            $narrowed !== null && !$narrowed->isFullyEnforced(),
            $narrowed === null ? [] : $narrowed->droppedConstraints,
            $autoRetrySafe,
        );
    }
}
