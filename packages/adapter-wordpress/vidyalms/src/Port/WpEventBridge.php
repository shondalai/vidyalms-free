<?php

declare(strict_types=1);

/**
 * VidyaLMS: the event bridge, core domain events onto WordPress actions.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;

defined('ABSPATH') || exit;

/**
 * Fan-out of core domain events onto WordPress actions.
 *
 * "vidyalms.course.completed" is dispatched as
 *
 *   do_action('vidyalms/course_completed', array $payload, string $idempotencyKey, DateTimeImmutable $occurredAt)
 *
 * so a listener can dedupe a redelivery itself. This is how the WooCommerce
 * bridge, the privacy hooks and Pro attach, and it is the one supported way to
 * react to learning events from another plugin.
 *
 * A listener that throws is logged and swallowed. The core emitted the event
 * once and has already committed the enrolment or the completion; a third party
 * plugin must not be able to unwind it.
 */
final class WpEventBridge implements EventBridgePort
{
    private const HOOK_PREFIX = 'vidyalms/';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        $hook = self::hookFor($event->eventName());

        try {
            do_action($hook, $event->payload(), $event->idempotencyKey(), $event->occurredAt);
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS event listener failed for {event}: {message}', [
                'event' => $hook,
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * "vidyalms.course.completed" becomes "vidyalms/course_completed".
     */
    public static function hookFor(string $coreEventName): string
    {
        $segments = explode('.', $coreEventName);

        if (($segments[0] ?? '') === 'vidyalms') {
            array_shift($segments);
        }

        return self::HOOK_PREFIX . implode('_', $segments);
    }
}
