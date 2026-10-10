<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

final class Module
{
    public const STATE_PUBLISHED = 'published';
    public const STATE_DRAFT = 'draft';

    /**
     * Removed from the course without being deleted, exactly as a lesson is.
     * Archiving a module archives its lessons, because a module nobody can
     * reach whose lessons still count towards completion is a course that
     * says one thing to an author and another to a learner.
     */
    public const STATE_ARCHIVED = 'archived';

    /** Every state a module row may carry. */
    public const STATES = [self::STATE_PUBLISHED, self::STATE_DRAFT, self::STATE_ARCHIVED];

    public function __construct(
        public readonly int $id,
        public readonly int $courseId,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly int $position,
        public readonly string $state,
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->courseId, $this->title, $this->summary, $this->position, $this->state);
    }

    public function isArchived(): bool
    {
        return $this->state === self::STATE_ARCHIVED;
    }

    public function withState(string $state): self
    {
        return new self($this->id, $this->courseId, $this->title, $this->summary, $this->position, $state);
    }
}
