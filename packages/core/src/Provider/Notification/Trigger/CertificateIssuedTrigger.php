<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\CertificateIssued;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A certificate was issued.
 *
 * The verification URL is composed the same way the render path composes it,
 * from a host fact the core cannot know. An empty base yields an empty URL
 * rather than a broken link.
 */
final class CertificateIssuedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.certificate_issued';
    public const FACT_VERIFICATION_BASE_URL = 'verification_base_url';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.certificate_issued';
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
        return [CertificateIssued::class];
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
        if (!$event instanceof CertificateIssued) {
            return null;
        }

        // The code is the certificate's public identity and is what the
        // template needs; the composed verification URL is not stored beside
        // it, because a live credential URL sitting in a terminal queue row
        // forever is a disclosure the row has no reason to carry. The
        // renderer composes it from the code and the site's own base.
        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'certificate_id' => $event->certificateId,
            'code' => $event->code,
            'enrolment_id' => $event->enrolmentId,
            'recipient_user_id' => $event->userId,
        ];
    }
}
