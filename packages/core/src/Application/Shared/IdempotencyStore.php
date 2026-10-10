<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

/**
 * Replay protection for mutating endpoints. A client supplies an idempotency
 * key; the first execution stores its outcome under (scope, key) and any
 * replay within the retention window returns the stored outcome instead of
 * executing again. A flaky mobile connection cannot double-enrol or
 * double-submit.
 */
interface IdempotencyStore
{
    /**
     * Returns the stored outcome for a replayed key, or null when the key is
     * unseen and has been claimed for this execution.
     *
     * @return array<string, mixed>|null
     */
    public function claimOrReplay(string $scope, string $key): ?array;

    /**
     * Records the successful outcome for a claimed key.
     *
     * @param array<string, mixed> $outcome
     */
    public function complete(string $scope, string $key, array $outcome): void;

    /**
     * Releases a claimed key after a failed execution so the client may retry.
     */
    public function release(string $scope, string $key): void;
}
