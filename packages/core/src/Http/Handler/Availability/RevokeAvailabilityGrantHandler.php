<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Availability;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\ManageAvailabilityGrants;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * DELETE /manage/availability/grants/{id}
 *
 * The learner's outcome returns to whatever the tree says, which for a
 * revoked open-ended denial also returns the item to their completion
 * denominator.
 */
final class RevokeAvailabilityGrantHandler implements HandlerInterface
{
    public function __construct(
        private readonly ManageAvailabilityGrants $grants,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return $this->idempotent->run(
            $request,
            'availability.grant.revoke',
            function () use ($request): ApiResponse {
                $this->grants->revoke($request->principal, $request->intPathParam('id'));

                return ApiResponse::noContent();
            },
        );
    }
}
