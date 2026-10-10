<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /attempts/{id}
 *
 * The learner view. All hiding of correctness and results lives in the use
 * case; this handler only relays its shape.
 */
final class GetAttemptHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetAttemptForLearner $getAttemptForLearner,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(
            $this->getAttemptForLearner->execute($request->principal, $request->intPathParam('id')),
        );
    }
}
