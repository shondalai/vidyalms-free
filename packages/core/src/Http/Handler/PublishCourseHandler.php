<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetManagedCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/publish
 */
final class PublishCourseHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.publish';

    public function __construct(
        private readonly PublishCourse $publishCourse,
        private readonly GetManagedCourse $getManagedCourse,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId): ApiResponse {
            $this->publishCourse->execute($request->principal, $courseId);

            return ApiResponse::ok(
                $this->presenters->curriculum->managedCourseDetail($this->getManagedCourse->reload($courseId)),
            );
        });
    }
}
