<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class ListSubmissionsForAssignment
{
    private const MAX_LIMIT = 100;

    private const STATUSES = [
        Submission::STATUS_DRAFT,
        Submission::STATUS_SUBMITTED,
        Submission::STATUS_GRADED,
    ];

    public function __construct(
        private readonly SubmissionRepository $submissions,
        private readonly AssignmentRepository $assignments,
        private readonly DatabasePort $database,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @return array{items: list<Submission>, total: int, limit: int, offset: int}
     */
    public function execute(
        Principal $principal,
        int $assignmentId,
        ?string $status = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        $this->access->requireSignedIn($principal);

        $assignment = $this->assignments->ofId($assignmentId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Assignment', $assignmentId);
        $lesson = $this->database->selectOne(
            Query::from('lessons')->select(['id', 'course_id'])->where('id', '=', $assignment->lessonId),
        );

        if ($lesson === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $assignment->lessonId);
        }

        $this->access->require($principal, (int) $lesson['course_id'], Capability::GRADE);

        if ($status !== null && !\in_array($status, self::STATUSES, true)) {
            throw ValidationException::forField('status', 'Status must be one of draft, submitted or graded.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('limit', sprintf('Limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        if ($offset < 0) {
            throw ValidationException::forField('offset', 'Offset must be zero or positive.');
        }

        return [
            'items' => $this->submissions->listForAssignment($assignmentId, $status, $limit, $offset),
            'total' => $this->submissions->countForAssignment($assignmentId, $status),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
