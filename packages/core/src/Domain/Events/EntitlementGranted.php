<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

final class EntitlementGranted extends DomainEvent
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $entitlementId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly string $source,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.entitlement.granted';
    }

    public function payload(): array
    {
        return [
            'entitlement_id' => $this->entitlementId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'source' => $this->source,
        ];
    }

    public function idempotencyKey(): string
    {
        return 'entitlement.granted:' . $this->entitlementId;
    }
}
