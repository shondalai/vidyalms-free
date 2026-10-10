<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * A certificate was replaced by a corrected one.
 *
 * The idempotency key names the row that was replaced and the revision it was
 * replaced at, never the row that was created. A key built from the new
 * certificate id is different on every run, so a bulk reissue killed halfway
 * and restarted would mail the first half of a cohort a second time. This is
 * the same invariant the sweep triggers carry, applied to the one other
 * occurrence in the design with no domain event of its own.
 */
final class CertificateReissued extends DomainEvent implements ActivityProjectable
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $certificateId,
        public readonly int $supersededCertificateId,
        public readonly string $code,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $templateRevision,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.certificate.reissued';
    }

    public function payload(): array
    {
        return [
            'certificate_id' => $this->certificateId,
            'superseded_certificate_id' => $this->supersededCertificateId,
            'code' => $this->code,
            'enrolment_id' => $this->enrolmentId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'template_revision' => $this->templateRevision,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'certificate.reissued:' . $this->supersededCertificateId . ':' . $this->templateRevision;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId, $this->courseId, null, $this->enrolmentId);
    }
}
