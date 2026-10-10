<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware\RateLimitMiddleware;
use Shondalai\VidyaLMS\Core\Tests\Support\FrozenClock;
use Shondalai\VidyaLMS\Core\Tests\Support\InMemoryCache;

/**
 * The verification page's two rate-limit dimensions.
 *
 * The privacy rule names both, by IP and by code prefix, and only the address
 * one existed: a walk of one code namespace spread across a thousand
 * addresses was unbounded, which is precisely the shape of attack a
 * twelve-character public code invites.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    public function testAWalkOfOneCodeNamespaceIsRefusedEvenFromFreshAddresses(): void
    {
        $middleware = $this->middleware(codePrefixLimit: 5);
        $refused = 0;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            // A fresh address every time, so the address counter never sees
            // the same caller twice and cannot refuse anything.
            $request = $this->verify('ABCD' . str_pad((string) $attempt, 8, '0'), '198.51.100.' . $attempt);

            try {
                $middleware->process($request, static fn (): ApiResponse => ApiResponse::ok([]));
            } catch (ApiProblem $problem) {
                self::assertSame(429, $problem->status);
                ++$refused;
            }
        }

        self::assertGreaterThan(0, $refused, 'a distributed walk of one code prefix was unbounded');
    }

    public function testADifferentCodePrefixBuysItsOwnCounter(): void
    {
        $middleware = $this->middleware(codePrefixLimit: 2);
        $next = static fn (): ApiResponse => ApiResponse::ok([]);

        $middleware->process($this->verify('ABCD00000001', '198.51.100.1'), $next);
        $middleware->process($this->verify('ABCD00000002', '198.51.100.2'), $next);

        // A legitimate reader whose code sits in another namespace is not
        // caught by somebody else's walk.
        $response = $middleware->process($this->verify('ZZZZ00000001', '198.51.100.3'), $next);

        self::assertSame(200, $response->status);
    }

    public function testSeparatorsDoNotBuyAFreshCounter(): void
    {
        $middleware = $this->middleware(codePrefixLimit: 1);
        $next = static fn (): ApiResponse => ApiResponse::ok([]);

        $middleware->process($this->verify('ABCD00000001', '198.51.100.1'), $next);

        // Lookup accepts the code with or without separators, so the prefix
        // is taken after the same normalisation: a walk that inserts hyphens
        // must not escape its own bucket.
        $this->expectException(ApiProblem::class);

        $middleware->process($this->verify('abcd-0000-0002', '198.51.100.2'), $next);
    }

    public function testARouteOutsideTheCoveredPrefixesIsUntouched(): void
    {
        $middleware = $this->middleware(codePrefixLimit: 1);
        $next = static fn (): ApiResponse => ApiResponse::ok([]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $request = new ApiRequest(
                'GET',
                '/courses',
                Principal::guest(),
                clientAddress: '198.51.100.1',
            );

            self::assertSame(200, $middleware->process($request, $next)->status);
        }
    }

    public function testAWalkOfABadgeCodeNamespaceIsBoundedByTheSameDimension(): void
    {
        // The second dimension named one family, so the badge code namespace,
        // which comes from the same alphabet through the same generator, was
        // bounded by address alone. A third family registering its own
        // verification route should not need an edit inside a security
        // control to get the counter.
        $middleware = new RateLimitMiddleware(
            new InMemoryCache(),
            new FrozenClock('2026-05-05 12:00:00'),
            ['/badges'],
            1000,
            60,
            [],
            5,
        );

        $refused = 0;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $code = 'ABCD' . str_pad((string) $attempt, 8, '0');
            $request = new ApiRequest(
                'GET',
                '/badges/' . $code . '/verify',
                Principal::guest(),
                pathParams: ['code' => $code],
                clientAddress: '198.51.100.' . $attempt,
            );

            try {
                $middleware->process($request, static fn (): ApiResponse => ApiResponse::ok([]));
            } catch (ApiProblem $problem) {
                self::assertSame(429, $problem->status);
                ++$refused;
            }
        }

        self::assertGreaterThan(0, $refused, 'a distributed walk of one badge code prefix was unbounded');
    }

    public function testTwoFamiliesSharingACodePrefixDoNotShareACounter(): void
    {
        $cache = new InMemoryCache();
        $clock = new FrozenClock('2026-05-05 12:00:00');
        $next = static fn (): ApiResponse => ApiResponse::ok([]);

        $certificates = new RateLimitMiddleware($cache, $clock, ['/certificates'], 1000, 60, [], 1);
        $badges = new RateLimitMiddleware($cache, $clock, ['/badges'], 1000, 60, [], 1);

        $certificates->process($this->verify('ABCD00000001', '198.51.100.1'), $next);

        $code = 'ABCD00000002';
        $response = $badges->process(new ApiRequest(
            'GET',
            '/badges/' . $code . '/verify',
            Principal::guest(),
            pathParams: ['code' => $code],
            clientAddress: '198.51.100.2',
        ), $next);

        self::assertSame(200, $response->status);
    }

    private function middleware(int $codePrefixLimit): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            new InMemoryCache(),
            new FrozenClock('2026-05-05 12:00:00'),
            ['/certificates'],
            1000,
            60,
            [],
            $codePrefixLimit,
        );
    }

    private function verify(string $code, string $address): ApiRequest
    {
        return new ApiRequest(
            'GET',
            '/certificates/' . $code . '/verify',
            Principal::guest(),
            pathParams: ['code' => $code],
            clientAddress: $address,
        );
    }
}
