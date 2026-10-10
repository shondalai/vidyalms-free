<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Policy;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<NotificationPolicyInterface>
 */
final class NotificationPolicyRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'notification_policy';

    protected function providerInterface(): string
    {
        return NotificationPolicyInterface::class;
    }
}
