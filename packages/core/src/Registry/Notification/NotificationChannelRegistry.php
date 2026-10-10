<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<NotificationChannelInterface>
 */
final class NotificationChannelRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return NotificationChannelInterface::class;
    }
}
