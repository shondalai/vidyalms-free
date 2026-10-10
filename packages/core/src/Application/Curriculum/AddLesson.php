<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;

final class AddLesson
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
        private readonly LessonTypeRegistry $lessonTypes,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     */
    public function execute(
        Principal $principal,
        int $moduleId,
        string $title,
        string $type,
        array $content,
        ?string $slug = null,
        ?string $summary = null,
        ?int $durationSeconds = null,
        bool $freePreview = false,
        string $state = Lesson::STATE_PUBLISHED,
    ): Lesson {
        $this->access->requireSignedIn($principal);

        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        $this->access->require($principal, $module->courseId, Capability::TEACH);

        $title = trim($title);

        if ($title === '' || \strlen($title) > 255) {
            throw ValidationException::forField('title', 'A non-empty title of at most 255 characters is required.');
        }

        if (!$this->lessonTypes->has($type)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('type', sprintf('Unknown lesson type "%s".', $type));
        }

        if ($durationSeconds !== null && ($durationSeconds < 0 || $durationSeconds > Lesson::MAX_DURATION_SECONDS)) {
            throw ValidationException::forField('duration_seconds', sprintf(
                'The duration must be between 0 and %d seconds.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                Lesson::MAX_DURATION_SECONDS,
            ));
        }

        if (!\in_array($state, [Lesson::STATE_PUBLISHED, Lesson::STATE_DRAFT], true)) {
            throw ValidationException::forField('state', 'The state must be published or draft.');
        }

        $this->lessonTypes->get($type)->validateContent($content);
        if ($type === 'video' && $state === Lesson::STATE_PUBLISHED && ($durationSeconds ?? 0) < 1) {
            throw ValidationException::forField('duration_seconds', 'Set the video duration before publishing this lesson.');
        }

        $base = $slug !== null ? Slug::fromString($slug) : Slug::fromTitle($title);

        $lesson = new Lesson(
            id: 0,
            courseId: $module->courseId,
            moduleId: $moduleId,
            title: $title,
            slug: $this->uniqueSlug($module->courseId, $base),
            type: $type,
            summary: $summary,
            durationSeconds: $durationSeconds,
            position: $this->lessons->nextPosition($moduleId),
            state: $state,
            freePreview: $freePreview,
        );

        return $lesson->withId($this->lessons->add($lesson, $content));
    }

    private function uniqueSlug(int $courseId, Slug $base): Slug
    {
        if (!$this->lessons->slugExists($courseId, $base->value)) {
            return $base;
        }

        $suffix = 2;

        do {
            $stem = rtrim(substr($base->value, 0, 190 - \strlen('-' . $suffix)), '-');
            $candidate = Slug::fromString($stem . '-' . $suffix);
            $suffix++;
        } while ($this->lessons->slugExists($courseId, $candidate->value));

        return $candidate;
    }
}
