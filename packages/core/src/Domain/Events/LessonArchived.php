<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A lesson left a curriculum. Emitted by ArchiveLesson on the transition
 * only: archiving an archived lesson answers the same thing again and emits
 * nothing.
 *
 * This is the causal link between one authoring click and a batch of
 * completions. Archiving the last thing a cohort had not done finishes the
 * course for all of them, which fires CourseCompleted and CertificateIssued
 * per learner minutes later; without this event a search index, a cache, a
 * drip schedule or a host mirroring the curriculum sees that storm with no
 * cause attached.
 *
 * Nothing is deleted, so a listener must not treat this as a deletion.
 */
final class LessonArchived extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $courseId,
        public readonly int $lessonId,
        public readonly int $moduleId,
        public readonly int $lessonsArchived,
        public readonly int $enrolmentsAffected,
        public readonly int $actorId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.lesson.archived';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'lesson_id' => $this->lessonId,
            'module_id' => $this->moduleId,
            'lessons_archived' => $this->lessonsArchived,
            'enrolments_affected' => $this->enrolmentsAffected,
            'actor_id' => $this->actorId,
        ];
    }

    /**
     * A lesson archives once, and restoring and archiving again is a new
     * occurrence, so the moment is part of the key.
     */
    public function idempotencyKey(): string
    {
        return 'lesson.archived:' . $this->lessonId . ':' . $this->occurredAt->format('YmdHisu');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->actorId, $this->courseId, $this->lessonId);
    }
}
