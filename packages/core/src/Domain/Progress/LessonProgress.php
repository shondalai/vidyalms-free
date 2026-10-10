<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

/**
 * One learner's state on one lesson of one enrolment. Transitions are
 * monotonic: progress never decreases and a completed lesson never leaves
 * completed, so replayed heartbeats and re-fired listeners cannot regress
 * or inflate anything except time spent.
 */
final class LessonProgress
{
    public const STATUS_NOT_STARTED = 'not_started';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    private function __construct(
        public readonly int $enrolmentId,
        public readonly int $lessonId,
        public readonly int $userId,
        private string $status,
        private float $progressPercent,
        private int $secondsSpent,
        private ?int $positionSeconds,
        private ?\DateTimeImmutable $firstAccessedAt,
        private ?\DateTimeImmutable $completedAt,
        private ?\DateTimeImmutable $lastHeartbeatAt,
    ) {
    }

    public static function start(int $enrolmentId, int $lessonId, int $userId, \DateTimeImmutable $now): self
    {
        return new self(
            $enrolmentId,
            $lessonId,
            $userId,
            self::STATUS_IN_PROGRESS,
            0.0,
            0,
            null,
            $now,
            null,
            null,
        );
    }

    public static function restore(
        int $enrolmentId,
        int $lessonId,
        int $userId,
        string $status,
        float $progressPercent,
        int $secondsSpent,
        ?int $positionSeconds,
        ?\DateTimeImmutable $firstAccessedAt,
        ?\DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $lastHeartbeatAt,
    ): self {
        return new self(
            $enrolmentId,
            $lessonId,
            $userId,
            $status,
            $progressPercent,
            $secondsSpent,
            $positionSeconds,
            $firstAccessedAt,
            $completedAt,
            $lastHeartbeatAt,
        );
    }

    public function recordHeartbeat(\DateTimeImmutable $now): void
    {
        $this->lastHeartbeatAt = $now;

        if ($this->firstAccessedAt === null) {
            $this->firstAccessedAt = $now;
        }

        if ($this->status === self::STATUS_NOT_STARTED) {
            $this->status = self::STATUS_IN_PROGRESS;
        }
    }

    public function addSecondsSpent(int $seconds): void
    {
        if ($seconds > 0) {
            $this->secondsSpent += $seconds;
        }
    }

    public function raiseProgressPercent(float $percent): void
    {
        $percent = max(0.0, min(100.0, $percent));

        if ($percent > $this->progressPercent) {
            $this->progressPercent = $percent;
        }
    }

    public function moveTo(int $positionSeconds): void
    {
        $this->positionSeconds = max(0, $positionSeconds);
    }

    /**
     * Returns true only on the transition into completed; a lesson that is
     * already completed stays untouched so the caller can emit
     * LessonCompleted exactly once.
     */
    public function complete(\DateTimeImmutable $now): bool
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = $now;
        $this->progressPercent = 100.0;

        if ($this->firstAccessedAt === null) {
            $this->firstAccessedAt = $now;
        }

        return true;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function progressPercent(): float
    {
        return $this->progressPercent;
    }

    public function secondsSpent(): int
    {
        return $this->secondsSpent;
    }

    public function positionSeconds(): ?int
    {
        return $this->positionSeconds;
    }

    public function firstAccessedAt(): ?\DateTimeImmutable
    {
        return $this->firstAccessedAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function lastHeartbeatAt(): ?\DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }
}
