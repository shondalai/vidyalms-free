<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

final class AttemptSubmitted extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $attemptId,
        public readonly int $quizId,
        public readonly ?int $lessonId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly float $percentage,
        public readonly ?bool $passed,
        public readonly bool $requiresManualGrading,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.attempt.submitted';
    }

    public function payload(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'quiz_id' => $this->quizId,
            'lesson_id' => $this->lessonId,
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'percentage' => $this->percentage,
            'passed' => $this->passed,
            'requires_manual_grading' => $this->requiresManualGrading,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'attempt.submitted:' . $this->attemptId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, $this->lessonId, $this->enrolmentId);
    }
}
