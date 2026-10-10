<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;

/**
 * The channel accepts a list in one call. Default when absent: one call per
 * recipient.
 */
interface SupportsBatchSend
{
    /**
     * @param list<array{notification: Notification, context: SendContext}> $messages
     *
     * @return list<SendResult> one result per message, in the same order
     */
    public function sendBatch(array $messages): array;
}
