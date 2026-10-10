<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PATCH /manage/modules/{id}
 */
final class UpdateModuleHandler implements HandlerInterface
{
    private const ROUTE = 'manage.modules.update';

    public function __construct(
        private readonly UpdateModule $updateModule,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $moduleId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $moduleId): ApiResponse {
            return ApiResponse::ok($this->presenters->curriculum->moduleRecord(
                $this->updateModule->execute($request->principal, $moduleId, $request->body),
            ));
        });
    }
}
