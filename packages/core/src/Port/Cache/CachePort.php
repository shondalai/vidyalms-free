<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Cache;

/**
 * Read-through caching for course structure and progress summaries.
 * Invalidated by domain events, never by time alone in request paths.
 */
interface CachePort
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    /**
     * @template T
     *
     * @param callable(): T $producer
     *
     * @return T
     */
    public function remember(string $key, int $ttlSeconds, callable $producer): mixed;
}
