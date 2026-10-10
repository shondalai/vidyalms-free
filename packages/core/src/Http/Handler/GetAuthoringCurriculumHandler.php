<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringCurriculum;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/curriculum
 */
final class GetAuthoringCurriculumHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetAuthoringCurriculum $getAuthoringCurriculum,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        return ApiResponse::ok($this->presenters->curriculum->authoringCurriculum(
            $courseId,
            $this->getAuthoringCurriculum->execute($request->principal, $courseId),
        ));
    }
}
