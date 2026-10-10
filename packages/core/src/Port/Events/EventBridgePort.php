<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * The outbound side of the core's eventing. The core emits each domain event
 * exactly once, with an idempotency key; the adapter fans it out to the host:
 * Joomla's Dispatcher (onVidyaLmsCourseCompleted), WordPress do_action
 * ("vidyalms/course_completed"). This is how Rewardify, CjForum, EasyCommerce,
 * WooCommerce and Pro attach.
 */
interface EventBridgePort
{
    public function publish(DomainEvent $event): void;
}
