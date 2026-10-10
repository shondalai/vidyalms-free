<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A gated row became available to one learner.
 *
 * The moment is part of the idempotency key, and it is not optional. This
 * design's own access model guarantees the occurrence repeats: evaluating on
 * read from current facts means a downward regrade retracts access and a
 * later resit restores it, and a learner moved between runs re-blocks and
 * re-opens whole modules while deliberately keeping the same enrolment id.
 * Without the moment the second unlock emits nothing, the activity timeline
 * is missing an event, and the mail is silently dropped.
 *
 * Exactly one mail per unlock comes from the queue's occurrence key, which
 * buckets the unlock instant to the minute, rather than from an event key
 * that can only ever be used once.
 */
final class ContentUnlocked extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $enrolmentId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly string $subjectType,
        public readonly int $subjectId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.content.unlocked';
    }

    public function payload(): array
    {
        return [
            'enrolment_id' => $this->enrolmentId,
            'course_id' => $this->courseId,
            'user_id' => $this->userId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ];
    }

    public function idempotencyKey(): string
    {
        return sprintf(
            'content.unlocked:%d:%s:%d:%s',
            $this->enrolmentId,
            $this->subjectType,
            $this->subjectId,
            $this->occurredAt->format('YmdHisu'),
        );
    }

    /** The occurrence key the queue deduplicates on: the instant to the minute. */
    public function occurrenceKey(): string
    {
        return $this->occurredAt->format('YmdHi');
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef(
            $this->userId,
            $this->courseId,
            $this->subjectType === 'lesson' ? $this->subjectId : null,
            $this->enrolmentId,
        );
    }
}
