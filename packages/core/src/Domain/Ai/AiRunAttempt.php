<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One candidate route tried, written BEFORE the success test.
 *
 * A child table rather than a JSON array on the run, and rather than one run row
 * per attempt. A run has one to n attempts, and "which route is failing this
 * week" and "what did the failed attempts cost this month" are aggregations
 * asked from outside the run: a JSON array answers them with a full scan, and
 * one row per attempt makes winners and losers indistinguishable without a self
 * join.
 *
 * Written before the success test, because an attempt that crashed the process
 * is exactly the attempt an operator needs to see.
 */
final class AiRunAttempt
{
    /** @var list<string> */
    public readonly array $droppedConstraints;

    /**
     * @param list<string> $droppedConstraints
     */
    private function __construct(
        public readonly int $runId,
        public readonly int $attemptNo,
        public readonly ?int $routeId,
        public readonly int $position,
        public readonly ?string $providerRequestId,
        public readonly bool $autoRetrySafe,
        array $droppedConstraints,
        public readonly string $bridgeKey,
        public readonly ?string $model,
        public readonly \DateTimeImmutable $startedAt,
        public readonly bool $success,
        public readonly ?string $refusalKind,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly string $costUnit,
        public readonly ?float $costCharged,
        public readonly ?int $latencyMs,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
        $this->droppedConstraints = $droppedConstraints;
    }

    /** The row as it is written before the call is made. */
    public static function opened(
        int $runId,
        int $attemptNo,
        string $bridgeKey,
        \DateTimeImmutable $startedAt,
        ?int $routeId = null,
        ?string $model = null,
        int $position = 0,
    ): self {
        return new self(
            $runId,
            $attemptNo,
            $routeId,
            $position,
            null,
            false,
            [],
            $bridgeKey,
            $model,
            $startedAt,
            false,
            null,
            null,
            null,
            null,
            null,
            'none',
            null,
            null,
            null,
        );
    }

    /**
     * The same attempt, closed by whatever the bridge answered.
     *
     * The dropped constraints and the upstream reference are parameters here
     * rather than fields the caller crammed into `$errorMessage`. A SUCCEEDED
     * attempt used to carry a non-null error message reading
     * "schema_unenforced: ...", which put a note about the route's
     * capabilities into the column an outage post-mortem filters on.
     *
     * @param list<string> $droppedConstraints
     */
    public function closed(
        bool $success,
        \DateTimeImmutable $completedAt,
        ?string $refusalKind = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        string $costUnit = 'none',
        ?float $costCharged = null,
        ?string $providerRequestId = null,
        bool $autoRetrySafe = false,
        array $droppedConstraints = [],
    ): self {
        return new self(
            $this->runId,
            $this->attemptNo,
            $this->routeId,
            $this->position,
            $providerRequestId ?? $this->providerRequestId,
            $autoRetrySafe,
            $droppedConstraints === [] ? $this->droppedConstraints : $droppedConstraints,
            $this->bridgeKey,
            $this->model,
            $this->startedAt,
            $success,
            $refusalKind,
            $errorCode,
            $errorMessage,
            $tokensIn,
            $tokensOut,
            $costUnit,
            $costCharged,
            max(0, $completedAt->getTimestamp() - $this->startedAt->getTimestamp()) * 1000,
            $completedAt,
        );
    }
}
