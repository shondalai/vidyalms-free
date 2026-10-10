<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;

/**
 * What a channel is handed alongside the notification: the finished message,
 * the queue row it belongs to and the attempt number.
 *
 * The channel is handed a rendered message rather than a template key
 * because otherwise there are two template systems and the design never says
 * which wins. The core owns the four template parts, the plain-first
 * ordering, token resolution, send-time locale, the RFC 3834 stamp and the
 * RFC 8058 header pair; the host template is demoted to an optional outer
 * wrapper.
 */
final class SendContext
{
    private function __construct(
        public readonly RenderedMessage $message,
        public readonly ?int $queueId,
        public readonly int $attempt,
        public readonly string $recipientTimezone,
        public readonly \DateTimeImmutable $now,
        /** A verified, in-memory protected recipient; never persisted or logged. */
        public readonly ?string $protectedRecipientEmail,
    ) {
    }

    public static function create(
        RenderedMessage $message,
        ?int $queueId = null,
        int $attempt = 1,
        string $recipientTimezone = 'UTC',
        ?\DateTimeImmutable $now = null,
        ?string $protectedRecipientEmail = null,
    ): self {
        return new self(
            $message,
            $queueId,
            max(1, $attempt),
            $recipientTimezone,
            $now ?? new \DateTimeImmutable('@0'),
            $protectedRecipientEmail,
        );
    }

    /**
     * The per-message tag carried inside the envelope sender, which a channel
     * reports back as its provider message id so an asynchronous bounce
     * correlates to the row that caused it. Null when there is no queue row,
     * which is the direct-send path.
     *
     * It is the tag and not the address: the address is composed by the
     * sender from the channel's configured domain, and this is the half a
     * feedback report gives back.
     */
    public function envelopeTag(): ?string
    {
        return $this->queueId === null || $this->message->envelopeSender === null
            ? null : EnvelopeSender::tagOf($this->message->envelopeSender);
    }
}
