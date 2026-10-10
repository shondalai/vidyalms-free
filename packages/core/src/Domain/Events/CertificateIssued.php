<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

final class CertificateIssued extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $certificateId,
        public readonly string $code,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.certificate.issued';
    }

    public function payload(): array
    {
        return [
            'certificate_id' => $this->certificateId,
            'code' => $this->code,
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'certificate.issued:' . $this->certificateId;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, null, $this->enrolmentId);
    }
}
