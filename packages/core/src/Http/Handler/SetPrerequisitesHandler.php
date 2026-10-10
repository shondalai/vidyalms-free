<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\SetPrerequisites;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PUT /manage/courses/{id}/prerequisites
 */
final class SetPrerequisitesHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.prerequisites';

    public function __construct(
        private readonly SetPrerequisites $setPrerequisites,
        private readonly CourseRepository $courses,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $ids = Body::intList($request->body, 'course_ids');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $ids): ApiResponse {
            $this->setPrerequisites->execute($request->principal, $courseId, $ids);

            return ApiResponse::ok([
                'items' => array_map(
                    $this->presenters->shared->courseRef(...),
                    $this->courses->byIds($this->courses->prerequisiteIds($courseId)),
                ),
            ]);
        });
    }
}
