<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;

final class UpdateLesson
{
    private const ALLOWED_KEYS = ['title', 'slug', 'summary', 'duration_seconds', 'state', 'free_preview', 'type', 'content'];

    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly CourseAccess $access,
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly LessonAvailability $availability,
    ) {
    }

    /**
     * @param array<string, mixed> $changes position and module moves go through
     *                                      ReorderLessons
     */
    public function execute(Principal $principal, int $lessonId, array $changes): Lesson
    {
        $this->access->requireSignedIn($principal);

        $lesson = $this->lessons->byId($lessonId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $lessonId);

        $this->access->require($principal, $lesson->courseId, Capability::TEACH);

        foreach (array_keys($changes) as $key) {
            if (!\in_array($key, self::ALLOWED_KEYS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $key, 'This field cannot be updated.');
            }
        }

        $type = $lesson->type;

        if (\array_key_exists('type', $changes)) {
            $value = $changes['type'];

            if (!\is_string($value) || !$this->lessonTypes->has($value)) {
                throw ValidationException::forField('type', 'Unknown lesson type.');
            }

            $type = $value;
        }

        $content = null;

        if (\array_key_exists('content', $changes)) {
            $value = $changes['content'];

            if (!\is_array($value)) {
                throw ValidationException::forField('content', 'The content must be an object.');
            }

            /** @var array<string, mixed> $value */
            $content = $value;
        }

        // A type change must leave stored content valid for the new type, so
        // the current payload is revalidated when no replacement was sent.
        if ($content === null && $type !== $lesson->type) {
            $content = $this->lessons->contentOf($lesson->id) ?? [];
        }

        if ($content !== null) {
            $this->lessonTypes->get($type)->validateContent($content);
        }

        $state = $this->stateChange($changes, $lesson->state);

        // A published lesson inside a module that is not published is
        // unreachable and still countable, which is a course that says one
        // thing to an author and another to a learner. Restoring a lesson
        // therefore means restoring the module first, and the refusal names
        // it.
        if (
            $state === Lesson::STATE_PUBLISHED
            && $lesson->state !== Lesson::STATE_PUBLISHED
            && !$this->availability->moduleAcceptsPublishedLessons($lesson->moduleId)
        ) {
            throw ValidationException::forField(
                'state',
                sprintf(
                    'Module %d is not published, so a lesson inside it cannot be. Publish the module first.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $lesson->moduleId,
                ),
            );
        }

        $updated = new Lesson(
            id: $lesson->id,
            courseId: $lesson->courseId,
            moduleId: $lesson->moduleId,
            title: $this->titleChange($changes, $lesson->title),
            slug: $this->slugChange($changes, $lesson),
            type: $type,
            summary: $this->summaryChange($changes, $lesson->summary),
            durationSeconds: $this->durationChange($changes, $lesson->durationSeconds),
            position: $lesson->position,
            state: $state,
            freePreview: $this->freePreviewChange($changes, $lesson->freePreview),
        );

        if ($updated->type === 'video' && $updated->state === Lesson::STATE_PUBLISHED && ($updated->durationSeconds ?? 0) < 1) {
            throw ValidationException::forField('duration_seconds', 'Set the video duration before publishing this lesson.');
        }

        $this->lessons->update($updated, $content);

        return $updated;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function titleChange(array $changes, string $current): string
    {
        if (!\array_key_exists('title', $changes)) {
            return $current;
        }

        $value = $changes['title'];

        if (!\is_string($value) || trim($value) === '' || \strlen(trim($value)) > 255) {
            throw ValidationException::forField('title', 'A non-empty title of at most 255 characters is required.');
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function slugChange(array $changes, Lesson $lesson): Slug
    {
        if (!\array_key_exists('slug', $changes)) {
            return $lesson->slug;
        }

        $value = $changes['slug'];

        if (!\is_string($value)) {
            throw ValidationException::forField('slug', 'A string is required.');
        }

        $candidate = Slug::fromString($value);

        if (
            !$candidate->equals($lesson->slug)
            && $this->lessons->slugExists($lesson->courseId, $candidate->value, $lesson->id)
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('The slug "%s" is already used by another lesson of this course.', $candidate->value));
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function summaryChange(array $changes, ?string $current): ?string
    {
        if (!\array_key_exists('summary', $changes)) {
            return $current;
        }

        $value = $changes['summary'];

        if ($value !== null && !\is_string($value)) {
            throw ValidationException::forField('summary', 'A string or null is required.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function durationChange(array $changes, ?int $current): ?int
    {
        if (!\array_key_exists('duration_seconds', $changes)) {
            return $current;
        }

        $value = $changes['duration_seconds'];

        if ($value !== null && (!\is_int($value) || $value < 0 || $value > Lesson::MAX_DURATION_SECONDS)) {
            throw ValidationException::forField('duration_seconds', sprintf(
                'An integer between 0 and %d, or null, is required.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                Lesson::MAX_DURATION_SECONDS,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function stateChange(array $changes, string $current): string
    {
        if (!\array_key_exists('state', $changes)) {
            return $current;
        }

        $value = $changes['state'];

        // Published and draft only: this is also how an archived lesson is
        // restored. Archiving goes through ArchiveLesson, which is the only
        // path that recomputes the progress the removal changed.
        if (!\is_string($value) || !\in_array($value, [Lesson::STATE_PUBLISHED, Lesson::STATE_DRAFT], true)) {
            throw ValidationException::forField(
                'state',
                'The state must be published or draft; archive a lesson with DELETE and restore it by setting one of these.',
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function freePreviewChange(array $changes, bool $current): bool
    {
        if (!\array_key_exists('free_preview', $changes)) {
            return $current;
        }

        $value = $changes['free_preview'];

        if (\is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw ValidationException::forField('free_preview', 'A boolean is required.');
    }
}
