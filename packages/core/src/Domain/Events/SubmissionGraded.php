<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;

final class SubmissionGraded extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $submissionId,
        public readonly int $assignmentId,
        public readonly int $lessonId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly float $pointsAwarded,
        public readonly float $maxPoints,
        public readonly bool $approved = true,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.submission.graded';
    }

    public function payload(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'assignment_id' => $this->assignmentId,
            'lesson_id' => $this->lessonId,
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'points_awarded' => $this->pointsAwarded,
            'max_points' => $this->maxPoints,
            'approved' => $this->approved,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'submission.graded:' . $this->submissionId . ':' . $this->occurredAt->format('YmdHisu');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, $this->lessonId, $this->enrolmentId);
    }
}
