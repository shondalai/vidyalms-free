<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/** In-memory only: neither this recipient nor its parameters may be logged. */
final class SensitiveNotificationMessage
{
    public function __construct(public readonly string $recipientEmail, public readonly Notification $notification)
    {
    }
}
