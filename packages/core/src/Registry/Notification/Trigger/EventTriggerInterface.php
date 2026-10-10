<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;

/**
 * A trigger bound to domain event classes.
 *
 * parametersFor() answers null when this event is not one this trigger fires
 * on, which is how three hardcoded instanceof branches in one shipped class
 * became three registrations.
 */
interface EventTriggerInterface extends NotificationTriggerInterface
{
    /**
     * @return list<class-string<DomainEvent>>
     */
    public function eventClasses(): array;

    /**
     * The template parameters for this occurrence, or null when the event is
     * not one this trigger acts on.
     *
     * @return array<string, mixed>|null
     */
    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array;

    /**
     * The subject this occurrence is about, so the duplicate window and
     * cross-rule suppression read real columns rather than parsing the event
     * reference, which is an opaque identity no policy may parse.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public function subjectFor(DomainEvent $event): array;
}
