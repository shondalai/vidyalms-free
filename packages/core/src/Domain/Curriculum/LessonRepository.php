<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

interface LessonRepository
{
    public function byId(int $id): ?Lesson;

    /**
     * @return list<Lesson> ordered by module, then position
     */
    public function byCourse(int $courseId): array;

    /**
     * @return list<Lesson> ordered by position
     */
    public function byModule(int $moduleId): array;

    /**
     * @return list<int>
     */
    public function idsByModule(int $moduleId): array;

    public function nextPosition(int $moduleId): int;

    public function slugExists(int $courseId, string $slug, ?int $excludeLessonId = null): bool;

    public function publishedCountByCourse(int $courseId): int;

    /**
     * Persists the lesson row and its lesson_content row atomically and
     * returns the new lesson id; the entity's own id is ignored.
     *
     * @param array<string, mixed> $content
     */
    public function add(Lesson $lesson, array $content): int;

    /**
     * @param array<string, mixed>|null $content null leaves the stored content untouched
     */
    public function update(Lesson $lesson, ?array $content): void;

    /**
     * @return array<string, mixed>|null
     */
    public function contentOf(int $lessonId): ?array;

    /**
     * @param array<int, int> $positions lesson id to new position
     */
    public function updatePositions(array $positions): void;
}
