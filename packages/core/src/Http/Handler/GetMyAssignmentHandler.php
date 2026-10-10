<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assignments\GetMyAssignment;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

final class GetMyAssignmentHandler implements HandlerInterface
{
    public function __construct(private readonly GetMyAssignment $assignment)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->assignment->execute($request->principal, $request->intPathParam('id')));
    }
}
