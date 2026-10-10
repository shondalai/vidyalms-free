<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\ContentUnlocked;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A scheduled item became available.
 *
 * It is free rather than paid because the free build ships a condition that
 * really does name an opening instant and therefore really does schedule
 * unlock tasks. A free task whose only consumer is a paid trigger is a task
 * the free build cannot service, and the alternative, having the task handler
 * hand a notification to the queue itself, skips the whole dispatch model.
 *
 * Its rule is seeded disabled, so nothing changes for a site that does not
 * enable it, and there is deliberately no drip mailer anywhere: telling the
 * learner when the next item opens is this trigger and the queue, not a
 * subsystem.
 */
final class ContentUnlockedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.content_unlocked';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.content_unlocked';
    }

    public function categoryKey(): string
    {
        return 'course_progress';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Optional;
    }

    public function defaultAudienceKey(): string
    {
        return 'learner';
    }

    public function defaultTemplateKey(): string
    {
        return 'vidyalms.availability.unlocked';
    }

    public function eventClasses(): array
    {
        return [ContentUnlocked::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), ['course.title', 'course.url']);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof ContentUnlocked) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'enrolment_id' => $event->enrolmentId,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'recipient_user_id' => $event->userId,
        ];
    }

    public function subjectFor(DomainEvent $event): array
    {
        if (!$event instanceof ContentUnlocked) {
            return [null, null];
        }

        return [$event->subjectType, $event->subjectId];
    }
}
