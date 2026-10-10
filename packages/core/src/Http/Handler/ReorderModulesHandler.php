<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderModules;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * PUT /manage/courses/{id}/modules/order
 */
final class ReorderModulesHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.modules.order';

    public function __construct(
        private readonly ReorderModules $reorderModules,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $moduleIds = Body::intList($request->body, 'module_ids');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $moduleIds): ApiResponse {
            $this->reorderModules->execute($request->principal, $courseId, $moduleIds);

            return ApiResponse::noContent();
        });
    }
}
