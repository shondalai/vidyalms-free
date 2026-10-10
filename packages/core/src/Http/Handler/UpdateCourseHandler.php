<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetManagedCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PATCH /manage/courses/{id}
 *
 * The body is handed to the use case as it arrived: which fields may change
 * and what each one accepts is UpdateCourse's rule, and a field this route
 * refused first would be a rule in two places.
 */
final class UpdateCourseHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.update';

    public function __construct(
        private readonly UpdateCourse $updateCourse,
        private readonly GetManagedCourse $getManagedCourse,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId): ApiResponse {
            $this->updateCourse->execute($request->principal, $courseId, $request->body);

            return ApiResponse::ok(
                $this->presenters->curriculum->managedCourseDetail($this->getManagedCourse->reload($courseId)),
            );
        });
    }
}
