<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A way to reach a learner. The free build ships transactional email; paid
 * packages add in-app, webhook, SMS and push.
 *
 * send() returns a result rather than nothing, and that is the one shipped
 * signature in this design that genuinely changed. A void return meant the
 * queue could not tell a permanent failure from a transient one and could not
 * decide whether to retry, and there is no capability-interface escape for a
 * return value.
 *
 * The docblock changed with it. A channel no longer promises idempotency by
 * event reference, because the queue owns dedupe through its digest and its
 * claim. What a channel promises now is to report the outcome truthfully and
 * to classify permanent failure apart from transient failure, so a poisoned
 * row is stepped over instead of deadlocking the head of the queue.
 *
 * A channel never throws to signal a delivery failure. Throwing would put two
 * backoff regimes on one unit of work, composing differently under each
 * host's runner, with the runner's retries defeating the queue's attempt cap.
 *
 * What a channel can carry is probed with instanceof, never branched on by
 * key: SupportsHtmlBody, SupportsShortForm, SupportsAttachments,
 * SupportsListHeaders, SupportsBatchSend and ReportsDeliveryFeedback each
 * state their default when absent.
 */
interface NotificationChannelInterface extends ProviderInterface
{
    public function label(): string;

    public function send(Notification $notification, SendContext $context): SendResult;
}
