<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/** @internal */
final class Exploding implements Middleware
{
    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        throw new \RuntimeException('the licence server is down');
    }
}
