<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * An entitlement expired or was revoked and no other active entitlement
 * covers the course. Suspension never deletes progress.
 */
final class EnrolmentSuspended extends DomainEvent
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.enrolment.suspended';
    }

    public function payload(): array
    {
        return [
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'enrolment.suspended:' . $this->enrolmentId . ':' . $this->occurredAt->format('YmdHisu');
    }
}
