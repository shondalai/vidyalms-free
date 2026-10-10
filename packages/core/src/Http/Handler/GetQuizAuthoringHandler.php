<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizAuthoring;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/quizzes/{id}
 */
final class GetQuizAuthoringHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetQuizAuthoring $getQuizAuthoring,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->assessment->quizAuthoring(
            $this->getQuizAuthoring->execute($request->principal, $request->intPathParam('id')),
        ));
    }
}
