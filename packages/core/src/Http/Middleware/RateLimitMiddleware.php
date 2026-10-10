<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * A fixed-window counter over the routes that answer without a session.
 *
 * It is core middleware rather than a per-adapter guard for the reason the
 * coding standards already give: a policy guard written in an adapter covers
 * only the routes that adapter happens to own, and these are exactly the
 * routes an attacker reaches without one.
 *
 * The verification page, the credential routes and the unsubscribe pair are
 * the ones that need it. A verification code is short by design so a person
 * can read it off a certificate, and an unsubscribe token is a signed guess
 * away from somebody else's preferences; neither is protected by a session,
 * so the only thing between them and a walk of the key space is this.
 *
 * The window is fixed rather than sliding because the cache port stores a
 * value with a lifetime and nothing else, and a fixed window with a short
 * period is honest about its own burst edge rather than pretending to a
 * precision it cannot keep.
 */
final class RateLimitMiddleware implements Middleware
{
    public const KEY_PREFIX = 'vidyalms.ratelimit.';

    /**
     * How much of a verification code the second dimension buckets on. Four
     * characters over the 32-symbol alphabet is about a millionth of the key
     * space, so a legitimate reader never shares a bucket with a walk and a
     * walk cannot escape one by moving address.
     */
    public const CODE_PREFIX_LENGTH = 4;

    /** @var list<string> */
    private readonly array $prefixes;

    /**
     * @param list<string> $prefixes    path prefixes this applies to
     * @param int          $limit       requests allowed per window
     * @param int          $windowSeconds
     */
    public function __construct(
        private readonly CachePort $cache,
        private readonly ClockPort $clock,
        array $prefixes,
        private readonly int $limit = 30,
        private readonly int $windowSeconds = 60,
        /**
         * The prefixes that fail **open** for a guest the host could not
         * place, rather than closed.
         *
         * A refused unsubscribe is the worse failure of the two: it is a
         * legal obligation, a bulk receiver expects it honoured within 48
         * hours, and denying it costs an attacker one loop. So a host that
         * supplies no client address does not get a shared counter on that
         * surface at all; every other unauthenticated surface still does.
         *
         * @var list<string>
         */
        private readonly array $failOpenPrefixes = ['/notifications/unsubscribe'],
        /**
         * The ceiling on one code namespace, across every address. It is
         * higher than the per-address one because a busy issuer's codes
         * really do share a prefix and a cohort really does verify at once;
         * what it stops is an unbounded walk of one namespace from a
         * thousand addresses, which the address counter cannot see.
         */
        private readonly int $codePrefixLimit = 300,
    ) {
        $this->prefixes = $prefixes;
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        if (!$this->covers($request->path)) {
            return $next($request);
        }

        if ($this->failsOpen($request)) {
            return $next($request);
        }

        $now = $this->clock->now()->getTimestamp();
        $window = intdiv($now, max(1, $this->windowSeconds));

        // Two dimensions, and either one trips the refusal. The address
        // dimension bounds one caller; the code-prefix dimension bounds a
        // walk of one code namespace spread across many addresses, which the
        // address counter cannot see at all. The design names both and only
        // the first existed.
        foreach ($this->bucketsOf($request) as $bucket => $limit) {
            $key = self::KEY_PREFIX . $window . '.' . $bucket;
            $seen = $this->cache->get($key);
            $count = \is_int($seen) ? $seen : 0;

            if ($count >= $limit) {
                $retryAfter = (($window + 1) * $this->windowSeconds) - $now;

                throw new ApiProblem(
                    429,
                    'Too many requests',
                    'Try again shortly.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    ['retry_after' => max(1, $retryAfter)],
                );
            }

            // Best effort, and stated as such: the cache port stores a value
            // with a lifetime and offers no atomic increment, so concurrent
            // requests inside one window under-count. A ceiling that is
            // approximately right beats a second counter nobody can back.
            $this->cache->set($key, $count + 1, $this->windowSeconds);
        }

        return $next($request);
    }

    /**
     * Every counter this request touches, with the ceiling each carries.
     *
     * @return array<string, int>
     */
    private function bucketsOf(ApiRequest $request): array
    {
        $buckets = [$this->bucketOf($request) => $this->limit];
        $codePrefix = $this->codePrefixOf($request);

        if ($codePrefix !== null) {
            $buckets['code.' . hash('sha256', $codePrefix)] = $this->codePrefixLimit;
        }

        return $buckets;
    }

    /**
     * The credential family and the leading characters of the normalised
     * verification code this request is about, or null when the path names
     * none.
     *
     * The shape is matched rather than the family name, so any family that
     * registers a /{family}/{code}/verify route gets the second dimension
     * with no edit here. Naming one family was a closed list in the middle of
     * a security control: badge codes come from the same alphabet through the
     * same generator, so the badge namespace was bounded by address alone
     * while the certificate one was not.
     *
     * The family stays in the bucket key so two families never share a
     * counter. The code alphabet is the unambiguous 32-symbol one, and lookup
     * accepts separators, so the prefix is taken after the same normalisation
     * the lookup applies: a walk that inserts hyphens must not buy a fresh
     * counter.
     */
    private function codePrefixOf(ApiRequest $request): ?string
    {
        if (!preg_match('#/([A-Za-z0-9_-]+)/([^/]+)/verify$#', $request->path, $matches)) {
            return null;
        }

        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', rawurldecode($matches[2])) ?? '');

        return $code === '' ? null : $matches[1] . '|' . substr($code, 0, self::CODE_PREFIX_LENGTH);
    }

    /**
     * The bucket an authenticated caller falls into is their own; a guest
     * falls into one keyed by the address the adapter validated, and by the
     * path prefix, so the two guest surfaces stay separate.
     *
     * One shared guest bucket per prefix was a denial of service with a loop
     * in it: thirty requests on any unsubscribe token refused every other
     * guest's unsubscribe for the rest of the window, and that is the one
     * endpoint the law requires to work and a bulk receiver requires to be
     * honoured within 48 hours. The core still never reads an address from a
     * header, because an address the adapter did not validate is worse than
     * counting coarsely; a host that supplies none keeps the old coarse
     * bucket, and the caller decides whether that surface fails open.
     */
    private function bucketOf(ApiRequest $request): string
    {
        if (!$request->principal->isGuest()) {
            return 'u' . $request->principal->userId;
        }

        $address = $request->clientAddress;
        $prefix = $this->prefixOf($request->path);

        return $address === null || trim($address) === ''
            ? 'anon.' . hash('sha256', $prefix)
            : 'anon.' . hash('sha256', $prefix . '|' . trim($address));
    }

    /**
     * Whether this request is on a surface that must not be refused for want
     * of an address the host could not give.
     */
    private function failsOpen(ApiRequest $request): bool
    {
        if (!$request->principal->isGuest()) {
            return false;
        }

        $address = $request->clientAddress;

        if ($address !== null && trim($address) !== '') {
            return false;
        }

        foreach ($this->failOpenPrefixes as $prefix) {
            if (str_starts_with($request->path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function prefixOf(string $path): string
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $prefix;
            }
        }

        return $path;
    }

    private function covers(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
