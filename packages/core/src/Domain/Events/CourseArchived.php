<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A course left the catalogue. Emitted by ArchiveCourse on the transition
 * only: archiving an archived course returns it unchanged and emits nothing.
 *
 * Archiving is reversible and destroys nothing: enrolments, progress,
 * attempts and certificates stay exactly where they were, which is why this
 * is not called "deleted" and why a listener must not treat it as one.
 */
final class CourseArchived extends DomainEvent implements ActivityProjectable
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
        return 'vidyalms.course.archived';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'title' => $this->title,
            'slug' => $this->slug,
            'state' => 'archived',
            'actor_id' => $this->actorId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'course.archived:' . $this->courseId . ':' . $this->occurredAt->format('YmdHisu');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->actorId, $this->courseId);
    }
}
