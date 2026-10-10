<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What a model bridge may read while it runs.
 *
 * It carries no Principal, no domain entity, no course id and no learner
 * name, and neither does the request beside it. A bridge that cannot name a
 * course cannot leak one, and that minimisation boundary holds even when the
 * redactor is replaced, which is stronger than describing the redaction stage
 * alone.
 *
 * The route params are here rather than on the bridge instance because a
 * bridge is one registered class serving many routes: the credential, the
 * model and the params belong to the row, not to the object.
 */
final class BridgeContext
{
    /** @var array<string, mixed> */
    public readonly array $routeParams;

    /**
     * @param array<string, mixed> $routeParams
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly \DateTimeImmutable $deadline,
        public readonly string $idempotencyKey,
        array $routeParams,
        public readonly LoggerInterface $logger,
    ) {
        $this->routeParams = $routeParams;
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    public static function create(
        \DateTimeImmutable $now,
        \DateTimeImmutable $deadline,
        string $idempotencyKey,
        array $routeParams = [],
        ?LoggerInterface $logger = null,
    ): self {
        return new self($now, $deadline, $idempotencyKey, $routeParams, $logger ?? new NullLogger());
    }

    public function hasExpired(): bool
    {
        return $this->now >= $this->deadline;
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
