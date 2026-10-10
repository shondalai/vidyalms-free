<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * Emitted once per enrolment and lesson, on the transition to completed.
 */
final class LessonCompleted extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $enrolmentId,
        public readonly int $lessonId,
        public readonly int $userId,
        public readonly int $courseId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.lesson.completed';
    }

    public function payload(): array
    {
        return [
            'enrolment_id' => $this->enrolmentId,
            'lesson_id' => $this->lessonId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'lesson.completed:' . $this->enrolmentId . ':' . $this->lessonId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, $this->lessonId, $this->enrolmentId);
    }
}
