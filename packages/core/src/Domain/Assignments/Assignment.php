<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

/**
 * The assignment configuration attached to one lesson of type "assignment".
 * There is at most one per lesson; ConfigureAssignment upserts it.
 */
final class Assignment
{
    /**
     * @param list<string> $allowedFileTypes lowercase extensions without dots; an empty list accepts any type
     * @param list<array{id: string, label: string, max_points: float}> $rubric
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $lessonId,
        public readonly ?string $instructions,
        public readonly float $maxPoints,
        public readonly array $allowedFileTypes,
        public readonly ?int $maxFileSizeKb,
        public readonly int $maxFiles,
        public readonly ?\DateTimeImmutable $dueAt,
        public readonly bool $allowResubmission,
        public readonly ?float $passPercentage = null,
        public readonly array $rubric = [],
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->lessonId,
            $this->instructions,
            $this->maxPoints,
            $this->allowedFileTypes,
            $this->maxFileSizeKb,
            $this->maxFiles,
            $this->dueAt,
            $this->allowResubmission,
            $this->passPercentage,
            $this->rubric,
        );
    }

    public function isOverdueAt(\DateTimeImmutable $moment): bool
    {
        return $this->dueAt !== null && $moment > $this->dueAt;
    }

    public function acceptsExtension(string $extension): bool
    {
        if ($this->allowedFileTypes === []) {
            return true;
        }

        return \in_array(strtolower($extension), $this->allowedFileTypes, true);
    }

    public function maxFileSizeBytes(): ?int
    {
        return $this->maxFileSizeKb === null ? null : $this->maxFileSizeKb * 1024;
    }
}
