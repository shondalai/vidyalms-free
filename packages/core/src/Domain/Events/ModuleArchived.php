<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A module and everything in it left a curriculum. Emitted by ArchiveModule
 * on the transition only.
 *
 * lessons_archived counts the lessons that went with it, which is what makes
 * this the cause a listener can attribute a batch of completions to. No
 * LessonArchived is emitted per lesson: one authoring action is one
 * occurrence, and a module of forty lessons would otherwise be forty-one
 * events saying the same thing.
 *
 * Nothing is deleted, so a listener must not treat this as a deletion.
 */
final class ModuleArchived extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $courseId,
        public readonly int $moduleId,
        public readonly int $lessonsArchived,
        public readonly int $enrolmentsAffected,
        public readonly int $actorId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.module.archived';
    }

    public function payload(): array
    {
        return [
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'lessons_archived' => $this->lessonsArchived,
            'enrolments_affected' => $this->enrolmentsAffected,
            'actor_id' => $this->actorId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'module.archived:' . $this->moduleId . ':' . $this->occurredAt->format('YmdHisu');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->actorId, $this->courseId);
    }
}
