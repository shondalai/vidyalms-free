<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

interface SubmissionRepository
{
    public function ofId(int $id): ?Submission;

    /**
     * Writes the submission row and its file rows atomically. Returns the
     * persisted submission carrying its id.
     */
    public function add(Submission $submission): Submission;

    /**
     * Persists the grading fields of an already stored submission.
     */
    public function recordGrade(Submission $submission): void;

    /**
     * Highest attempt number the user has for the assignment, 0 when none.
     */
    public function maxAttemptNumber(int $assignmentId, int $userId): int;

    /**
     * Whether the user already has a submitted or graded attempt.
     */
    public function hasFinalizedSubmission(int $assignmentId, int $userId): bool;

    /**
     * The user's own attempts for one assignment, newest first.
     *
     * @return list<Submission>
     */
    public function listForUser(int $assignmentId, int $userId): array;

    /**
     * @return list<Submission>
     */
    public function listForAssignment(int $assignmentId, ?string $status, int $limit, int $offset): array;

    public function countForAssignment(int $assignmentId, ?string $status): int;
}
