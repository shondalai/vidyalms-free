<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/** @internal */
final class AuditTrail implements Middleware
{
    /** @var list<string> */
    public array $seen = [];

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $this->seen[] = $request->method . ' ' . $request->path;

        return $next($request);
    }
}
