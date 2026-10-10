<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * What a channel says happened.
 *
 * The queue cannot decide whether to retry without this, and a capability
 * interface cannot retrofit a return value, which is why the channel
 * signature changed rather than gaining a probe.
 */
enum SendOutcome: string
{
    case Sent = 'sent';
    case Deferred = 'deferred';
    case FailedTransient = 'failed_transient';
    case FailedPermanent = 'failed_permanent';

    /** A permanent failure is never retried. A poisoned row is stepped over. */
    public function isRetryable(): bool
    {
        return $this === self::FailedTransient;
    }

    public function isTerminal(): bool
    {
        return $this === self::Sent || $this === self::FailedPermanent;
    }
}
