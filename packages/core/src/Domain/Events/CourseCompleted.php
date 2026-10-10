<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * The spine's terminal event, emitted exactly once per enrolment when the
 * completion engine's decision transitions to complete.
 */
final class CourseCompleted extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $enrolmentId,
        public readonly int $courseId,
        public readonly int $userId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.course.completed';
    }

    public function payload(): array
    {
        return [
            'enrolment_id' => $this->enrolmentId,
            'course_id' => $this->courseId,
            'user_id' => $this->userId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'course.completed:' . $this->enrolmentId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, null, $this->enrolmentId);
    }
}
