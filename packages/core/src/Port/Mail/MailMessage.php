<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Mail;

/**
 * One outbound mail, rendered.
 *
 * The channel renders and hands the mailer a finished message. Otherwise
 * there are two template systems and nothing says which wins: the core owns
 * notification_templates with its four parts, the RFC 2046 plain-first
 * ordering, token resolution, send-time locale, the RFC 3834 stamp and the
 * RFC 8058 header pair, while a host's own template layer would leave those
 * legal obligations enforced by adapter goodwill and untested in the core.
 * The host template is therefore an optional outer wrapper and nothing more.
 *
 * Two members were added in the same release as the notification queue and
 * both are load-bearing. The header map carries List-Unsubscribe,
 * List-Unsubscribe-Post and Auto-Submitted, and an adapter that controls DKIM
 * signing must include both List-Unsubscribe header names in the h= tag and
 * must not rewrite or drop them. The envelope sender carries a per-message
 * tag, which is what makes asynchronous bounce attribution possible at all.
 *
 * The parameter bag widened from array<string, scalar|null> to array<string,
 * mixed> alongside Notification, because the email channel passes a
 * notification's parameters straight into one of these with no
 * transformation, so widening one and not the other fails static analysis on
 * the first digest. What replaces scalarity is JSON safety: at the host
 * boundary the template layer receives a decoded structure, and a channel
 * that cannot pass one flattens it before constructing the message.
 */
final class MailMessage
{
    /**
     * @param array<string, mixed>  $parameters  JSON-safe values; a non-scalar arrives decoded at the host boundary
     * @param array<string, string> $headers     the List-* and Auto-Submitted map, which an adapter may not drop
     * @param list<MailAttachment>  $attachments dropped by an adapter that cannot attach, never a send failure
     */
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $recipientEmail,
        public readonly string $templateKey,
        public readonly array $parameters = [],
        public readonly ?string $eventReference = null,
        public readonly ?string $subject = null,
        public readonly ?string $bodyText = null,
        public readonly ?string $bodyHtml = null,
        public readonly array $headers = [],
        public readonly ?string $envelopeSender = null,
        public readonly ?string $locale = null,
        /**
         * Files travelling with this message.
         *
         * Trailing and defaulted, so every construction site written before
         * attachments existed keeps working unchanged. It is here rather than
         * on a second port because the notification registry has published
         * `SupportsAttachments` since the channel capabilities were seated and
         * nothing could ever hand a channel one: a declared capability with no
         * carrier is a capability that has never run.
         *
         * **An adapter that cannot attach drops them and records the send as
         * degraded**, which is that interface's own published default. It never
         * fails the send: a message that arrived without its file is better
         * than a message that never arrived, and the degraded flag is what
         * makes the difference visible.
         */
        public readonly array $attachments = [],
    ) {
    }

    /**
     * Whether the core rendered the body itself, which is the path every
     * queued message takes. A message with no rendered parts is the legacy
     * direct-send shape, where the host template renders from the key.
     */
    public function isRendered(): bool
    {
        return $this->bodyText !== null || $this->bodyHtml !== null;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
