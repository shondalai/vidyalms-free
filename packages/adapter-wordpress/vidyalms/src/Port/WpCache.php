<?php

declare(strict_types=1);

/**
 * VidyaLMS: the cache port over the object cache, transients as the fallback.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;

defined('ABSPATH') || exit;

/**
 * Read-through caching for course structure and progress summaries.
 *
 * With a persistent object cache (Redis, Memcached) the wp_cache_* group
 * "vidyalms" is used, which is the fast path and is flushed with the rest of
 * the site cache. Without one, WordPress's default object cache lives for a
 * single request and would cache nothing across page loads, so transients are
 * used instead, which persist in the options table.
 *
 * Every entry is stored inside a one-key envelope. Both stores answer a miss
 * with boolean false, which is also a legitimate cached value; the envelope's
 * presence, not the value, is what distinguishes a hit from a miss.
 */
final class WpCache implements CachePort
{
    public const GROUP = 'vidyalms';

    private const ENVELOPE = 'value';

    /**
     * Transient names are option names, capped at 172 characters, so the key is
     * hashed rather than passed through.
     */
    private const TRANSIENT_PREFIX = 'vidyalms_';

    // Rate counters share a bounded, secret-hashed set of slots. Collisions
    // share a stricter limit; they never discard a count within the window.
    private const RATE_SLOTS = 4096;

    public function get(string $key): mixed
    {
        $envelope = $this->envelope($key);

        return $envelope === null ? null : $envelope[self::ENVELOPE];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $ttl = max(1, $ttlSeconds);
        $envelope = [self::ENVELOPE => $value];
        $rate = $this->rateKey($key);
        if ($rate !== null) {
            $envelope['window'] = $rate[1];
        }

        if ($this->usingObjectCache()) {
            wp_cache_set($this->id($key), $envelope, self::GROUP, $ttl);

            return;
        }

        set_transient($this->transient($key), $envelope, $ttl);
    }

    public function delete(string $key): void
    {
        if ($this->usingObjectCache()) {
            wp_cache_delete($this->id($key), self::GROUP);

            return;
        }

        delete_transient($this->transient($key));
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $envelope = $this->envelope($key);

        if ($envelope !== null) {
            return $envelope[self::ENVELOPE];
        }

        $produced = $producer();
        $this->set($key, $produced, $ttlSeconds);

        return $produced;
    }

    /**
     * @return array{value: mixed}|null
     */
    private function envelope(string $key): ?array
    {
        if ($this->usingObjectCache()) {
            $found = false;
            /** @var mixed $stored */
            $stored = wp_cache_get($this->id($key), self::GROUP, false, $found);

            if ($found !== true) {
                return null;
            }
        } else {
            /** @var mixed $stored */
            $stored = get_transient($this->transient($key));
        }

        if (!\is_array($stored) || !\array_key_exists(self::ENVELOPE, $stored)) {
            return null;
        }

        $rate = $this->rateKey($key);
        if ($rate !== null && ($stored['window'] ?? null) !== $rate[1]) {
            return null;
        }

        /** @var array{value: mixed} $stored */
        return $stored;
    }

    /**
     * Asked per call, not once: an object cache drop-in can be added to a site
     * between two requests and the answer has to follow it.
     *
     * The comparison is not decoration. wp_using_ext_object_cache() returns the
     * global $_wp_using_ext_object_cache, which wp_start_object_cache() sets
     * and which is null until it has run, so the function is documented as
     * boolean and genuinely answers null early in a bootstrap. Returning it
     * straight from a bool-typed method is a TypeError, and it was reachable:
     * a null answer is exactly what a CLI or early hook gets, which is where
     * the core rate limiter first asked this port anything at all. A null
     * means no drop-in has declared itself, so it reads as false and the
     * transient path is used, which is the correct answer as well as a total
     * one.
     */
    private function usingObjectCache(): bool
    {
        return wp_using_ext_object_cache() === true;
    }

    private function id(string $key): string
    {
        return md5($key);
    }

    private function transient(string $key): string
    {
        $rate = $this->rateKey($key);
        if ($rate !== null) {
            return self::TRANSIENT_PREFIX . 'rate_' . $rate[0];
        }
        return self::TRANSIENT_PREFIX . md5($key);
    }

    /** @return array{int, string}|null */
    private function rateKey(string $key): ?array
    {
        if (!preg_match('/\Avidyalms\.ratelimit\.(\d+)\.(.+)\z/D', $key, $match)) {
            return null;
        }
        $hash = hash_hmac('sha256', $match[2], wp_salt('auth'));
        return [hexdec(substr($hash, 0, 6)) % self::RATE_SLOTS, $match[1]];
    }
}
