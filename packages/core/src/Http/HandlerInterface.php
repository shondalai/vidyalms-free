<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * One endpoint of the /v1 contract. Handlers translate between HTTP shapes
 * and use cases; they hold no rules of their own, and no authorization
 * beyond what the use case already enforces.
 */
interface HandlerInterface
{
    public function handle(ApiRequest $request): ApiResponse;
}
