<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Http;

/**
 * An outbound integration request. Adapters enforce host policy and may lower
 * the requested timeout. Body bytes are preserved for signed payloads.
 */
final class HttpRequest
{
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];

    /** @param array<string, string> $headers */
    private function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        /**
         * The longest this caller is willing to wait.
         *
         * A preference and not a grant: the adapter clamps it to its own
         * configured ceiling, so a provider cannot hold a request open past
         * what the site allows. Null means "the adapter's own default".
         */
        public readonly ?int $timeoutSeconds,
        /** For a log line and a support thread, never sent. */
        public readonly ?string $correlationId,
        /**
         * Whether a failed call may be retried by the caller at all.
         *
         * The caller must know whether its operation is idempotent before
         * allowing retries. Transport availability does not make a retry safe.
         */
        public readonly bool $retryable,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function create(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?int $timeoutSeconds = null,
        ?string $correlationId = null,
        bool $retryable = false,
    ): self {
        $method = strtoupper($method);

        if (!\in_array($method, self::METHODS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method));
        }

        if (!str_starts_with($url, 'https://')) {
            // Plain HTTP is refused at the type rather than by a setting. Every
            // call this port exists for carries a credential or a signature,
            // and a downgrade is the one failure a host allowlist does not
            // catch.
            throw new \InvalidArgumentException('An outbound request is https; plain HTTP carries credentials in clear.');
        }

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new \InvalidArgumentException('A timeout preference is at least one second.');
        }

        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        return new self($method, $url, $normalised, $body, $timeoutSeconds, $correlationId, $retryable);
    }

    /** The host an allowlist is checked against, or an empty string when the URL has none. */
    public function host(): string
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = parse_url($this->url, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : '';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
