<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

final class EnrolmentResumed extends DomainEvent
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
        return 'vidyalms.enrolment.resumed';
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
        return 'enrolment.resumed:' . $this->enrolmentId . ':' . $this->occurredAt->format('YmdHisu');
    }
}
