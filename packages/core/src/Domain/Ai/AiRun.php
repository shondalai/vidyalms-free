<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One row of the ledger, as a reader gets it back.
 *
 * A run row is written inside a transaction BEFORE the bridge is called, in
 * state reserved, carrying the run key, the task, the placement, the route, the
 * principal, the policy version in force, the inputs digest and the redaction
 * manifest. The outcome updates it. That ordering is the whole reason the
 * learner's right-to-know view is buildable at all: a ledger written after the
 * call cannot record a call that never came back.
 *
 * principalUserId and onBehalfOfUserId are two different people on purpose. The
 * learner whose data was sent is not the person who clicked, the audit filters
 * on one and the right-to-know view on the other, and onBehalfOfUserId is a
 * denormalisation of the single-subject case rather than the record. The record
 * is ai_run_subjects, because one nullable integer cannot answer for a run over
 * a cohort dataset or a hundred submissions.
 */
final class AiRun
{
    /**
     * @param array<string, mixed>|null $redactionManifest
     */
    public function __construct(
        public readonly int $id,
        public readonly string $runKey,
        public readonly ?int $jobId,
        public readonly string $taskKey,
        public readonly int $taskVersion,
        public readonly ?string $placementKey,
        public readonly ?int $routeId,
        public readonly string $bridgeKey,
        public readonly ?string $model,
        public readonly ?string $promptRef,
        public readonly int $policyVersion,
        public readonly ?int $principalUserId,
        public readonly string $actorKind,
        public readonly ?int $ruleId,
        public readonly ?int $onBehalfOfUserId,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $courseId,
        public readonly ?int $organisationId,
        public readonly string $locale,
        public readonly string $inputsDigest,
        public readonly ?array $redactionManifest,
        public readonly int $redactionManifestVersion,
        public readonly bool $dryRun,
        public readonly AiRunState $state,
        public readonly ?string $refusalKind,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly string $costUnit,
        public readonly ?float $costReserved,
        public readonly ?float $costCharged,
        public readonly bool $unbilled,
        public readonly ?bool $schemaValid,
        public readonly ?string $providerRequestId,
        public readonly ?string $providerFingerprint,
        public readonly ?string $finishReason,
        public readonly ?int $durationMs,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly ?\DateTimeImmutable $settledAt,
        public readonly \DateTimeImmutable $retainUntil,
        public readonly ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /** Who to bill this run's reservation against, settled or not. */
    public function spend(): float
    {
        return max($this->costCharged ?? 0.0, $this->costReserved ?? 0.0);
    }

    /**
     * Whether the model ran and the ledger did not settle, which is a different
     * fact from a free run and is why it is a flag rather than a zero.
     */
    public function isUnbilled(): bool
    {
        return $this->unbilled;
    }

    public function isTerminal(): bool
    {
        return $this->state->isTerminal();
    }

    /**
     * Whether an automation started this run.
     *
     * Real rather than derived from ruleId being non-null, because a person
     * running a rule by hand is exactly the case an implicit encoding breaks on.
     */
    public function startedByAutomation(): bool
    {
        return $this->actorKind === AiRunReservation::ACTOR_AUTOMATION;
    }
}
