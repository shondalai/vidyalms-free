<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a delivery feedback mapper may read: which channel the report arrived
 * for, when it arrived, and the request headers the transport carried.
 *
 * The shared secret that authenticated the request is deliberately not here.
 * A mapper has no business reading it, and a context that carried it would
 * hand every third-party mapper the channel's credential.
 */
final class FeedbackContext
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $channelKey,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly array $headers,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function create(string $channelKey, \DateTimeImmutable $receivedAt, array $headers = []): self
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        return new self($channelKey, $receivedAt, $normalised);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
