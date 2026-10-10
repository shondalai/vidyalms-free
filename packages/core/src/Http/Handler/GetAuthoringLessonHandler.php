<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/lessons/{id}
 */
final class GetAuthoringLessonHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetAuthoringLesson $getAuthoringLesson,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->curriculum->authoringLesson(
            $this->getAuthoringLesson->execute($request->principal, $request->intPathParam('id')),
        ));
    }
}
