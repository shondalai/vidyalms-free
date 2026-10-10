<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One queued AI run or multi-step build.
 *
 * The job model is the import pipeline copied rather than reinvented, so the
 * heartbeat rule is the same: the progress write moves updatedAt, and a run
 * whose process was killed leaves a row that says "running" for ever unless
 * something reads the silence. hasStalled() reports it as a presumption and
 * says so; the status stays running, because flipping it to failed would claim
 * an error nothing reported, and a slow build that writes again stops being
 * stale on its own.
 *
 * Exactly one of taskKey and recipeKey is set. A single-task job names the task;
 * a multi-step build names a recipe, because one job row holding one task key
 * cannot express three task keys, and hardcoding the sequence in a runner would
 * make a fourth step a core edit.
 */
final class AiJob
{
    /**
     * How long a running job may go without a heartbeat before it is reported
     * as stopped responding.
     *
     * The same quarter of an hour import jobs use, and for the same reason: the
     * runner writes progress on every note and a second callback moves the
     * heartbeat alone, so a silent chunking phase is not a dead process but
     * fifteen minutes of nothing is.
     */
    public const STALE_AFTER_SECONDS = 900;

    /**
     * @param array<string, mixed>      $options
     * @param array<string, mixed>|null $totals
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $taskKey,
        public readonly ?string $recipeKey,
        public readonly int $stepIndex,
        public readonly ?string $placementKey,
        public readonly AiJobStatus $status,
        public readonly string $scopeType,
        public readonly int $scopeId,
        public readonly ?int $organisationId,
        public readonly array $options,
        public readonly int $optionsVersion,
        public readonly bool $dryRun,
        public readonly string $costUnit,
        public readonly ?float $costReserved,
        public readonly ?float $costCharged,
        public readonly ?string $log,
        public readonly ?array $totals,
        public readonly ?\DateTimeImmutable $resumeAt,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
        public readonly int $createdBy,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * The work this job names, whichever of the two columns holds it.
     *
     * A job with neither is unrunnable and is refused at the write path rather
     * than discovered at drain time.
     */
    public function work(): string
    {
        return $this->recipeKey ?? $this->taskKey ?? '';
    }

    public function isRecipe(): bool
    {
        return $this->recipeKey !== null;
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * The last time this run wrote anything: its own progress while it runs,
     * and the write that finished it once it has.
     */
    public function heartbeatAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt ?? $this->startedAt ?? $this->createdAt;
    }

    public function hasStalled(\DateTimeImmutable $now, int $afterSeconds = self::STALE_AFTER_SECONDS): bool
    {
        if ($this->status !== AiJobStatus::Running) {
            return false;
        }

        $heartbeat = $this->heartbeatAt();

        return $heartbeat !== null && $now->getTimestamp() - $heartbeat->getTimestamp() > $afterSeconds;
    }

    /** Whether the scheduler should arm another attempt, and from when. */
    public function resumesAt(): ?\DateTimeImmutable
    {
        return $this->status->resumes() ? $this->resumeAt : null;
    }
}
