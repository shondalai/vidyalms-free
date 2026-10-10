<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel correlates a provider message id, so an asynchronous bounce or
 * complaint can be matched back to the queue row that produced it. Default
 * when absent: no asynchronous correlation.
 */
interface ReportsDeliveryFeedback
{
    /**
     * The delivery feedback mapper key this channel's reports arrive in, so
     * the inbound route can pick one without the channel naming a class.
     */
    public function feedbackMapperKey(): string;
}
