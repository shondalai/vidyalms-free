<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * A finished message: the four template parts with their tokens already
 * resolved, the header map and the envelope sender.
 *
 * Every email ships the plain part, and multipart alternatives are ordered
 * plain first and HTML last per RFC 2046 section 5.1.4, which orders parts by
 * increasing faithfulness. Which parts a channel consumes is a capability
 * probed with instanceof, never a branch on the channel key, which is what
 * lets a short-form channel take body_short and refuse HTML without the
 * sender knowing that channel exists.
 */
final class RenderedMessage
{
    /** RFC 3834: every message the platform originates is stamped. */
    public const HEADER_AUTO_SUBMITTED = 'Auto-Submitted';
    public const HEADER_LIST_UNSUBSCRIBE = 'List-Unsubscribe';
    public const HEADER_LIST_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';
    public const HEADER_LIST_ID = 'List-Id';

    /** RFC 8058 fixes this value exactly, and no other is accepted. */
    public const ONE_CLICK = 'List-Unsubscribe=One-Click';

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $parameters what the host template may still interpolate
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $bodyShort = null,
        public readonly array $headers = [],
        public readonly ?string $envelopeSender = null,
        public readonly string $locale = 'en-GB',
        public readonly string $templateKey = '',
        public readonly array $parameters = [],
    ) {
    }

    /**
     * The ordered alternative parts, plain first. A core test asserts this
     * order and no adapter may weaken it.
     *
     * @return list<array{type: string, body: string}>
     */
    public function alternativeParts(): array
    {
        $parts = [['type' => 'text/plain', 'body' => $this->bodyText]];

        if ($this->bodyHtml !== null && $this->bodyHtml !== '') {
            $parts[] = ['type' => 'text/html', 'body' => $this->bodyHtml];
        }

        return $parts;
    }

    public function hasUnsubscribeHeaders(): bool
    {
        return isset($this->headers[self::HEADER_LIST_UNSUBSCRIBE])
            && isset($this->headers[self::HEADER_LIST_UNSUBSCRIBE_POST]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->subject,
            $this->bodyText,
            $this->bodyHtml,
            $this->bodyShort,
            $headers + $this->headers,
            $this->envelopeSender,
            $this->locale,
            $this->templateKey,
            $this->parameters,
        );
    }

    public function withEnvelopeSender(?string $envelopeSender): self
    {
        return new self(
            $this->subject,
            $this->bodyText,
            $this->bodyHtml,
            $this->bodyShort,
            $this->headers,
            $envelopeSender,
            $this->locale,
            $this->templateKey,
            $this->parameters,
        );
    }
}
