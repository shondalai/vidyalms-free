<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback;

/** Authenticated secret hydration within the existing queue's lease and policies. */
interface SensitiveNotificationDelivery
{
    public function handles(QueuedNotification $row): bool;
    public function prepare(QueuedNotification $row): ?SensitiveNotificationMessage;
    public function feedback(QueuedNotification $row, DeliveryFeedback $report): void;
    public function purge(int $queueId): void;
    public function prune(int $limit): void;
}
