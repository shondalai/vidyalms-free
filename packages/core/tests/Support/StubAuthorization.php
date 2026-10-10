<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;

/**
 * Grant-list authorization. Construct with allowAll for admin-like tests,
 * or grant specific (user, capability) pairs, optionally scoped to one
 * resource reference.
 */
final class StubAuthorization implements AuthorizationPort
{
    /** @var array<string, true> */
    private array $grants = [];

    public function __construct(
        private readonly bool $allowAll = false,
    ) {
    }

    public function grant(int $userId, string $capability, ?string $resource = null): void
    {
        $this->grants[$this->keyFor($userId, $capability, $resource)] = true;
    }

    public function can(int $userId, string $capability, ?string $resource = null): bool
    {
        if ($this->allowAll) {
            return true;
        }

        if (isset($this->grants[$this->keyFor($userId, $capability, null)])) {
            return true;
        }

        return $resource !== null && isset($this->grants[$this->keyFor($userId, $capability, $resource)]);
    }

    public function revoke(int $userId, string $capability, ?string $resource = null): void
    {
        unset($this->grants[$this->keyFor($userId, $capability, $resource)]);
    }

    private function keyFor(int $userId, string $capability, ?string $resource): string
    {
        return $userId . '|' . $capability . '|' . ($resource ?? '*');
    }
}
