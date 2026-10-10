<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A course's stored fields changed. Emitted by UpdateCourse, and only when
 * something actually changed: a PATCH that sets a field to what it already
 * held is not an occurrence.
 *
 * "changed" names the fields, so a listener that only cares about the slug
 * (a permalink shell, a sitemap, a cache key) can ignore everything else
 * without diffing the payload against its own copy.
 *
 * This is the one course event the activity log does not project. A course
 * being saved eleven times while it is written is eleven rows on a learner
 * timeline that no learner did anything on.
 */
final class CourseUpdated extends DomainEvent
{
    /**
     * @param list<string> $changed
     */
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $courseId,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $state,
        public readonly array $changed,
        public readonly int $actorId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.course.updated';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'title' => $this->title,
            'slug' => $this->slug,
            'state' => $this->state,
            'changed' => $this->changed,
            'actor_id' => $this->actorId,
        ];
    }

    /**
     * An update is repeatable, so the occurrence is the course, the moment
     * and what moved.
     *
     * The moment carries microseconds and the changed list is part of the
     * key, because an autosaving authoring form produces several saves inside
     * one second and they are different occurrences with different consequences:
     * a title change followed by a slug change is two things a permalink
     * mirror has to do, and one key for both is the second one lost.
     */
    public function idempotencyKey(): string
    {
        $changed = $this->changed;
        sort($changed);

        return 'course.updated:' . $this->courseId
            . ':' . $this->occurredAt->format('YmdHisu')
            . ':' . implode(',', $changed);
    }
}
