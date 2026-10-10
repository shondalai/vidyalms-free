<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A course exists. Emitted once, by CreateCourse, after the row and its lead
 * instructor are committed.
 *
 * The payload carries title and slug because that is what a host has to
 * mirror: the WordPress adapter keeps a permalink shell per course, and it
 * learns about a new one from this event rather than by recognising a REST
 * path, which was adapter-side knowledge of the contract and broke the
 * moment anything else created a course (an importer, a Pro package, a
 * WP-CLI command).
 */
final class CourseCreated extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $courseId,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $state,
        public readonly int $actorId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.course.created';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'title' => $this->title,
            'slug' => $this->slug,
            'state' => $this->state,
            'actor_id' => $this->actorId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'course.created:' . $this->courseId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->actorId, $this->courseId);
    }
}
