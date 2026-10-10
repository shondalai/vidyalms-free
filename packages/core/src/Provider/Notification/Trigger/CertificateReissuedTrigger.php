<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\CertificateReissued;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A certificate was corrected and replaced.
 *
 * It is a separate trigger rather than a second use of the issuance one,
 * because a learner who is told twice that they have earned a certificate
 * reads the second message as a second credential. This message says the
 * opposite: the one you hold has been replaced, and here is the new code.
 *
 * Transactional, like issuance: a holder whose credential has been superseded
 * has to be told whatever their marketing preferences are, because the code
 * printed on the artefact they are carrying no longer resolves to a live
 * certificate.
 */
final class CertificateReissuedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.certificate.reissued';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.certificate_reissued';
    }

    public function categoryKey(): string
    {
        return 'credential';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Transactional;
    }

    public function defaultAudienceKey(): string
    {
        return 'learner';
    }

    public function defaultTemplateKey(): string
    {
        return self::KEY;
    }

    public function eventClasses(): array
    {
        return [CertificateReissued::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), [
            'course.title',
            'certificate.code',
            'certificate.verification_url',
        ]);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof CertificateReissued) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'certificate_id' => $event->certificateId,
            'superseded_certificate_id' => $event->supersededCertificateId,
            'code' => $event->code,
            'enrolment_id' => $event->enrolmentId,
            'recipient_user_id' => $event->userId,
        ];
    }

    /**
     * The subject is the certificate that was replaced, not the course and
     * not the new row. A duplicate window keyed on the course would collapse
     * a whole cohort's corrections into one message, and one keyed on the new
     * id would be different on every restart of the job.
     *
     * @return array{0: string|null, 1: int|null}
     */
    /**
     * What an occurrence of this trigger can be about: the recipient is the
     * learner and the subject is the thing they did it to.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return ['user', 'certificate'];
    }

    public function subjectFor(DomainEvent $event): array
    {
        return $event instanceof CertificateReissued
            ? ['certificate', $event->supersededCertificateId]
            : [null, null];
    }
}
