<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;

final class InMemoryCache implements CachePort
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        if (!\array_key_exists($key, $this->values)) {
            $this->values[$key] = $producer();
        }

        return $this->values[$key];
    }
}
