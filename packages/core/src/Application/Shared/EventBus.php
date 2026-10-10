<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;

/**
 * Dispatches domain events to internal listeners first (completion issuing a
 * certificate, transactional mail), then across the bridge to the host CMS.
 *
 * A listener failure is logged and does not stop other listeners or the
 * bridge: side effects are individually idempotent by the event's key, so a
 * retry of the triggering operation can repair a partial fan-out without
 * duplicating what already succeeded.
 */
final class EventBus
{
    /** @var array<class-string, list<callable(DomainEvent): void>> */
    private array $listeners = [];

    /** @var list<callable(): void>|null Side effects waiting for an enclosing transaction. */
    private ?array $deferred = null;

    public function __construct(
        private readonly EventBridgePort $bridge,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param class-string $eventClass
     * @param callable(DomainEvent): void $listener
     */
    public function subscribe(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        if ($this->deferred !== null) {
            $this->deferred[] = fn () => $this->dispatch($event);
            return;
        }
        foreach ($this->listeners as $eventClass => $listeners) {
            if (!$event instanceof $eventClass) {
                continue;
            }

            foreach ($listeners as $listener) {
                try {
                    $listener($event);
                } catch (\Throwable $failure) {
                    $this->logger->error('VidyaLMS event listener failed.', [
                        'event' => $event->eventName(),
                        'idempotency_key' => $event->idempotencyKey(),
                        'exception' => $failure,
                    ]);
                }
            }
        }

        try {
            $this->bridge->publish($event);
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS event bridge failed.', [
                'event' => $event->eventName(),
                'idempotency_key' => $event->idempotencyKey(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * @param list<DomainEvent> $events
     */
    public function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    /**
     * Release events and job scheduling only once the supplied transaction
     * succeeds. Nested calls feed the enclosing buffer; a rollback discards
     * its own side effects.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function afterSuccessful(callable $operation): mixed
    {
        $parent = $this->deferred;
        $this->deferred = [];
        try {
            $result = $operation();
            $effects = $this->endDeferral($parent);
        } catch (\Throwable $error) {
            $this->deferred = $parent;
            throw $error;
        }
        foreach ($effects as $effect) {
            $this->afterCommit($effect);
        }
        return $result;
    }

    /**
     * @param list<callable(): void>|null $parent
     * @return list<callable(): void>
     */
    private function endDeferral(?array $parent): array
    {
        $effects = $this->deferred ?? [];
        $this->deferred = $parent;
        return $effects;
    }

    /** @param callable(): void $effect */
    public function afterCommit(callable $effect): void
    {
        if ($this->deferred !== null) {
            $this->deferred[] = $effect;
            return;
        }
        $effect();
    }
}
