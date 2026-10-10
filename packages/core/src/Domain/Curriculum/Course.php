<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;

final class Course
{
    /**
     * @param array<string, mixed>|null $latePolicyParams
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly Slug $slug,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?string $image,
        public readonly ?int $categoryId,
        public readonly ?string $level,
        public readonly ?string $language,
        public readonly CourseVisibility $visibility,
        public readonly CourseState $state,
        public readonly string $priceMode,
        public readonly CompletionRule $completionRule,
        public readonly ?int $completionThreshold,
        public readonly bool $featured,
        public readonly int $createdBy,
        public readonly ?\DateTimeImmutable $publishedAt,
        public readonly string $enrolmentRule = 'open',
        public readonly string $progression = 'free',
        public readonly ?string $timezone = null,
        public readonly ?\DateTimeImmutable $startsAt = null,
        public readonly string $latePolicyKey = 'none',
        public readonly ?array $latePolicyParams = null,
        public readonly int $latePolicyParamsVersion = 1,
        /** External shop source used for catalogue prices and purchase links. */
        public readonly string $commerceSource = 'cms-shop',
    ) {
    }

    public function withId(int $id): self
    {
        return $this->copy($id, $this->state, $this->publishedAt);
    }

    public function publish(\DateTimeImmutable $at): self
    {
        return $this->copy($this->id, CourseState::Published, $at);
    }

    public function archive(): self
    {
        return $this->copy($this->id, CourseState::Archived, $this->publishedAt);
    }

    public function unpublish(): self
    {
        return $this->copy($this->id, CourseState::Draft, $this->publishedAt);
    }

    public function trash(): self
    {
        return $this->copy($this->id, CourseState::Trashed, $this->publishedAt);
    }

    public function isPublished(): bool
    {
        return $this->state === CourseState::Published;
    }

    public function isPubliclyListed(): bool
    {
        return $this->isPublished() && $this->visibility === CourseVisibility::Public;
    }

    private function copy(int $id, CourseState $state, ?\DateTimeImmutable $publishedAt): self
    {
        return new self(
            $id,
            $this->title,
            $this->slug,
            $this->summary,
            $this->description,
            $this->image,
            $this->categoryId,
            $this->level,
            $this->language,
            $this->visibility,
            $state,
            $this->priceMode,
            $this->completionRule,
            $this->completionThreshold,
            $this->featured,
            $this->createdBy,
            $publishedAt,
            $this->enrolmentRule,
            $this->progression,
            $this->timezone,
            $this->startsAt,
            $this->latePolicyKey,
            $this->latePolicyParams,
            $this->latePolicyParamsVersion,
            $this->commerceSource,
        );
    }
}
