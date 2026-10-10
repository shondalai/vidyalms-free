<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Availability;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityCatalogue;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/availability/conditions
 */
final class ListAvailabilityConditionsHandler implements HandlerInterface
{
    public function __construct(private readonly AvailabilityCatalogue $catalogue)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $surface = $request->queryString('surface');

        return ApiResponse::ok($this->catalogue->execute(
            $request->principal,
            $surface === '' ? null : $surface,
        ));
    }
}
