<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\RemoveInstructor;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * DELETE /manage/courses/{id}/instructors/{user_id}
 */
final class RemoveInstructorHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.instructors.remove';

    public function __construct(
        private readonly RemoveInstructor $removeInstructor,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $userId = $request->intPathParam('user_id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $userId): ApiResponse {
            $this->removeInstructor->execute($request->principal, $courseId, $userId);

            return ApiResponse::noContent();
        });
    }
}
