<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assignments;

use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionFile;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseSubmissionRepository implements SubmissionRepository
{
    private const TABLE = 'submissions';
    private const FILES_TABLE = 'submission_files';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function ofId(int $id): ?Submission
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        if ($row === null) {
            return null;
        }

        return $this->map($row, $this->filesFor([$id]));
    }

    public function add(Submission $submission): Submission
    {
        $now = $this->clock->now()->format(self::DATETIME);

        return $this->database->transactional(function () use ($submission, $now): Submission {
            $id = $this->database->insert(self::TABLE, [
                'assignment_id' => $submission->assignmentId,
                'enrolment_id' => $submission->enrolmentId,
                'user_id' => $submission->userId,
                'attempt_number' => $submission->attemptNumber,
                'status' => $submission->status(),
                'text_response' => $submission->textResponse,
                'submitted_at' => $submission->submittedAt?->format(self::DATETIME),
                'graded_at' => $submission->gradedAt()?->format(self::DATETIME),
                'grader_id' => $submission->graderId(),
                'points_awarded' => $submission->pointsAwarded(),
                'feedback' => $submission->feedback(),
                'assessment_json' => json_encode($submission->assessment(), JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($submission->files as $file) {
                $this->database->insert(self::FILES_TABLE, [
                    'submission_id' => $id,
                    'path' => $file->path,
                    'filename' => $file->filename,
                    'size_bytes' => $file->sizeBytes,
                    'mime' => $file->mime,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $submission->withId($id);
        });
    }

    public function recordGrade(Submission $submission): void
    {
        if ($submission->id === null) {
            throw new \LogicException('Cannot record a grade for an unsaved submission.');
        }

        $this->database->update(self::TABLE, [
            'status' => $submission->status(),
            'graded_at' => $submission->gradedAt()?->format(self::DATETIME),
            'grader_id' => $submission->graderId(),
            'points_awarded' => $submission->pointsAwarded(),
            'feedback' => $submission->feedback(),
            'assessment_json' => json_encode($submission->assessment(), JSON_THROW_ON_ERROR),
            'updated_at' => $this->clock->now()->format(self::DATETIME),
        ], ['id' => $submission->id]);
    }

    public function maxAttemptNumber(int $assignmentId, int $userId): int
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->select(['attempt_number'])
                ->where('assignment_id', '=', $assignmentId)
                ->where('user_id', '=', $userId)
                ->orderBy('attempt_number', Query::SORT_DESC),
        );

        return $row === null ? 0 : (int) $row['attempt_number'];
    }

    public function hasFinalizedSubmission(int $assignmentId, int $userId): bool
    {
        $count = $this->database->count(
            Query::from(self::TABLE)
                ->where('assignment_id', '=', $assignmentId)
                ->where('user_id', '=', $userId)
                ->whereIn('status', [Submission::STATUS_SUBMITTED, Submission::STATUS_GRADED]),
        );

        return $count > 0;
    }

    public function listForUser(int $assignmentId, int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('assignment_id', '=', $assignmentId)
                ->where('user_id', '=', $userId)
                ->orderBy('attempt_number', Query::SORT_DESC),
        );

        return $this->mapAll($rows);
    }

    public function listForAssignment(int $assignmentId, ?string $status, int $limit, int $offset): array
    {
        $query = Query::from(self::TABLE)
            ->where('assignment_id', '=', $assignmentId)
            ->orderBy('id', Query::SORT_DESC)
            ->limit($limit)
            ->offset($offset);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $this->mapAll($this->database->selectAll($query));
    }

    public function countForAssignment(int $assignmentId, ?string $status): int
    {
        $query = Query::from(self::TABLE)->where('assignment_id', '=', $assignmentId);

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $this->database->count($query);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Submission>
     */
    private function mapAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $files = $this->filesFor($ids);

        return array_map(fn (array $row): Submission => $this->map($row, $files), $rows);
    }

    /**
     * @param list<int> $submissionIds
     *
     * @return array<int, list<SubmissionFile>>
     */
    private function filesFor(array $submissionIds): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::FILES_TABLE)
                ->whereIn('submission_id', $submissionIds)
                ->orderBy('id', Query::SORT_ASC),
        );

        $files = [];

        foreach ($rows as $row) {
            $files[(int) $row['submission_id']][] = new SubmissionFile(
                (string) $row['path'],
                (string) $row['filename'],
                (int) $row['size_bytes'],
                (string) $row['mime'],
            );
        }

        return $files;
    }

    /**
     * @param array<string, mixed>              $row
     * @param array<int, list<SubmissionFile>>  $filesBySubmission
     */
    private function map(array $row, array $filesBySubmission): Submission
    {
        $id = (int) $row['id'];

        return new Submission(
            $id,
            (int) $row['assignment_id'],
            (int) $row['enrolment_id'],
            (int) $row['user_id'],
            (int) $row['attempt_number'],
            (string) $row['status'],
            $row['text_response'] === null ? null : (string) $row['text_response'],
            $this->toDateTime($row['submitted_at']),
            $this->toDateTime($row['graded_at']),
            $row['grader_id'] === null ? null : (int) $row['grader_id'],
            $row['points_awarded'] === null ? null : (float) $row['points_awarded'],
            $row['feedback'] === null ? null : (string) $row['feedback'],
            $filesBySubmission[$id] ?? [],
            json_decode((string) ($row['assessment_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    private function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
