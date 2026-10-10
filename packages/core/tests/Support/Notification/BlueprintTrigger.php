<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Notification;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresNotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;

final class BlueprintTrigger implements NotificationTriggerInterface, DeclaresNotificationTemplateBlueprint
{
    public const TEMPLATE_KEY = 'test.learning_update';

    public function __construct(
        private readonly NotificationClass $class,
        private readonly NotificationTemplateBlueprint $blueprint,
    ) {
    }

    public function key(): string
    {
        return 'test.trigger';
    }

    public function label(): string
    {
        return 'Test trigger';
    }

    public function kind(): string
    {
        return self::KIND_EVENT;
    }

    public function categoryKey(): string
    {
        return 'learning';
    }

    public function messageClass(): NotificationClass
    {
        return $this->class;
    }

    public function defaultAudienceKey(): string
    {
        return 'recipient';
    }

    public function defaultTemplateKey(): string
    {
        return self::TEMPLATE_KEY;
    }

    public function declaredPlaceholders(): array
    {
        return ['user.name', 'user.first_name', 'course.url', 'site.name', 'site.logo_url'];
    }

    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint
    {
        return $this->blueprint;
    }
}
