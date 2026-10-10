<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A learner completed a course.
 */
final class CourseCompletedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.course_completed';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.course_completed';
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
        return [CourseCompleted::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), ['course.title', 'course.url']);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof CourseCompleted) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'enrolment_id' => $event->enrolmentId,
            'recipient_user_id' => $event->userId,
        ];
    }
}
