<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Pro;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/** @internal */
final class SeatsHandler implements HandlerInterface
{
    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(['seats' => 12]);
    }
}
