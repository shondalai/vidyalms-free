<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A learner enrolled in a course.
 *
 * One of the three branches a shipped class used to hold as hardcoded
 * instanceof checks with a template key and a parameter map each. A fourth
 * trigger, a second channel or a site-defined rule meant editing that class;
 * it is a registration now.
 */
final class EnrolledTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.enrolled';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.enrolled';
    }

    public function categoryKey(): string
    {
        return 'course_progress';
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
        return [LearnerEnrolled::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), ['course.title', 'course.url']);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof LearnerEnrolled) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'enrolment_id' => $event->enrolmentId,
            'entitlement_id' => $event->entitlementId,
            'recipient_user_id' => $event->userId,
        ];
    }
}
