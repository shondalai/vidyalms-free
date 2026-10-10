<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ListCourseCategories;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/** GET /manage/course-categories */
final class ListCourseCategoriesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListCourseCategories $categories,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $page = $this->categories->execute(
            $request->principal,
            $request->queryString('q'),
            $request->queryString('sort', 'name'),
            $request->queryString('order', 'asc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return ApiResponse::ok([
            'items' => array_map($this->presenters->curriculum->courseCategory(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
            'management_url' => $page->managementUrl,
        ]);
    }
}
