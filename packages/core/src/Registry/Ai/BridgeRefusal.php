<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * Why a bridge produced no result, as one of twelve closed kinds.
 *
 * It is a return value and never an exception, because a ceiling reached, a
 * model that declined or a deadline is a thing a surface has to render and a
 * runner has to decide about. The kind is what the retry decision reads, and
 * the retry instant is carried here rather than parsed out of a message,
 * because a rate limit that says when is the one refusal whose answer is a
 * time.
 *
 * The provider request id is here for the same reason it is on a result: a
 * billing dispute or an outage post-mortem asks about the calls that failed
 * at least as often as about the ones that worked.
 */
final class BridgeRefusal
{
    /** @var array<string, scalar|null> */
    public readonly array $parameters;

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function __construct(
        public readonly RefusalKind $kind,
        public readonly string $messageKey,
        array $parameters,
        public readonly ?\DateTimeImmutable $retryAfter,
        public readonly ?string $providerRequestId,
    ) {
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function of(
        RefusalKind $kind,
        string $messageKey,
        array $parameters = [],
        ?string $providerRequestId = null,
    ): self {
        return new self($kind, $messageKey, $parameters, null, $providerRequestId);
    }

    /**
     * The one refusal whose honest answer is an instant. A runner given one
     * defers to it rather than running its own ladder over the top.
     *
     * @param array<string, scalar|null> $parameters
     */
    public static function rateLimited(
        \DateTimeImmutable $retryAfter,
        string $messageKey = 'ai.refusal.upstream_rate_limited',
        array $parameters = [],
        ?string $providerRequestId = null,
    ): self {
        return new self(RefusalKind::UpstreamRateLimited, $messageKey, $parameters, $retryAfter, $providerRequestId);
    }

    public function isRetryable(): bool
    {
        return $this->kind->isRetryable();
    }
}
