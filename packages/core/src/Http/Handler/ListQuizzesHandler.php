<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\ListQuizzesForCourse;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/quizzes
 */
final class ListQuizzesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListQuizzesForCourse $listQuizzes,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok([
            'items' => array_map(
                $this->presenters->assessment->quizSummary(...),
                $this->listQuizzes->execute($request->principal, $request->intPathParam('id')),
            ),
        ]);
    }
}
