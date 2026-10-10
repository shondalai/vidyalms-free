<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * Persistence for idempotency keys. The unique index over (scope, idem_key)
 * is the arbiter of who claimed first.
 */
interface IdempotencyKeyRepository
{
    /**
     * Inserts a claimed row and returns true, or false when a row for the
     * (scope, key) pair already exists.
     */
    public function insertClaim(string $scope, string $key, \DateTimeImmutable $expiresAt): bool;

    public function find(string $scope, string $key): ?IdempotencyRecord;

    /**
     * Takes over an expired claim: resets the outcome and extends the expiry.
     */
    public function reclaim(string $scope, string $key, \DateTimeImmutable $expiresAt, \DateTimeImmutable $previousExpiry): bool;

    /**
     * @param array<string, mixed> $outcome
     */
    public function storeOutcome(string $scope, string $key, array $outcome, \DateTimeImmutable $expiresAt, \DateTimeImmutable $claimExpiry): bool;

    /**
     * Deletes the row only while it is still claimed; completed outcomes stay.
     */
    public function releaseClaim(string $scope, string $key, \DateTimeImmutable $claimExpiry): void;
}
