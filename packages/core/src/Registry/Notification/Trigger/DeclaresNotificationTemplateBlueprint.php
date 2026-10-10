<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/**
 * Optional authored default for a trigger's template identity.
 *
 * A trigger without it remains valid. The studio gives that legacy provider
 * a safe, token-free generic blueprint and labels it as such.
 */
interface DeclaresNotificationTemplateBlueprint
{
    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint;
}
