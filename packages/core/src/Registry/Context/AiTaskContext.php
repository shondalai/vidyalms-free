<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Ai\AiBridgeCaller;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * What an AI task may read while it runs.
 *
 * It carries no DatabasePort, and that is an explicit divergence from
 * TriggerContext: a trigger reads in order to build its parameters, while a
 * task must be handed exactly the facts it declared and nothing more. A
 * database handle defeats minimisation in one line, and a task that needs a
 * fact it did not declare declares it.
 *
 * It carries no Principal either. The caller is here as a pseudonymous id,
 * because a task that holds the acting user holds a display name and an email
 * address, and neither has any business crossing a model boundary.
 *
 * What it does carry, from the wave that first fills the seams, is an
 * AiBridgeCaller, and the distinction between that and a bridge handle is the
 * whole minimisation argument. A task holding the bridge composes its own
 * PromptSegment list, and a segment's text is free, so it could put a
 * learner's display name or an unredacted submission on the wire without
 * touching the redactor. A task holding the caller can ask for one call and
 * cannot reach the transport: the redaction, the narrowing, the route chain,
 * the attempt row and the reservation all happen on the far side of one
 * method it does not implement.
 *
 * The caller is nullable because the eleven free tasks are deterministic and
 * make no call at all, and a run that never intends one should not have to
 * mint a route chain to construct a context. A task asking for a caller that
 * is not there is asking for a model on a build that has none, which is a
 * refusal rather than a null dereference.
 *
 * The cancellation signal is here now rather than later, because it is the
 * one member a task cannot be given retroactively: a long-running task's
 * inner loop has to have been written to ask, and a member added after tasks
 * exist is a member every one of them ignores. A deadline cannot stand in for
 * it, because a deadline cannot express an operator hitting a stop control.
 */
final class AiTaskContext
{
    /**
     * @param array<string, mixed> $facts the values of the facts the task declared, and no others
     */
    private function __construct(
        public readonly string $callerRef,
        public readonly ?string $placementKey,
        public readonly ?string $policyKey,
        public readonly int $policyVersion,
        public readonly string $locale,
        public readonly CostRecord $remainingCeiling,
        public readonly \DateTimeImmutable $deadline,
        public readonly string $runKey,
        public readonly bool $dryRun,
        public readonly int $attempt,
        public readonly array $facts,
        public readonly \DateTimeImmutable $now,
        public readonly CancellationSignal $cancellation,
        private readonly ?AiBridgeCaller $bridge,
        /**
         * The resolved payload the pipeline already redacted for the manifest.
         *
         * It is here because the grounding stage resolves chunk keys into
         * chunk text onto the run and nothing carried that text any further:
         * a task was handed the keys as authored options and the facts as
         * values, so a task declaring RequiresGrounding could compose a call
         * carrying every option and no chunk at all. The run's manifest,
         * rendered from this payload, would then over-report what actually
         * left the site, which is the one thing the manifest may never do.
         *
         * Handing over the payload rather than a bare chunk list is what
         * keeps the two in step: the caller redacts the same object the
         * manifest was rendered from, so the record and the wire agree by
         * construction rather than by two code paths agreeing.
         *
         * Nullable because a run assembled by hand, and every deterministic
         * task, has nothing resolved to hand over.
         */
        private readonly ?AiTaskPayload $payload = null,
    ) {
    }

    /**
     * @param array<string, mixed> $facts
     */
    public static function create(
        string $callerRef,
        string $runKey,
        \DateTimeImmutable $now,
        \DateTimeImmutable $deadline,
        ?string $placementKey = null,
        ?string $policyKey = null,
        int $policyVersion = 1,
        string $locale = 'en-GB',
        ?CostRecord $remainingCeiling = null,
        bool $dryRun = false,
        int $attempt = 1,
        array $facts = [],
        ?CancellationSignal $cancellation = null,
        ?AiBridgeCaller $bridge = null,
        ?AiTaskPayload $payload = null,
    ): self {
        return new self(
            $callerRef,
            $placementKey,
            $policyKey,
            $policyVersion,
            $locale,
            $remainingCeiling ?? CostRecord::unknown(CostRecord::UNIT_NONE),
            $deadline,
            $runKey,
            $dryRun,
            $attempt,
            $facts,
            $now,
            $cancellation ?? CancellationSignal::never(),
            $bridge,
            $payload,
        );
    }

    /**
     * The payload the run resolved, which is what a model-backed task hands
     * to the caller so that the wire carries the chunks the manifest names.
     *
     * A task that would rather send less may build its own; there is no way
     * to send more, because the caller redacts whatever it is given and the
     * declared-fact filter runs on the far side of a method the task does
     * not implement.
     */
    public function payload(): ?AiTaskPayload
    {
        return $this->payload;
    }

    /**
     * Whether this run can reach a model at all.
     *
     * A deterministic task never asks; a model-backed task asks first, and a
     * false answer is a build with no model access rather than an error.
     */
    public function hasBridge(): bool
    {
        return $this->bridge !== null;
    }

    /**
     * The one collaborator that can make a call, or null on a build that has
     * none. It is reached through a method rather than held as a public
     * member so a task cannot pass it on to something else by property
     * access and pretend the caller authorised it.
     */
    public function bridge(): ?AiBridgeCaller
    {
        return $this->bridge;
    }

    /**
     * One declared fact's value, or null when the task did not declare it.
     * There is deliberately no way to ask for a fact that is not in the bag.
     */
    public function fact(string $key): mixed
    {
        return $this->facts[$key] ?? null;
    }

    public function hasExpired(): bool
    {
        return $this->now >= $this->deadline;
    }

    /**
     * Whether this run has been told to stop, asked rather than read: a stop
     * control hit after the task started is exactly the case the signal
     * exists for, and a value captured at construction could not see it.
     */
    public function isCancelled(): bool
    {
        return $this->cancellation->isCancelled();
    }
}
