<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/** One unambiguous trigger owner and its template blueprint. */
final class TemplateBlueprintRegistration
{
    public function __construct(
        public readonly NotificationTriggerInterface $trigger,
        public readonly NotificationTemplateBlueprint $blueprint,
        public readonly bool $generic,
    ) {
    }
}
