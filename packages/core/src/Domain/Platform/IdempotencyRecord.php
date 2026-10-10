<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * The stored state of one idempotency key: claimed while a request is in
 * flight, completed once its outcome has been recorded.
 */
final class IdempotencyRecord
{
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_COMPLETED = 'completed';

    /**
     * @param array<string, mixed>|null $outcome
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $key,
        public readonly string $status,
        public readonly ?array $outcome,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
