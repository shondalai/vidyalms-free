<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationJobs;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;

/**
 * The thin subscriber over the trigger registry.
 *
 * It used to be the one God object this design names: a constant array of
 * three event classes, a private constant naming one channel, and a chain of
 * instanceof branches each hardcoding a template key and a parameter map. A
 * fourth trigger, a second channel or a site-defined rule meant editing a
 * shipped class. Its three branches are three free trigger providers now, its
 * channel constant is the rule's own channel list, and what it does is
 * subscribe to whatever the registry says the registered triggers bind to.
 *
 * It is a listener, and the enqueue it performs here is deliberately not the
 * outbox. The event bus wraps every listener in a try and logs, so an outbox
 * written from a listener commits the business state and loses the row. A use
 * case that must not lose its mail calls the enqueue directly inside its own
 * transaction; this subscriber is the safety net for every event that reaches
 * the bus by another road, and the queue's dedupe digest means the two paths
 * cannot double-enqueue one occurrence.
 */
final class SendTransactionalMail
{
    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly NotificationEnqueue $enqueue,
        /**
         * The listener path writes intent rows for events that reach the bus
         * by a road the outbox does not own, so it queues their fan-out too.
         * Optional because the enqueue is: a harness with no scheduler still
         * builds this listener, and the periodic fan-out is the net.
         */
        private readonly ?NotificationJobs $jobs = null,
    ) {
    }

    /**
     * Subscribes once, to the base event class.
     *
     * Snapshotting the registry's bound event classes here would freeze the
     * subscription list at construction: the kernel subscribes its listeners
     * inside its own constructor, and a Pro or third-party package registers
     * its triggers afterwards through the kernel-ready hook, so a trigger
     * bound to an event class no free trigger names would never be reached.
     * The bus matches with instanceof, which makes the base class a wildcard,
     * and the resolution happens where the standards require it: inside the
     * invocation, through the registry, at dispatch time.
     */
    public function subscribe(EventBus $bus): void
    {
        $bus->subscribe(DomainEvent::class, $this);
    }

    public function __invoke(DomainEvent $event): void
    {
        if ($this->triggers->forEvent($event) === []) {
            return;
        }

        $payload = $event->payload();
        $courseId = $payload['course_id'] ?? null;

        $written = $this->enqueue->forEvent($event, \is_numeric($courseId) ? (int) $courseId : null);

        foreach ($written as $intentId) {
            $this->jobs?->queueFanout($intentId);
        }
    }
}
