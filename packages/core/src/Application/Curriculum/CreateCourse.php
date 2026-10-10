<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCreated;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class CreateCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly AccessGuard $guard,
        private readonly DatabasePort $database,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly CourseCategoryPort $categories,
    ) {
    }

    public function authorise(Principal $principal): void
    {
        $this->guard->require($principal, Capability::TEACH);
    }

    public function execute(
        Principal $principal,
        string $title,
        ?string $slug = null,
        ?string $summary = null,
        ?string $description = null,
        ?string $image = null,
        ?int $categoryId = null,
        ?string $level = null,
        ?string $language = null,
        CourseVisibility $visibility = CourseVisibility::Public,
        string $priceMode = 'free',
        CompletionRule $completionRule = CompletionRule::AllLessons,
        ?int $completionThreshold = null,
        bool $featured = false,
    ): Course {
        $this->authorise($principal);

        $title = trim($title);

        if ($title === '') {
            throw ValidationException::forField('title', 'The title may not be empty.');
        }

        if (\strlen($title) > 255) {
            throw ValidationException::forField('title', 'The title may not exceed 255 characters.');
        }

        $priceMode = trim($priceMode);

        if ($priceMode === '' || \strlen($priceMode) > 20) {
            throw ValidationException::forField('price_mode', 'The price mode must be 1 to 20 characters long.');
        }

        if ($completionThreshold !== null && ($completionThreshold < 1 || $completionThreshold > 100)) {
            throw ValidationException::forField('completion_threshold', 'The completion threshold must be between 1 and 100.');
        }

        $this->requireAssignableCategory($categoryId);

        $base = $slug !== null ? Slug::fromString($slug) : Slug::fromTitle($title);

        $course = new Course(
            id: 0,
            title: $title,
            slug: $this->uniqueSlug($base),
            summary: $summary,
            description: $description,
            image: $image,
            categoryId: $categoryId,
            level: $level,
            language: $language,
            visibility: $visibility,
            state: CourseState::Draft,
            priceMode: $priceMode,
            completionRule: $completionRule,
            completionThreshold: $completionThreshold,
            featured: $featured,
            createdBy: $principal->userId,
            publishedAt: null,
        );

        $id = $this->database->transactional(function () use ($course, $principal): int {
            $id = $this->courses->add($course);
            $this->courses->assignInstructor($id, $principal->userId, 'lead');

            return $id;
        });

        $created = $course->withId($id);

        // After the commit, never inside it: a listener that mirrors the
        // course into the host (a WordPress permalink shell) must not see a
        // course a rollback is about to take away.
        $this->events->dispatch(new CourseCreated(
            $this->clock->now(),
            $created->id,
            $created->title,
            $created->slug->value,
            $created->state->value,
            $principal->userId,
        ));

        return $created;
    }

    private function requireAssignableCategory(?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        if ($categoryId < 1) {
            throw ValidationException::forField('category_id', 'A positive integer or null is required.');
        }

        $category = $this->categories->findById($categoryId);

        if ($category === null) {
            throw ValidationException::forField('category_id', 'The course category was not found.');
        }

        if ($category->state === CourseCategoryState::Trashed) {
            throw ValidationException::forField('category_id', 'A trashed course category cannot be assigned.');
        }
    }

    private function uniqueSlug(Slug $base): Slug
    {
        if (!$this->courses->slugExists($base->value)) {
            return $base;
        }

        $suffix = 2;

        do {
            $stem = rtrim(substr($base->value, 0, 190 - \strlen('-' . $suffix)), '-');
            $candidate = Slug::fromString($stem . '-' . $suffix);
            $suffix++;
        } while ($this->courses->slugExists($candidate->value));

        return $candidate;
    }
}
