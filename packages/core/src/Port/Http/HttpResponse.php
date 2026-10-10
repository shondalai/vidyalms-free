<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Http;

/**
 * What an outbound call answered.
 *
 * A refusal is a response and never an exception. A gateway package that has
 * to distinguish "the host is not on this site's allowlist" from "the remote
 * said 500" cannot do it from a caught throwable without matching on
 * messages, and an exception thrown out of a reconciliation pass stops the
 * whole pass rather than recording one failed call.
 *
 * `refusedReason` is set only when the adapter declined to make the call at
 * all: no port implementation, a host outside the allowlist, a URL scheme it
 * will not follow. A remote that answered, however badly, carries a status
 * and a null reason.
 */
final class HttpResponse
{
    public const REFUSED_NO_IMPLEMENTATION = 'http_port_not_implemented';
    public const REFUSED_HOST_NOT_ALLOWED = 'http_host_not_allowed';
    public const REFUSED_TIMEOUT = 'http_timeout';
    public const REFUSED_TRANSPORT = 'http_transport_failure';

    /** @param array<string, string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        public readonly ?string $refusedReason,
        public readonly float $elapsedSeconds,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function of(int $status, string $body = '', array $headers = [], float $elapsedSeconds = 0.0): self
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('An HTTP status is between 100 and 599.');
        }

        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        return new self($status, $body, $normalised, null, $elapsedSeconds);
    }

    /** The call was not made. Status 0, because no remote answered. */
    public static function refused(string $reason): self
    {
        if ($reason === '') {
            throw new \InvalidArgumentException('A refusal names the reason it refused.');
        }

        return new self(0, '', [], $reason, 0.0);
    }

    public function wasMade(): bool
    {
        return $this->refusedReason === null;
    }

    public function isSuccessful(): bool
    {
        return $this->wasMade() && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
