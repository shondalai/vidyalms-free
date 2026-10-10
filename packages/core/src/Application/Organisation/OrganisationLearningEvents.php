<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/** Request-scoped staging. The HTTP coordinator flushes only after atomic idempotency commits. */
final class OrganisationLearningEvents
{
    /** @var list<DomainEvent> */
    private array $pending = [];

    public function __construct(private readonly NotificationEnqueue $outbox, private readonly EventBus $events)
    {
    }

    /** @param list<DomainEvent> $events */
    public function stage(array $events): void
    {
        foreach ($events as $event) {
            $courseId = $event->payload()['course_id'] ?? null;
            $this->outbox->forEvent($event, \is_int($courseId) ? $courseId : null);
            $this->pending[] = $event;
        }
    }

    public function afterCommit(): void
    {
        $events = $this->pending;
        $this->pending = [];
        $this->events->dispatchAll($events);
    }

    public function discard(): void
    {
        $this->pending = [];
    }
}
