<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\IdempotencyStore;
use Shondalai\VidyaLMS\Core\Domain\Platform\IdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\IdempotencyRecord;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Replay protection backed by the idempotency_keys table.
 *
 * A row is in exactly one of three states and the two windows are what move
 * it between them. **Claimed** is an execution in flight: a second caller of
 * the same (scope, key) is refused with a conflict until the claim expiry
 * passes, after which the key is reclaimed and re-executed, which is what
 * stops a crashed request from owning a key forever. **Completed** is an
 * answer: every replay inside the outcome window returns the stored outcome
 * byte for byte and executes nothing. **Absent** is a released claim, which a
 * failed execution leaves behind so the client may retry immediately; a
 * completed outcome is never released.
 *
 * Both windows are per route family, so interactive learning requests and
 * longer-running administrative operations can use appropriate replay windows.
 * A family that names no TTL gets the shipped defaults.
 *
 * **The maps are keyed on the route family, not on the stored scope.** A
 * scope is `routeName:principalId`, minted per request by IdempotentExecution,
 * so a map keyed by scope could name no route a caller ever reaches: it would
 * have to name one principal at a time. A key here matches a route name
 * exactly, or matches it as a dotted prefix, with the longest match winning.
 * The host supplies them through the CoreKernel options
 * idempotency_claim_ttls and idempotency_outcome_ttls; without that they are
 * unreachable configuration, which is what they were.
 */
final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public const DEFAULT_CLAIM_TTL = 'PT1H';
    public const DEFAULT_OUTCOME_TTL = 'PT24H';

    /** @var array<string, \DateTimeImmutable> Claims owned by this execution, never by a replay. */
    private array $claims = [];

    /**
     * @param array<string, string> $claimTtls   ISO-8601 durations keyed by route family
     * @param array<string, string> $outcomeTtls ISO-8601 durations keyed by route family
     */
    public function __construct(
        private readonly IdempotencyKeyRepository $keys,
        private readonly ClockPort $clock,
        private readonly array $claimTtls = [],
        private readonly array $outcomeTtls = [],
    ) {
        // Reject invalid configuration before processing a learning request.
        foreach ([$claimTtls, $outcomeTtls] as $windows) {
            foreach ($windows as $scope => $ttl) {
                self::interval($ttl, $scope);
            }
        }
    }

    public function claimOrReplay(string $scope, string $key): ?array
    {
        $now = $this->clock->now();
        $claimExpiry = $now->add(self::interval(self::window($this->claimTtls, $scope, self::DEFAULT_CLAIM_TTL), $scope));

        if ($this->keys->insertClaim($scope, $key, $claimExpiry)) {
            $this->claims[$scope . '\0' . $key] = $claimExpiry;
            return null;
        }

        $existing = $this->keys->find($scope, $key);

        if ($existing === null) {
            throw new ConflictException('A request with this idempotency key is already in flight.');
        }

        if ($existing->status === IdempotencyRecord::STATUS_COMPLETED && $existing->expiresAt > $now) {
            return $existing->outcome ?? [];
        }

        if ($existing->expiresAt > $now) {
            throw new ConflictException('A request with this idempotency key is already in flight.');
        }

        if (!$this->keys->reclaim($scope, $key, $claimExpiry, $existing->expiresAt)) {
            throw new ConflictException('A request with this idempotency key is already in flight.');
        }

        $this->claims[$scope . '\0' . $key] = $claimExpiry;
        return null;
    }

    public function complete(string $scope, string $key, array $outcome): void
    {
        $expiry = $this->clock->now()->add(
            self::interval(self::window($this->outcomeTtls, $scope, self::DEFAULT_OUTCOME_TTL), $scope),
        );

        $identity = $scope . '\0' . $key;
        $claim = $this->claims[$identity] ?? null;
        if ($claim === null || !$this->keys->storeOutcome($scope, $key, $outcome, $expiry, $claim)) {
            throw new ConflictException('This request no longer owns its idempotency claim.');
        }
        unset($this->claims[$identity]);
    }

    public function release(string $scope, string $key): void
    {
        $identity = $scope . '\0' . $key;
        if (isset($this->claims[$identity])) {
            $this->keys->releaseClaim($scope, $key, $this->claims[$identity]);
            unset($this->claims[$identity]);
        }
    }

    /**
     * The window a scope falls under: an exact route name, else the longest
     * dotted prefix of it, else the shipped default.
     *
     * @param array<string, string> $windows
     */
    private static function window(array $windows, string $scope, string $default): string
    {
        if ($windows === []) {
            return $default;
        }

        // IdempotentExecution mints "routeName:principalId"; the principal is
        // never part of the configured family.
        $separator = strrpos($scope, ':');
        $route = $separator === false ? $scope : substr($scope, 0, $separator);
        $best = null;

        foreach ($windows as $family => $ttl) {
            if ($route !== $family && !str_starts_with($route, $family . '.')) {
                continue;
            }

            if ($best === null || \strlen($family) > \strlen($best)) {
                $best = $family;
            }
        }

        return $best === null ? $default : $windows[$best];
    }

    private static function interval(string $ttl, string $scope): \DateInterval
    {
        try {
            $interval = new \DateInterval($ttl);
            $reference = new \DateTimeImmutable('2026-01-01T00:00:00Z');
            if ($reference->add($interval)->getTimestamp() <= $reference->getTimestamp()) {
                throw new \InvalidArgumentException('An idempotency window must be at least one second.');
            }
            return $interval;
        } catch (\Exception $failure) {
            throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('The idempotency window for scope "%s" is not an ISO-8601 duration: "%s".', $scope, $ttl),
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $failure,
            );
        }
    }
}
