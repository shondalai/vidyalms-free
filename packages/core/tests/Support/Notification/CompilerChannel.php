<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Notification;

use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\SendResult;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsShortForm;

final class CompilerChannel implements
    NotificationChannelInterface,
    SupportsHtmlBody,
    SupportsListHeaders,
    SupportsShortForm
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function send(Notification $notification, SendContext $context): SendResult
    {
        return SendResult::sent();
    }
}
