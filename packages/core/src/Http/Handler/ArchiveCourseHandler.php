<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetManagedCourse;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/archive
 */
final class ArchiveCourseHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.archive';

    public function __construct(
        private readonly ArchiveCourse $archiveCourse,
        private readonly GetManagedCourse $getManagedCourse,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId): ApiResponse {
            $this->archiveCourse->execute($request->principal, $courseId);

            return ApiResponse::ok(
                $this->presenters->curriculum->managedCourseDetail($this->getManagedCourse->reload($courseId)),
            );
        });
    }
}
