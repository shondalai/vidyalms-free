<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Route;

final class RouteTest extends TestCase
{
    private function handler(): HandlerInterface
    {
        return new class implements HandlerInterface {
            public function handle(ApiRequest $request): ApiResponse
            {
                return ApiResponse::ok([]);
            }
        };
    }

    public function testMatchesLiteralAndParameterSegments(): void
    {
        $route = new Route('GET', '/courses/{id}/curriculum', $this->handler());

        self::assertSame(['id' => '42'], $route->match('GET', '/courses/42/curriculum'));
        self::assertNull($route->match('POST', '/courses/42/curriculum'));
        self::assertNull($route->match('GET', '/courses/42'));
        self::assertNull($route->match('GET', '/courses/42/reviews'));
    }

    public function testDecodesPathParameters(): void
    {
        $route = new Route('GET', '/courses/{slug}', $this->handler());

        self::assertSame(['slug' => 'my course'], $route->match('GET', '/courses/my%20course'));
    }

    public function testMatchesRootPattern(): void
    {
        $route = new Route('GET', '/courses', $this->handler());

        self::assertSame([], $route->match('GET', 'courses'));
        self::assertSame([], $route->match('get', '/courses/'));
    }
}
