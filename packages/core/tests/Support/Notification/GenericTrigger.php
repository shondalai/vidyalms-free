<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Notification;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;

final class GenericTrigger implements NotificationTriggerInterface
{
    public function __construct(
        private readonly string $providerKey,
        private readonly string $templateKey,
    ) {
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return 'Third-party trigger';
    }

    public function kind(): string
    {
        return self::KIND_EVENT;
    }

    public function categoryKey(): string
    {
        return 'third_party';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Operational;
    }

    public function defaultAudienceKey(): string
    {
        return 'recipient';
    }

    public function defaultTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function declaredPlaceholders(): array
    {
        return [];
    }
}
