<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * One `ai_run_attempts` row, written **before** the success test.
 *
 * That placement is the whole point. An outage post-mortem asks which route
 * was failing this week and a billing dispute asks what the failed attempts
 * cost this month, and neither question is answerable from a run row carrying
 * an attempt counter: both are aggregations asked from outside the run, over
 * the attempts that did not work.
 *
 * `autoRetrySafe` is carried rather than recomputed, because the decision
 * depends on a capability of the bridge that answered and the row outlives
 * the object that knew it. A retryable refusal from a bridge that does not
 * honour idempotency is not safe to send again: the outbox parks and the run
 * is recorded at-most-once.
 */
final class AiAttemptRecord
{
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_REFUSED = 'refused';
    public const OUTCOME_UNRESOLVED = 'unresolved';
    public const OUTCOME_ERRORED = 'errored';

    /** @var list<string> */
    public readonly array $droppedConstraints;

    /**
     * @param list<string> $droppedConstraints schema keywords the route did not enforce
     */
    private function __construct(
        public readonly string $runKey,
        public readonly int $attempt,
        public readonly int $position,
        public readonly string $bridgeKey,
        public readonly string $model,
        public readonly string $outcome,
        public readonly ?string $refusalKind,
        public readonly ?string $providerRequestId,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly CostRecord $cost,
        public readonly bool $schemaUnenforced,
        array $droppedConstraints,
        public readonly bool $autoRetrySafe,
        public readonly \DateTimeImmutable $at,
    ) {
        $this->droppedConstraints = $droppedConstraints;
    }

    /**
     * @param list<string> $droppedConstraints
     */
    public static function of(
        string $runKey,
        int $attempt,
        int $position,
        string $bridgeKey,
        string $model,
        string $outcome,
        \DateTimeImmutable $at,
        ?string $refusalKind = null,
        ?string $providerRequestId = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?CostRecord $cost = null,
        bool $schemaUnenforced = false,
        array $droppedConstraints = [],
        bool $autoRetrySafe = false,
    ): self {
        return new self(
            $runKey,
            $attempt,
            $position,
            $bridgeKey,
            $model,
            $outcome,
            $refusalKind,
            $providerRequestId,
            $tokensIn,
            $tokensOut,
            $cost ?? CostRecord::unknown(CostRecord::UNIT_NONE),
            $schemaUnenforced,
            $droppedConstraints,
            $autoRetrySafe,
            $at,
        );
    }
}
