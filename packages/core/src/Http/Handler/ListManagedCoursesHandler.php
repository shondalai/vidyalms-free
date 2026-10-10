<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ListManagedCourses;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses
 */
final class ListManagedCoursesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListManagedCourses $listManagedCourses,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $state = $request->queryString('state');
        $search = $request->queryString('q');

        $page = $this->listManagedCourses->execute(
            $request->principal,
            $state === '' ? null : $state,
            $search === '' ? null : $search,
            $request->queryString('sort', 'title'),
            $request->queryString('order', 'asc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
            $request->queryString('category_id') === '' ? null : $request->queryInt('category_id', 0),
        );

        return ApiResponse::ok([
            'items' => array_map($this->presenters->curriculum->managedCourse(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
