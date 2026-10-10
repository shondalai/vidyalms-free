<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ChangeCourseLifecycle;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

final class ChangeCourseLifecycleHandler implements HandlerInterface
{
    public function __construct(
        private readonly ChangeCourseLifecycle $lifecycle,
        private readonly IdempotentExecution $idempotent,
        private readonly string $action,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return $this->idempotent->run($request, 'manage.courses.' . $this->action, function () use ($request): ApiResponse {
            $this->lifecycle->execute($request->principal, $request->intPathParam('id'), $this->action);

            return new ApiResponse(204, null);
        });
    }
}
