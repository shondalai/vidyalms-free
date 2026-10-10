<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Progress\GetLearnerCourseProgress;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/learners/{user_id}
 */
final class GetLearnerCourseProgressHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetLearnerCourseProgress $getLearnerCourseProgress,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->platform->learnerCourseProgress(
            $this->getLearnerCourseProgress->execute(
                $request->principal,
                $request->intPathParam('id'),
                $request->intPathParam('user_id'),
            ),
        ));
    }
}
