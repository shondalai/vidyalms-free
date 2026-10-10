<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Events\EventBridgePort;

/**
 * Fan-out of core domain events onto Joomla's dispatcher.
 *
 * "vidyalms.course.completed" is dispatched as onVidyaLmsCourseCompleted with
 * the event payload, the occurrence time and the idempotency key, so listeners
 * (Rewardify, CjForum, EasyCommerce, Pro) can dedupe a redelivery themselves.
 *
 * A listener that throws is logged and swallowed. The core emitted the event
 * once and has already committed; a third-party plugin must not be able to
 * unwind a completed enrolment.
 *
 * @since 1.0.0
 */
final class JoomlaEventBridge implements EventBridgePort
{
    private const NAME_PREFIX = 'onVidyaLms';

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        $name = self::joomlaEventName($event->eventName());

        $arguments = [
            'eventName' => $event->eventName(),
            'idempotencyKey' => $event->idempotencyKey(),
            'occurredAt' => $event->occurredAt,
            'payload' => $event->payload(),
            'result' => [],
        ];

        try {
            if (in_array($name, ['onVidyaLmsCourseCreated', 'onVidyaLmsCoursePublished',
                'onVidyaLmsCourseUpdated', 'onVidyaLmsCourseArchived'], true)) {
                PluginHelper::importPlugin('finder', 'vidyalms');
            }
            $this->dispatcher->dispatch($name, new GenericEvent($name, $arguments));
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS event listener failed for {event}: {message}', [
                'event' => $name,
                'message' => $failure->getMessage(),
                'exception' => $failure,
            ]);
        }
    }

    /**
     * "vidyalms.course.completed" becomes "onVidyaLmsCourseCompleted".
     */
    public static function joomlaEventName(string $coreEventName): string
    {
        $segments = explode('.', $coreEventName);

        if (($segments[0] ?? '') === 'vidyalms') {
            array_shift($segments);
        }

        $name = '';

        foreach ($segments as $segment) {
            foreach (explode('_', $segment) as $word) {
                $name .= ucfirst(strtolower($word));
            }
        }

        return self::NAME_PREFIX . $name;
    }
}
