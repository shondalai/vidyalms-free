<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The one place lesson content is encoded and decoded, and therefore the one
 * place its stored version is written and its upgrade is run. The registry is
 * here for that alone: nothing above this class ever sees a payload in
 * anything but the current shape.
 */
final class DatabaseLessonRepository implements LessonRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly LessonTypeRegistry $lessonTypes,
    ) {
    }

    public function byId(int $id): ?Lesson
    {
        $row = $this->database->selectOne(Query::from('lessons')->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function byCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('lessons')
                ->where('course_id', '=', $courseId)
                ->orderBy('module_id')
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->map(...), $rows);
    }

    public function byModule(int $moduleId): array
    {
        $rows = $this->database->selectAll(
            Query::from('lessons')
                ->where('module_id', '=', $moduleId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->map(...), $rows);
    }

    public function idsByModule(int $moduleId): array
    {
        $rows = $this->database->selectAll(
            Query::from('lessons')
                ->select(['id'])
                ->where('module_id', '=', $moduleId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function nextPosition(int $moduleId): int
    {
        $row = $this->database->selectOne(
            Query::from('lessons')
                ->select(['position'])
                ->where('module_id', '=', $moduleId)
                ->orderBy('position', Query::SORT_DESC),
        );

        return $row === null ? 1 : ((int) $row['position']) + 1;
    }

    public function slugExists(int $courseId, string $slug, ?int $excludeLessonId = null): bool
    {
        $query = Query::from('lessons')
            ->where('course_id', '=', $courseId)
            ->where('slug', '=', $slug);

        if ($excludeLessonId !== null) {
            $query->where('id', '!=', $excludeLessonId);
        }

        return $this->database->count($query) > 0;
    }

    public function publishedCountByCourse(int $courseId): int
    {
        return $this->database->count(
            Query::from('lessons')
                ->where('course_id', '=', $courseId)
                ->where('state', '=', Lesson::STATE_PUBLISHED),
        );
    }

    public function add(Lesson $lesson, array $content): int
    {
        $now = $this->now();

        return $this->database->transactional(function () use ($lesson, $content, $now): int {
            $lessonId = $this->database->insert('lessons', $this->toRow($lesson) + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->database->insert('lesson_content', [
                'lesson_id' => $lessonId,
                'content' => $this->encode($lesson->type, $content),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $lessonId;
        });
    }

    public function update(Lesson $lesson, ?array $content): void
    {
        $now = $this->now();

        $this->database->transactional(function () use ($lesson, $content, $now): void {
            $this->database->update(
                'lessons',
                $this->toRow($lesson) + ['updated_at' => $now],
                ['id' => $lesson->id],
            );

            if ($content !== null) {
                $this->database->upsert(
                    'lesson_content',
                    [
                        'lesson_id' => $lesson->id,
                        'content' => $this->encode($lesson->type, $content),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['lesson_id'],
                    ['content', 'updated_at'],
                );
            }
        });
    }

    public function contentOf(int $lessonId): ?array
    {
        $row = $this->database->selectOne(
            Query::from('lesson_content')->where('lesson_id', '=', $lessonId),
        );

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $stored */
        $stored = $decoded;
        $body = StoredShape::bodyOf($stored);
        $version = StoredShape::versionOf($stored);

        $lesson = $this->byId($lessonId);

        if ($lesson === null || !$this->lessonTypes->has($lesson->type)) {
            return $body;
        }

        $provider = $this->lessonTypes->get($lesson->type);

        if ($version >= $provider->contentVersion()) {
            return $this->safeContent($lesson->type, $body);
        }

        return $this->safeContent($lesson->type, $provider->upgradeContent($body, $version));
    }

    public function updatePositions(array $positions): void
    {
        $now = $this->now();

        $this->database->transactional(function () use ($positions, $now): void {
            foreach ($positions as $lessonId => $position) {
                $this->database->update(
                    'lessons',
                    ['position' => $position, 'updated_at' => $now],
                    ['id' => $lessonId],
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Lesson
    {
        return new Lesson(
            id: (int) $row['id'],
            courseId: (int) $row['course_id'],
            moduleId: (int) $row['module_id'],
            title: (string) $row['title'],
            slug: Slug::fromString((string) $row['slug']),
            type: (string) $row['type'],
            summary: $row['summary'] === null ? null : (string) $row['summary'],
            durationSeconds: $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
            position: (int) $row['position'],
            state: (string) $row['state'],
            freePreview: (int) $row['free_preview'] === 1,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Lesson $lesson): array
    {
        return [
            'course_id' => $lesson->courseId,
            'module_id' => $lesson->moduleId,
            'title' => $lesson->title,
            'slug' => $lesson->slug->value,
            'type' => $lesson->type,
            'summary' => $lesson->summary,
            'duration_seconds' => $lesson->durationSeconds,
            'position' => $lesson->position,
            'state' => $lesson->state,
            'free_preview' => $lesson->freePreview ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $content
     */
    private function encode(string $type, array $content): string
    {
        return json_encode(
            StoredShape::wrap($this->safeContent($type, $content), $this->versionOf($type)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * An unregistered key keeps its rows readable rather than breaking a
     * course, which is the same promise the registry makes about removed
     * extensions: version 1 and no upgrade.
     */
    private function versionOf(string $type): int
    {
        return $this->lessonTypes->has($type) ? $this->lessonTypes->get($type)->contentVersion() : 1;
    }

    /** @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function safeContent(string $type, array $content): array
    {
        $field = match ($type) { 'reading' => 'body', 'assignment' => 'instructions', default => null };
        if ($field !== null && is_string($content[$field] ?? null)) {
            $content[$field] = \Shondalai\VidyaLMS\Core\Domain\Shared\HtmlPolicy::safe($content[$field]);
        }
        return $content;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
