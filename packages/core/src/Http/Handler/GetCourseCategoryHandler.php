<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCourseCategory;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/** GET /manage/course-categories/{id} */
final class GetCourseCategoryHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetCourseCategory $category,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->curriculum->courseCategory(
            $this->category->execute($request->principal, $request->intPathParam('id')),
        ));
    }
}
