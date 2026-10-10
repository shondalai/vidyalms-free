<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/** @internal */
final class Recording implements Middleware
{
    /** A counter shared by every instance, so entry order is observable. */
    private static int $tick = 0;

    /** @var list<string> */
    public array $order = [];

    public int $startedAt = 0;

    public function __construct(private readonly string $name)
    {
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $this->startedAt = ++self::$tick;
        $this->order[] = $this->name . ' in';
        $response = $next($request);
        $this->order[] = $this->name . ' out';

        return $response;
    }
}
