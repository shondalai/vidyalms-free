<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

final class LearnerEnrolled extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $entitlementId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.enrolment.created';
    }

    public function payload(): array
    {
        return [
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'entitlement_id' => $this->entitlementId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'enrolment.created:' . $this->enrolmentId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, null, $this->enrolmentId);
    }
}
