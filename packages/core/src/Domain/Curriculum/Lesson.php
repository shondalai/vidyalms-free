<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;

/**
 * Lesson metadata only. The authored content payload lives in lesson_content
 * and crosses the application boundary as a separate array, so listing a
 * curriculum can never leak content to visitors without access.
 */
final class Lesson
{
    public const STATE_PUBLISHED = 'published';
    public const STATE_DRAFT = 'draft';

    /**
     * Removed from the course without being deleted. An archived lesson
     * leaves the learner curriculum, the lessons_total of every enrolment
     * and the completion rule; the progress rows, attempts, submissions and
     * grades that reference it stay exactly where they are, because those
     * are append-only records of what a person did and archiving is an
     * authoring decision, not a correction of history.
     *
     * The two states an author moves between are draft and published;
     * archived is entered by the archive route and left by setting the state
     * back, which is why the update use cases still accept only the first
     * two plus this one as a restore source.
     */
    public const STATE_ARCHIVED = 'archived';

    /** Every state a lesson row may carry. */
    public const STATES = [self::STATE_PUBLISHED, self::STATE_DRAFT, self::STATE_ARCHIVED];

    /**
     * The longest duration a lesson may carry: what the schema's "?uint"
     * duration column holds, an unsigned 32 bit integer. A longer one is not
     * a duration, and it is refused here rather than at the database, because
     * a host whose sql_mode lacks STRICT_TRANS_TABLES stores 4294967295 and
     * says nothing. Where the answer comes from is what decides whether the
     * same document imports the same way on every host.
     */
    public const MAX_DURATION_SECONDS = 4294967295;

    public function __construct(
        public readonly int $id,
        public readonly int $courseId,
        public readonly int $moduleId,
        public readonly string $title,
        public readonly Slug $slug,
        public readonly string $type,
        public readonly ?string $summary,
        public readonly ?int $durationSeconds,
        public readonly int $position,
        public readonly string $state,
        public readonly bool $freePreview,
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->courseId,
            $this->moduleId,
            $this->title,
            $this->slug,
            $this->type,
            $this->summary,
            $this->durationSeconds,
            $this->position,
            $this->state,
            $this->freePreview,
        );
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->state === self::STATE_ARCHIVED;
    }

    public function withState(string $state): self
    {
        return new self(
            $this->id,
            $this->courseId,
            $this->moduleId,
            $this->title,
            $this->slug,
            $this->type,
            $this->summary,
            $this->durationSeconds,
            $this->position,
            $state,
            $this->freePreview,
        );
    }
}
