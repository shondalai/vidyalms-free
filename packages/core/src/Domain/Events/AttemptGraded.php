<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * Final or manual grading finished for an attempt. Re-grading emits again
 * with a later timestamp; listeners that must act once per attempt key on
 * the attempt id from the payload.
 */
final class AttemptGraded extends DomainEvent
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
        public readonly bool $passed,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.attempt.graded';
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
        ];
    }

    public function idempotencyKey(): string
    {
        return 'attempt.graded:' . $this->attemptId . ':' . $this->occurredAt->format('YmdHisu');
    }
}
