<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

/**
 * One attempt at an assignment. Attempts are append-only: a resubmission is a
 * new row with the next attempt number, never an overwrite of a graded one.
 */
final class Submission
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    /**
     * @param list<SubmissionFile> $files
     * @param array<string, mixed> $assessment
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $assignmentId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $attemptNumber,
        private string $status,
        public readonly ?string $textResponse,
        public readonly ?\DateTimeImmutable $submittedAt,
        private ?\DateTimeImmutable $gradedAt,
        private ?int $graderId,
        private ?float $pointsAwarded,
        private ?string $feedback,
        public readonly array $files,
        private array $assessment = [],
    ) {
    }

    /**
     * @param list<SubmissionFile> $files
     * @param array<string, mixed> $assessment
     */
    public static function submitted(
        int $assignmentId,
        int $enrolmentId,
        int $userId,
        int $attemptNumber,
        ?string $textResponse,
        \DateTimeImmutable $submittedAt,
        array $files,
        array $assessment = [],
    ): self {
        return new self(
            null,
            $assignmentId,
            $enrolmentId,
            $userId,
            $attemptNumber,
            self::STATUS_SUBMITTED,
            $textResponse,
            $submittedAt,
            null,
            null,
            null,
            null,
            $files,
            $assessment,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->assignmentId,
            $this->enrolmentId,
            $this->userId,
            $this->attemptNumber,
            $this->status,
            $this->textResponse,
            $this->submittedAt,
            $this->gradedAt,
            $this->graderId,
            $this->pointsAwarded,
            $this->feedback,
            $this->files,
            $this->assessment,
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isFinalized(): bool
    {
        return \in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_GRADED], true);
    }

    public function gradedAt(): ?\DateTimeImmutable
    {
        return $this->gradedAt;
    }

    public function graderId(): ?int
    {
        return $this->graderId;
    }

    public function pointsAwarded(): ?float
    {
        return $this->pointsAwarded;
    }

    public function feedback(): ?string
    {
        return $this->feedback;
    }

    /** @return array<string, mixed> */
    public function assessment(): array
    {
        return $this->assessment;
    }

    /** @param array<string, mixed> $assessment */
    public function grade(int $graderId, float $pointsAwarded, ?string $feedback, \DateTimeImmutable $gradedAt, array $assessment = []): void
    {
        $this->status = self::STATUS_GRADED;
        $this->graderId = $graderId;
        $this->pointsAwarded = $pointsAwarded;
        $this->feedback = $feedback;
        $this->gradedAt = $gradedAt;
        $this->assessment = $assessment;
    }
}
