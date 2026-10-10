<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A course became visible to learners. Emitted by PublishCourse on the
 * transition only: publishing a published course returns it unchanged and
 * emits nothing.
 *
 * A course can be archived and published again, so the occurrence is the
 * transition rather than the course.
 */
final class CoursePublished extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $courseId,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $actorId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.course.published';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'title' => $this->title,
            'slug' => $this->slug,
            'state' => 'published',
            'actor_id' => $this->actorId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'course.published:' . $this->courseId . ':' . $this->occurredAt->format('YmdHisu');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->actorId, $this->courseId);
    }
}
