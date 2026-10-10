<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderLessons;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * PUT /manage/modules/{id}/lessons/order
 */
final class ReorderLessonsHandler implements HandlerInterface
{
    private const ROUTE = 'manage.modules.lessons.order';

    public function __construct(
        private readonly ReorderLessons $reorderLessons,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $moduleId = $request->intPathParam('id');
        $lessonIds = Body::intList($request->body, 'lesson_ids');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $moduleId, $lessonIds): ApiResponse {
            $this->reorderLessons->execute($request->principal, $moduleId, $lessonIds);

            return ApiResponse::noContent();
        });
    }
}
