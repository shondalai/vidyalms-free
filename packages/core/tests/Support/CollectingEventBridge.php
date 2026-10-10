<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;

final class CollectingEventBridge implements EventBridgePort
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent $event): void
    {
        $this->published[] = $event;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (DomainEvent $event): string => $event->eventName(), $this->published);
    }
}
