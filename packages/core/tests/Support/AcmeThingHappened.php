<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * An event class the free build has never heard of.
 *
 * It exists so a test can prove the thing the design's own acceptance
 * criterion states: the shipped triggers bind six event classes, and a
 * package that binds a seventh must reach the queue with no core edit. The
 * subscriber used to snapshot the registry inside the kernel constructor, so
 * the list was frozen before the hook that registers providers had run.
 */
final class AcmeThingHappened extends DomainEvent
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $userId,
        public readonly int $courseId,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'acme.thing.happened';
    }

    public function payload(): array
    {
        return ['user_id' => $this->userId, 'course_id' => $this->courseId];
    }

    public function idempotencyKey(): string
    {
        return 'acme.thing:' . $this->userId . ':' . $this->courseId;
    }
}
