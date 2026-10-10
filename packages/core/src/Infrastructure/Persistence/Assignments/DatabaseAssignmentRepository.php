<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments;

use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseAssignmentRepository implements AssignmentRepository
{
    private const TABLE = 'assignments';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function ofId(int $id): ?Assignment
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function ofLesson(int $lessonId): ?Assignment
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('lesson_id', '=', $lessonId));

        return $row === null ? null : $this->map($row);
    }

    public function save(Assignment $assignment): Assignment
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $values = [
            'instructions' => $assignment->instructions,
            'max_points' => $assignment->maxPoints,
            'allowed_file_types' => $assignment->allowedFileTypes === []
                ? null
                : implode(',', $assignment->allowedFileTypes),
            'max_file_size_kb' => $assignment->maxFileSizeKb,
            'max_files' => $assignment->maxFiles,
            'due_at' => $assignment->dueAt?->format(self::DATETIME),
            'allow_resubmission' => $assignment->allowResubmission ? 1 : 0,
            'pass_percentage' => $assignment->passPercentage,
            'rubric_json' => json_encode($assignment->rubric, JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];

        $existing = $this->database->selectOne(
            Query::from(self::TABLE)->select(['id'])->where('lesson_id', '=', $assignment->lessonId),
        );

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->database->update(self::TABLE, $values, ['id' => $id]);

            return $assignment->withId($id);
        }

        $id = $this->database->insert(self::TABLE, array_merge($values, [
            'lesson_id' => $assignment->lessonId,
            'created_at' => $now,
        ]));

        return $assignment->withId($id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Assignment
    {
        $allowed = $row['allowed_file_types'];
        $allowedList = [];

        if (\is_string($allowed) && trim($allowed) !== '') {
            $allowedList = array_values(array_filter(array_map(
                static fn (string $part): string => strtolower(trim($part)),
                explode(',', $allowed),
            ), static fn (string $part): bool => $part !== ''));
        }

        $dueAt = $row['due_at'];

        return new Assignment(
            (int) $row['id'],
            (int) $row['lesson_id'],
            $row['instructions'] === null ? null : (string) $row['instructions'],
            (float) $row['max_points'],
            $allowedList,
            $row['max_file_size_kb'] === null ? null : (int) $row['max_file_size_kb'],
            (int) $row['max_files'],
            \is_string($dueAt) ? new \DateTimeImmutable($dueAt, new \DateTimeZone('UTC')) : null,
            (int) $row['allow_resubmission'] === 1,
            isset($row['pass_percentage']) ? (float) $row['pass_percentage'] : null,
            json_decode((string) ($row['rubric_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
