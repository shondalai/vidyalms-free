<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * @extends AbstractProviderRegistry<NotificationTriggerInterface>
 */
final class NotificationTriggerRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'notification_trigger';

    protected function providerInterface(): string
    {
        return NotificationTriggerInterface::class;
    }

    /**
     * The event triggers that act on this event, in registration order.
     *
     * @return list<EventTriggerInterface>
     */
    public function forEvent(DomainEvent $event): array
    {
        $matched = [];

        foreach ($this->all() as $trigger) {
            if (!$trigger instanceof EventTriggerInterface) {
                continue;
            }

            foreach ($trigger->eventClasses() as $eventClass) {
                if ($event instanceof $eventClass) {
                    $matched[] = $trigger;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * @return list<SweepTriggerInterface>
     */
    public function sweeps(): array
    {
        $sweeps = [];

        foreach ($this->all() as $trigger) {
            if ($trigger instanceof SweepTriggerInterface) {
                $sweeps[] = $trigger;
            }
        }

        return $sweeps;
    }

    /**
     * Every domain event class any registered event trigger binds to.
     *
     * This is a diagnostic listing for the rules screen and not a
     * subscription source: the subscriber binds once to the base event class
     * and resolves through forEvent() at dispatch time, because a list taken
     * at boot is frozen before a Pro or third-party package has registered
     * anything.
     *
     * @return list<class-string<DomainEvent>>
     */
    public function subscribedEventClasses(): array
    {
        $classes = [];

        foreach ($this->all() as $trigger) {
            if (!$trigger instanceof EventTriggerInterface) {
                continue;
            }

            foreach ($trigger->eventClasses() as $eventClass) {
                $classes[$eventClass] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * The categories the registered triggers declare, with the class the
     * category answers to, every class declared under it, and which triggers
     * declared it. There is no categories table for the same reason: a row
     * could otherwise name a category no provider owns.
     *
     * The class is folded to the **most restrictive** declared class and
     * never to the first declarer's. Many template keys map to one category
     * by design and the shipped catalogue really does mix classes under one
     * key (course_progress carries a transactional enrolment mail and an
     * optional drip nudge), so first-wins would let an administrator force a
     * category that carries an Optional message, which is the Article 21(3)
     * bypass that admits no balancing. The fold answers the only question the
     * category level is asked: which states the policy matrix may offer.
     *
     * @return array<string, array{class: string, declared_classes: list<string>, declared_by: list<string>}>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->all() as $trigger) {
            $key = $trigger->categoryKey();
            $class = $trigger->messageClass();

            if (!isset($categories[$key])) {
                $categories[$key] = ['class' => $class->value, 'declared_classes' => [], 'declared_by' => []];
            }

            if ($class->rank() < NotificationClass::from($categories[$key]['class'])->rank()) {
                $categories[$key]['class'] = $class->value;
            }

            if (!\in_array($class->value, $categories[$key]['declared_classes'], true)) {
                $categories[$key]['declared_classes'][] = $class->value;
            }

            $categories[$key]['declared_by'][] = $trigger->key();
        }

        foreach ($categories as $key => $category) {
            $classes = $category['declared_classes'];
            sort($classes);
            $categories[$key]['declared_classes'] = $classes;
        }

        return $categories;
    }

    /**
     * The class a category answers to, which is the most restrictive class
     * any of its declarers carries. A category no trigger declares has no
     * class, so the caller refuses rather than guessing.
     */
    public function classForCategory(string $categoryKey): ?NotificationClass
    {
        $categories = $this->categories();

        return isset($categories[$categoryKey])
            ? NotificationClass::from($categories[$categoryKey]['class'])
            : null;
    }
}
